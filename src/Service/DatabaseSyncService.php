<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Service;

use Symfony\Component\Process\Process;

class DatabaseSyncService
{
    public function isDdev(string $projectRoot): bool
    {
        return is_dir($projectRoot . '/.ddev');
    }

    /**
     * Returns true when running inside a ddev web container.
     * In that case ddev CLI commands are unavailable — use direct DB connections instead.
     */
    public function isInsideDdevContainer(): bool
    {
        return isset($_SERVER['IS_DDEV_PROJECT']) || isset($_ENV['IS_DDEV_PROJECT']);
    }

    /**
     * Reads DATABASE_URL from .env (not .env.local) for use inside the ddev web container.
     * .env.local contains host-side credentials which are not valid inside the container.
     * Falls back to ddev's standard container defaults.
     */
    public function readDdevContainerDatabaseUrl(string $projectRoot): string
    {
        $path = $projectRoot . '/.env';
        if (is_file($path)) {
            $content = (string) file_get_contents($path);
            if (preg_match('/^DATABASE_URL\s*=\s*"?([^"\n]+)"?/m', $content, $m)) {
                return trim($m[1]);
            }
        }

        return 'mysql://db:db@db:3306/db';
    }

    /**
     * Dumps the local database to a temp file and returns the path.
     * Caller is responsible for deleting the file afterwards.
     */
    public function dumpLocal(string $projectRoot): string
    {
        $tmpFile = sys_get_temp_dir() . '/revolte_deploy_' . time() . '.sql';

        if ($this->isDdev($projectRoot) && !$this->isInsideDdevContainer()) {
            $process = new Process(
                ['ddev', 'export-db', '--gzip=false', '--file=' . $tmpFile],
                cwd: $projectRoot,
                timeout: 120,
            );
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException('DDEV DB-Dump fehlgeschlagen: ' . ($process->getErrorOutput() ?: $process->getOutput()));
            }
        } else {
            $url = $this->isInsideDdevContainer()
                ? $this->readDdevContainerDatabaseUrl($projectRoot)
                : $this->readLocalDatabaseUrl($projectRoot);
            $db = $this->parseDatabaseUrl($url);

            $cmd = ['mysqldump', '-h', $db['host'], '-P', (string) $db['port'], '-u', $db['user']];
            if ($db['password'] !== '') {
                $cmd[] = '--password=' . $db['password'];
            }
            $cmd[] = $db['name'];

            $process = new Process($cmd, timeout: 120);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException('mysqldump fehlgeschlagen: ' . $process->getErrorOutput());
            }

            file_put_contents($tmpFile, $process->getOutput());
        }

        return $tmpFile;
    }

    /**
     * Reads the current values of preserved fields from the remote DB before import.
     * Returns a structure suitable for restorePreservedFields().
     *
     * @param array<string, list<string>> $preserveFields e.g. ['tl_page' => ['dns', 'useSSL']]
     * @return array<string, list<array<string, mixed>>>
     */
    public function readPreservedFields(RemoteCommandRunner $remote, string $sshProfile, array $db, array $preserveFields): array
    {
        $saved = [];

        foreach ($preserveFields as $table => $fields) {
            $fieldList = 'id, ' . implode(', ', array_map(fn ($f) => '`' . $f . '`', $fields));
            $query = sprintf('SELECT %s FROM `%s`', $fieldList, $table);

            try {
                $output = $this->runRemoteMysqlQuery($remote, $sshProfile, $db, $query);
            } catch (\RuntimeException $e) {
                // Table doesn't exist yet (fresh install) — nothing to preserve
                if (str_contains($e->getMessage(), '1146') || str_contains($e->getMessage(), "doesn't exist")) {
                    $saved[$table] = [];
                    continue;
                }
                throw $e;
            }
            $rows = [];

            foreach (explode("\n", trim($output)) as $i => $line) {
                if ($i === 0 || trim($line) === '') {
                    continue; // skip header row
                }
                $values = explode("\t", $line);
                $row = ['id' => (int) ($values[0] ?? 0)];
                foreach ($fields as $j => $field) {
                    $raw = $values[$j + 1] ?? null;
                    $row[$field] = ($raw === 'NULL') ? null : $raw;
                }
                $rows[] = $row;
            }

            $saved[$table] = $rows;
        }

        return $saved;
    }

    /**
     * Restores preserved field values after a DB import.
     *
     * @param array<string, list<array<string, mixed>>> $saved result from readPreservedFields()
     */
    public function restorePreservedFields(RemoteCommandRunner $remote, string $sshProfile, array $db, array $saved): void
    {
        foreach ($saved as $table => $rows) {
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $sets = [];

                foreach ($row as $field => $value) {
                    if ($field === 'id') {
                        continue;
                    }
                    $sets[] = sprintf(
                        '`%s` = %s',
                        $field,
                        $value === null ? 'NULL' : sprintf("'%s'", addslashes((string) $value)),
                    );
                }

                if ($sets === []) {
                    continue;
                }

                $query = sprintf('UPDATE `%s` SET %s WHERE id = %d', $table, implode(', ', $sets), $id);

                try {
                    $this->runRemoteMysqlQuery($remote, $sshProfile, $db, $query);
                } catch (\RuntimeException $e) {
                    if (str_contains($e->getMessage(), '1048') || str_contains($e->getMessage(), 'cannot be null')) {
                        $fallbackSets = [];
                        foreach ($row as $field => $value) {
                            if ($field === 'id') {
                                continue;
                            }
                            $fallbackSets[] = sprintf(
                                '`%s` = %s',
                                $field,
                                $value === null ? "''" : sprintf("'%s'", addslashes((string) $value)),
                            );
                        }
                        $this->runRemoteMysqlQuery($remote, $sshProfile, $db, sprintf(
                            'UPDATE `%s` SET %s WHERE id = %d',
                            $table,
                            implode(', ', $fallbackSets),
                            $id,
                        ));
                    } else {
                        throw $e;
                    }
                }
            }
        }
    }

    private function runRemoteMysqlQuery(RemoteCommandRunner $remote, string $sshProfile, array $db, string $query): string
    {
        $remoteCmd = sprintf(
            'mysql --password=%s -h %s -P %d -u %s %s -e %s',
            escapeshellarg($db['password']),
            escapeshellarg($db['host']),
            (int) $db['port'],
            escapeshellarg($db['user']),
            escapeshellarg($db['name']),
            escapeshellarg($query),
        );

        $process = new Process(['ssh', '-o', 'BatchMode=yes', $sshProfile, $remoteCmd], timeout: 30);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Remote DB-Query fehlgeschlagen: ' . ($process->getErrorOutput() ?: $process->getOutput()));
        }

        return $process->getOutput();
    }

    /**
     * Imports a SQL dump file into the remote database via SSH stdin pipe.
     * Remote DATABASE_URL is read from .env.local on the server.
     */
    public function importRemote(string $sshProfile, string $remotePath, string $dumpPath, RemoteCommandRunner $remote): void
    {
        $remoteDatabaseUrl = $this->readRemoteDatabaseUrl($remote, $sshProfile, $remotePath);
        $db = $this->parseDatabaseUrl($remoteDatabaseUrl);

        $remoteCmd = sprintf(
            'mysql --password=%s -h %s -P %d -u %s %s',
            escapeshellarg($db['password']),
            escapeshellarg($db['host']),
            (int) $db['port'],
            escapeshellarg($db['user']),
            escapeshellarg($db['name']),
        );

        $process = new Process(
            ['ssh', '-o', 'BatchMode=yes', $sshProfile, $remoteCmd],
            timeout: 300,
        );
        $process->setInput(fopen($dumpPath, 'r'));
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Remote DB-Import fehlgeschlagen: ' . ($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    public function readRemoteDatabaseUrl(RemoteCommandRunner $remote, string $sshProfile, string $remotePath): string
    {
        foreach (['.env.local', '.env'] as $file) {
            try {
                $result = $remote->capture(
                    $sshProfile,
                    sprintf(
                        'grep "^DATABASE_URL=" %s | head -1 | cut -d= -f2-',
                        escapeshellarg($remotePath . '/' . $file),
                    ),
                );
                $result = trim($result, "\"' \t\n\r");
                if ($result !== '') {
                    return $result;
                }
            } catch (\RuntimeException) {
                continue;
            }
        }

        throw new \RuntimeException('Remote DATABASE_URL nicht gefunden (weder in .env.local noch .env)');
    }

    public function readLocalDatabaseUrl(string $projectRoot): string
    {
        foreach (['.env.local', '.env'] as $file) {
            $path = $projectRoot . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            $content = (string) file_get_contents($path);
            if (preg_match('/^DATABASE_URL\s*=\s*"?([^"\n]+)"?/m', $content, $m)) {
                return trim($m[1]);
            }
        }

        throw new \RuntimeException('Lokale DATABASE_URL nicht gefunden (weder in .env.local noch .env)');
    }

    /**
     * Runs a query locally and returns trimmed scalar output (use -N, no column headers).
     */
    public function runLocalQueryScalar(string $projectRoot, string $query): string
    {
        if ($this->isDdev($projectRoot) && !$this->isInsideDdevContainer()) {
            $process = new Process(
                ['ddev', 'exec', 'mysql', '-u', 'db', '-pdb', 'db', '-N'],
                cwd: $projectRoot,
                timeout: 30,
            );
        } else {
            $url = $this->isInsideDdevContainer()
                ? $this->readDdevContainerDatabaseUrl($projectRoot)
                : $this->readLocalDatabaseUrl($projectRoot);
            $db = $this->parseDatabaseUrl($url);
            $cmd = ['mysql', '-h', $db['host'], '-P', (string) $db['port'], '-u', $db['user']];
            if ($db['password'] !== '') {
                $cmd[] = '--password=' . $db['password'];
            }
            $cmd[] = $db['name'];
            $cmd[] = '-N';
            $process = new Process($cmd, timeout: 30);
        }

        $process->setInput($query);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Lokale DB-Abfrage fehlgeschlagen: ' . ($process->getErrorOutput() ?: $process->getOutput()));
        }

        return trim($process->getOutput());
    }

    /**
     * Runs a query locally and returns all rows as associative arrays (uses --batch for tab-separated output with headers).
     *
     * @return list<array<string, string|null>>
     */
    public function runLocalQueryRows(string $projectRoot, string $query): array
    {
        if ($this->isDdev($projectRoot) && !$this->isInsideDdevContainer()) {
            $process = new Process(
                ['ddev', 'exec', 'mysql', '-u', 'db', '-pdb', 'db', '--batch'],
                cwd: $projectRoot,
                timeout: 60,
            );
        } else {
            $url = $this->isInsideDdevContainer()
                ? $this->readDdevContainerDatabaseUrl($projectRoot)
                : $this->readLocalDatabaseUrl($projectRoot);
            $db = $this->parseDatabaseUrl($url);
            $cmd = ['mysql', '-h', $db['host'], '-P', (string) $db['port'], '-u', $db['user']];
            if ($db['password'] !== '') {
                $cmd[] = '--password=' . $db['password'];
            }
            $cmd[] = $db['name'];
            $cmd[] = '--batch';
            $process = new Process($cmd, timeout: 60);
        }

        $process->setInput($query);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Lokale DB-Abfrage fehlgeschlagen: ' . ($process->getErrorOutput() ?: $process->getOutput()));
        }

        return $this->parseTabSeparated($process->getOutput());
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function parseTabSeparated(string $output): array
    {
        $lines = explode("\n", trim($output));
        if (count($lines) < 2) {
            return [];
        }

        $headers = explode("\t", $lines[0]);
        $rows = [];

        foreach (array_slice($lines, 1) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = explode("\t", $line);
            $row = [];
            foreach ($headers as $i => $col) {
                $val = $values[$i] ?? null;
                $row[$col] = ($val === 'NULL') ? null : $val;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    public function parseDatabaseUrl(string $url): array
    {
        $parsed = parse_url($url);

        if ($parsed === false || !isset($parsed['host'])) {
            throw new \RuntimeException('Ungültige DATABASE_URL: ' . $url);
        }

        return [
            'host' => $parsed['host'],
            'port' => $parsed['port'] ?? 3306,
            'user' => urldecode($parsed['user'] ?? ''),
            'password' => urldecode($parsed['pass'] ?? ''),
            'name' => ltrim($parsed['path'] ?? '', '/'),
        ];
    }
}

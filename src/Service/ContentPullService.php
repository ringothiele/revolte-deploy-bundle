<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Service;

use Symfony\Component\Process\Process;

class ContentPullService
{
    public function __construct(private readonly DatabaseSyncService $dbSync)
    {
    }

    /**
     * Dumps the remote DB and imports it locally (DDEV-aware).
     * Returns the temp dump file path (caller must delete).
     */
    public function pullDatabase(
        string $sshProfile,
        string $remotePath,
        string $projectRoot,
        RemoteCommandRunner $remote,
        array $noDataTables = [],
    ): void {
        $remoteDatabaseUrl = $this->dbSync->readRemoteDatabaseUrl($remote, $sshProfile, $remotePath);
        $db = $this->dbSync->parseDatabaseUrl($remoteDatabaseUrl);

        $tmpFile = sys_get_temp_dir() . '/revolte_content_pull_' . time() . '.sql.gz';

        if ($noDataTables !== []) {
            $schemaOnlyTables = implode(' ', array_map('escapeshellarg', $noDataTables));
            $ignoreFlags = implode(' ', array_map(
                fn ($t) => '--ignore-table=' . escapeshellarg($db['name'] . '.' . $t),
                $noDataTables,
            ));

            $remoteCmd = sprintf(
                '(mysqldump --password=%s --no-data -h %s -P %d -u %s %s %s; mysqldump --password=%s -h %s -P %d -u %s %s %s) | gzip',
                escapeshellarg($db['password']),
                escapeshellarg($db['host']),
                (int) $db['port'],
                escapeshellarg($db['user']),
                escapeshellarg($db['name']),
                $schemaOnlyTables,
                escapeshellarg($db['password']),
                escapeshellarg($db['host']),
                (int) $db['port'],
                escapeshellarg($db['user']),
                escapeshellarg($db['name']),
                $ignoreFlags,
            );
        } else {
            $remoteCmd = sprintf(
                'mysqldump --password=%s -h %s -P %d -u %s %s | gzip',
                escapeshellarg($db['password']),
                escapeshellarg($db['host']),
                (int) $db['port'],
                escapeshellarg($db['user']),
                escapeshellarg($db['name']),
            );
        }

        // Stream remote dump to local temp file
        $process = Process::fromShellCommandline(
            sprintf('ssh -o BatchMode=yes %s %s > %s',
                escapeshellarg($sshProfile),
                escapeshellarg($remoteCmd),
                escapeshellarg($tmpFile),
            ),
            timeout: 300,
        );
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Remote DB-Dump fehlgeschlagen: ' . ($process->getErrorOutput() ?: $process->getOutput()));
        }

        try {
            $this->importLocalDatabase($projectRoot, $tmpFile);
        } finally {
            if (is_file($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    private function importLocalDatabase(string $projectRoot, string $dumpFile): void
    {
        if ($this->dbSync->isDdev($projectRoot) && !$this->dbSync->isInsideDdevContainer()) {
            $process = new Process(
                ['ddev', 'import-db', '--file=' . $dumpFile],
                cwd: $projectRoot,
                timeout: 300,
            );
        } else {
            $url = $this->dbSync->isInsideDdevContainer()
                ? $this->dbSync->readDdevContainerDatabaseUrl($projectRoot)
                : $this->dbSync->readLocalDatabaseUrl($projectRoot);
            $db = $this->dbSync->parseDatabaseUrl($url);
            $password = escapeshellarg($db['password']);
            $process = Process::fromShellCommandline(
                sprintf('zcat %s | mysql --password=%s -h %s -P %d -u %s %s',
                    escapeshellarg($dumpFile),
                    $password,
                    escapeshellarg($db['host']),
                    (int) $db['port'],
                    escapeshellarg($db['user']),
                    escapeshellarg($db['name']),
                ),
                timeout: 300,
            );
        }

        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Lokaler DB-Import fehlgeschlagen: ' . ($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    /**
     * Reads local DB field values before import (to restore afterwards).
     *
     * @param array<string, list<string>> $preserveFields
     * @return array<string, list<array<string, mixed>>>
     */
    public function readLocalPreservedFields(string $projectRoot, array $preserveFields): array
    {
        $saved = [];

        foreach ($preserveFields as $table => $fields) {
            $fieldList = 'id, ' . implode(', ', $fields);
            $query = sprintf('SELECT %s FROM %s', $fieldList, $table);

            $output = $this->runLocalQuery($projectRoot, $query);
            $rows = [];

            foreach (explode("\n", trim($output)) as $i => $line) {
                if ($i === 0 || trim($line) === '') {
                    continue;
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
     * Restores local DB field values after import.
     *
     * @param array<string, list<array<string, mixed>>> $saved
     */
    /**
     * @return list<string> Warnings for fields that could not be restored
     */
    public function restoreLocalPreservedFields(string $projectRoot, array $saved): array
    {
        $warnings = [];

        foreach ($saved as $table => $rows) {
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $sets = [];

                foreach ($row as $field => $value) {
                    if ($field === 'id') {
                        continue;
                    }
                    $sets[] = sprintf(
                        '%s = %s',
                        $field,
                        $value === null ? 'NULL' : sprintf("'%s'", addslashes((string) $value)),
                    );
                }

                if ($sets === []) {
                    continue;
                }

                $query = sprintf('UPDATE %s SET %s WHERE id = %d', $table, implode(', ', $sets), $id);

                try {
                    $this->runLocalQuery($projectRoot, $query);
                } catch (\RuntimeException $e) {
                    // NOT NULL constraint: retry with empty string for all null values
                    if (str_contains($e->getMessage(), '1048') || str_contains($e->getMessage(), 'cannot be null')) {
                        $fallbackSets = [];
                        foreach ($row as $field => $value) {
                            if ($field === 'id') {
                                continue;
                            }
                            $fallbackSets[] = sprintf(
                                '%s = %s',
                                $field,
                                $value === null ? "''" : sprintf("'%s'", addslashes((string) $value)),
                            );
                        }
                        try {
                            $this->runLocalQuery($projectRoot, sprintf('UPDATE %s SET %s WHERE id = %d', $table, implode(', ', $fallbackSets), $id));
                        } catch (\RuntimeException $e2) {
                            $warnings[] = sprintf('%s id=%d: %s', $table, $id, $e2->getMessage());
                        }
                    } else {
                        $warnings[] = sprintf('%s id=%d: %s', $table, $id, $e->getMessage());
                    }
                }
            }
        }

        return $warnings;
    }

    /**
     * Rsyncs a single directory from remote to local.
     */
    public function pullDirectory(string $sshProfile, string $remoteDir, string $localDir): void
    {
        if (!is_dir($localDir)) {
            mkdir($localDir, 0755, true);
        }

        $process = new Process(
            ['rsync', '-az', '--delete', '-e', 'ssh -o BatchMode=yes', $sshProfile . ':' . $remoteDir . '/', $localDir . '/'],
            timeout: 300,
        );
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Datei-Transfer fehlgeschlagen: ' . ($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    /**
     * Writes a content baseline file after a successful pull.
     * Records pulled_at timestamp and MAX(id) for each tracked table.
     *
     * @param list<string> $tables
     */
    public function writeBaseline(string $projectRoot, string $environment, array $tables = ['tl_page', 'tl_article', 'tl_content', 'tl_layout', 'tl_module']): void
    {
        $maxIds = [];
        foreach ($tables as $table) {
            try {
                $maxIds[$table] = (int) $this->dbSync->runLocalQueryScalar(
                    $projectRoot,
                    sprintf('SELECT COALESCE(MAX(id), 0) FROM `%s`', $table),
                );
            } catch (\RuntimeException) {
                $maxIds[$table] = 0;
            }
        }

        $baseline = [
            'pulled_at' => time(),
            'environment' => $environment,
            'max_ids' => $maxIds,
        ];

        file_put_contents(
            $projectRoot . '/.revolte-content-baseline.json',
            json_encode($baseline, \JSON_PRETTY_PRINT) . "\n",
        );
    }

    private function runLocalQuery(string $projectRoot, string $query): string
    {
        return $this->dbSync->runLocalQueryScalar($projectRoot, $query);
    }
}

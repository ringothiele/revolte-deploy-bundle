<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Service;

use Symfony\Component\Process\Process;

class RemoteBackupService
{
    /**
     * Creates a full backup on the remote server (git commit + DB dump).
     * Returns the backup directory path on the remote.
     */
    public function createFullBackup(
        RemoteCommandRunner $remote,
        string $sshProfile,
        string $remotePath,
        array $remoteDb,
        string $backupBase,
        string $projectName,
        array $noDataTables = [],
    ): string {
        $backupDir = $this->makeBackupDir($remote, $sshProfile, $remotePath, $backupBase, $projectName, 'full');

        $this->saveCommit($remote, $sshProfile, $remotePath, $backupDir);
        $this->saveDbDump($remote, $sshProfile, $remoteDb, $backupDir, $noDataTables);

        return $backupDir;
    }

    /**
     * Creates a code-only backup on the remote server (git commit only, no DB).
     * Returns the backup directory path on the remote.
     */
    public function createCodeBackup(
        RemoteCommandRunner $remote,
        string $sshProfile,
        string $remotePath,
        string $backupBase,
        string $projectName,
    ): string {
        $backupDir = $this->makeBackupDir($remote, $sshProfile, $remotePath, $backupBase, $projectName, 'code');
        $this->saveCommit($remote, $sshProfile, $remotePath, $backupDir);

        return $backupDir;
    }

    /**
     * Lists all backups for the project, newest first.
     *
     * @return list<array{path: string, name: string, type: string, commit: string, date: string}>
     */
    public function listBackups(RemoteCommandRunner $remote, string $sshProfile, string $backupBase, string $projectName): array
    {
        $projectDir = $backupBase . '/' . $projectName;

        try {
            $output = $remote->capture(
                $sshProfile,
                sprintf('ls -1d %s/*/ 2>/dev/null | sort -r', escapeshellarg($projectDir)),
            );
        } catch (\RuntimeException) {
            return [];
        }

        $backups = [];

        foreach (array_filter(explode("\n", trim($output))) as $path) {
            $name = basename(rtrim($path, '/'));
            $parts = explode('_', $name, 4);

            // name format: YYYYMMDD_HHMMSS_{shortcommit}_{type}
            if (count($parts) < 4) {
                continue;
            }

            $date = $parts[0] . '_' . $parts[1];
            $type = $parts[3];

            $commit = '';
            try {
                $commit = $remote->capture($sshProfile, sprintf('cat %s', escapeshellarg(rtrim($path, '/') . '/commit.txt')));
            } catch (\RuntimeException) {
            }

            $backups[] = [
                'path' => rtrim($path, '/'),
                'name' => $name,
                'type' => $type,
                'commit' => substr($commit, 0, 8),
                'date' => substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2)
                    . ' ' . substr($date, 9, 2) . ':' . substr($date, 11, 2) . ':' . substr($date, 13, 2),
            ];
        }

        return $backups;
    }

    /**
     * Restores a backup: resets git to the saved commit and (if available) restores the DB.
     * Returns the commit hash that was restored.
     */
    public function restoreBackup(
        RemoteCommandRunner $remote,
        string $sshProfile,
        string $remotePath,
        string $backupPath,
        ?array $remoteDb,
    ): array {
        $commit = $remote->capture($sshProfile, sprintf('cat %s', escapeshellarg($backupPath . '/commit.txt')));
        $commit = trim($commit);

        if ($commit === '') {
            throw new \RuntimeException('Backup enthält keinen gültigen Commit-Hash.');
        }

        $currentCommit = $remote->capture(
            $sshProfile,
            sprintf('cd %s && git rev-parse HEAD', escapeshellarg($remotePath)),
        );

        $gitOutput = $remote->capture(
            $sshProfile,
            sprintf(
                'cd %s && git fetch origin 2>&1 && git reset --hard %s 2>&1',
                escapeshellarg($remotePath),
                escapeshellarg($commit),
            ),
            timeout: 60,
        );

        $dbFile = $backupPath . '/db.sql.gz';
        $hasDb = $remote->test($sshProfile, sprintf('test -f %s', escapeshellarg($dbFile)));

        if ($hasDb && $remoteDb !== null) {
            $remoteCmd = sprintf(
                'zcat %s | mysql --password=%s -h %s -P %d -u %s %s',
                escapeshellarg($dbFile),
                escapeshellarg($remoteDb['password']),
                escapeshellarg($remoteDb['host']),
                (int) $remoteDb['port'],
                escapeshellarg($remoteDb['user']),
                escapeshellarg($remoteDb['name']),
            );

            $process = new Process(['ssh', '-o', 'BatchMode=yes', $sshProfile, $remoteCmd], timeout: 300);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException('DB-Wiederherstellung fehlgeschlagen: ' . ($process->getErrorOutput() ?: $process->getOutput()));
            }
        }

        return [
            'from' => trim($currentCommit),
            'to' => $commit,
            'git_output' => $gitOutput,
            'db_restored' => $hasDb && $remoteDb !== null,
        ];
    }

    /**
     * Keeps only the $keep newest backups, removes the rest.
     */
    public function pruneBackups(
        RemoteCommandRunner $remote,
        string $sshProfile,
        string $backupBase,
        string $projectName,
        int $keep,
    ): int {
        $projectDir = $backupBase . '/' . $projectName;

        try {
            $output = $remote->capture(
                $sshProfile,
                sprintf('ls -1d %s/*/ 2>/dev/null | sort -r', escapeshellarg($projectDir)),
            );
        } catch (\RuntimeException) {
            return 0;
        }

        $dirs = array_filter(explode("\n", trim($output)));
        $toDelete = array_slice($dirs, $keep);

        foreach ($toDelete as $dir) {
            $remote->capture($sshProfile, sprintf('rm -rf %s', escapeshellarg(rtrim($dir, '/'))));
        }

        return count($toDelete);
    }

    private function makeBackupDir(
        RemoteCommandRunner $remote,
        string $sshProfile,
        string $remotePath,
        string $backupBase,
        string $projectName,
        string $type,
    ): string {
        $timestamp = $remote->capture($sshProfile, 'date +%Y%m%d_%H%M%S');
        $shortCommit = $remote->capture(
            $sshProfile,
            sprintf('cd %s && git rev-parse --short HEAD', escapeshellarg($remotePath)),
        );

        $dirName = sprintf('%s_%s_%s', trim($timestamp), trim($shortCommit), $type);
        $backupDir = $backupBase . '/' . $projectName . '/' . $dirName;

        $remote->capture($sshProfile, sprintf('mkdir -p %s', escapeshellarg($backupDir)));
        $remote->capture($sshProfile, sprintf('echo %s > %s', escapeshellarg($type), escapeshellarg($backupDir . '/type.txt')));

        return $backupDir;
    }

    private function saveCommit(
        RemoteCommandRunner $remote,
        string $sshProfile,
        string $remotePath,
        string $backupDir,
    ): void {
        $commit = $remote->capture(
            $sshProfile,
            sprintf('cd %s && git rev-parse HEAD', escapeshellarg($remotePath)),
        );

        $remote->capture(
            $sshProfile,
            sprintf('echo %s > %s', escapeshellarg(trim($commit)), escapeshellarg($backupDir . '/commit.txt')),
        );
    }

    private function saveDbDump(
        RemoteCommandRunner $remote,
        string $sshProfile,
        array $db,
        string $backupDir,
        array $noDataTables,
    ): void {
        $dbFile = $backupDir . '/db.sql.gz';

        if ($noDataTables !== []) {
            $schemaOnlyTables = implode(' ', array_map('escapeshellarg', $noDataTables));
            $ignoreFlags = implode(' ', array_map(
                fn ($t) => '--ignore-table=' . escapeshellarg($db['name'] . '.' . $t),
                $noDataTables,
            ));

            $cmd = sprintf(
                '(mysqldump --password=%s --no-data -h %s -P %d -u %s %s %s; mysqldump --password=%s -h %s -P %d -u %s %s %s) | gzip > %s',
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
                escapeshellarg($dbFile),
            );
        } else {
            $cmd = sprintf(
                'mysqldump --password=%s -h %s -P %d -u %s %s | gzip > %s',
                escapeshellarg($db['password']),
                escapeshellarg($db['host']),
                (int) $db['port'],
                escapeshellarg($db['user']),
                escapeshellarg($db['name']),
                escapeshellarg($dbFile),
            );
        }

        $process = new Process(['ssh', '-o', 'BatchMode=yes', $sshProfile, $cmd], timeout: 300);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Remote DB-Backup fehlgeschlagen: ' . ($process->getErrorOutput() ?: $process->getOutput()));
        }
    }
}

<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Service;

use Symfony\Component\Process\Process;

class ContentPushService
{
    /** @var list<string> */
    public const TABLES = ['tl_page', 'tl_article', 'tl_content', 'tl_layout', 'tl_module'];

    /**
     * FK column → referenced table for each tracked table.
     * Only columns that reference IDs of other tracked tables are listed.
     *
     * @var array<string, array<string, string>>
     */
    private const FK_DEFS = [
        'tl_page' => [
            'pid' => 'tl_page',
            'jumpTo' => 'tl_page',
        ],
        'tl_article' => [
            'pid' => 'tl_page',
        ],
        'tl_content' => [
            'pid' => 'tl_article',
            'cteAlias' => 'tl_content',
            'articleAlias' => 'tl_article',
            'module' => 'tl_module',
            'jumpTo' => 'tl_page',
        ],
        'tl_layout' => [],
        'tl_module' => [
            'jumpTo' => 'tl_page',
        ],
    ];

    public function __construct(private readonly DatabaseSyncService $dbSync)
    {
    }

    /**
     * @return array{pulled_at: int, environment: string, max_ids: array<string, int>}
     */
    public function readBaseline(string $projectRoot): array
    {
        $path = $projectRoot . '/.revolte-content-baseline.json';
        if (!is_file($path)) {
            throw new \RuntimeException('Keine Baseline gefunden. Zuerst revolte:deploy:content:pull ausführen.');
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !isset($data['max_ids'])) {
            throw new \RuntimeException('Baseline-Datei ist ungültig oder beschädigt.');
        }

        return $data;
    }

    /**
     * Warns if existing records (id <= baseline max) were modified locally since the pull.
     * These changes will NOT be pushed — only new records (id > baseline max) go to remote.
     *
     * @return list<string>
     */
    public function validatePrePush(string $projectRoot, array $baseline): array
    {
        $warnings = [];
        $pulledAt = (int) ($baseline['pulled_at'] ?? 0);

        foreach (self::TABLES as $table) {
            $maxId = (int) ($baseline['max_ids'][$table] ?? 0);
            if ($maxId === 0) {
                continue;
            }

            try {
                $count = (int) $this->dbSync->runLocalQueryScalar(
                    $projectRoot,
                    sprintf('SELECT COUNT(*) FROM `%s` WHERE id <= %d AND tstamp > %d', $table, $maxId, $pulledAt),
                );
                if ($count > 0) {
                    $warnings[] = sprintf(
                        '%s: %d bestehende(r) Datensatz/Datensätze seit dem Pull verändert — wird nicht gepusht.',
                        $table,
                        $count,
                    );
                }
            } catch (\RuntimeException) {
                // Table might not exist in this project
            }
        }

        return $warnings;
    }

    /**
     * Collects all new records (id > baseline max) for each tracked table.
     *
     * @return array<string, list<array<string, string|null>>>
     */
    public function collectNewRecords(string $projectRoot, array $baseline): array
    {
        $result = [];

        foreach (self::TABLES as $table) {
            $maxId = (int) ($baseline['max_ids'][$table] ?? 0);

            try {
                $rows = $this->dbSync->runLocalQueryRows(
                    $projectRoot,
                    sprintf('SELECT * FROM `%s` WHERE id > %d ORDER BY id ASC', $table, $maxId),
                );
                if ($rows !== []) {
                    $result[$table] = $rows;
                }
            } catch (\RuntimeException) {
                // Table might not exist
            }
        }

        return $result;
    }

    /**
     * Fetches MAX(id) for each tracked table on the remote in a single SSH round-trip.
     *
     * @return array<string, int>
     */
    public function getRemoteMaxIds(string $sshProfile, array $db): array
    {
        $parts = [];
        foreach (self::TABLES as $table) {
            $parts[] = sprintf("SELECT '%s', COALESCE(MAX(id),0) FROM `%s`", $table, $table);
        }

        $remoteCmd = sprintf(
            'mysql --password=%s -h %s -P %d -u %s %s -N -e %s',
            escapeshellarg($db['password']),
            escapeshellarg($db['host']),
            (int) $db['port'],
            escapeshellarg($db['user']),
            escapeshellarg($db['name']),
            escapeshellarg(implode(' UNION ALL ', $parts)),
        );

        $process = new Process(['ssh', '-o', 'BatchMode=yes', $sshProfile, $remoteCmd], timeout: 30);
        $process->run();

        $maxIds = array_fill_keys(self::TABLES, 0);

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Remote MAX(id)-Abfrage fehlgeschlagen: ' . ($process->getErrorOutput() ?: $process->getOutput()));
        }

        foreach (explode("\n", trim($process->getOutput())) as $line) {
            if (trim($line) === '') {
                continue;
            }
            [$table, $max] = explode("\t", $line, 2);
            if (array_key_exists($table, $maxIds)) {
                $maxIds[$table] = (int) $max;
            }
        }

        return $maxIds;
    }

    /**
     * Builds local_id → remote_id maps for all new records.
     * Remote IDs are assigned sequentially starting at remote_max + 1.
     *
     * @param array<string, list<array<string, string|null>>> $newRecords
     * @param array<string, int> $remoteMaxIds
     * @return array<string, array<int, int>>
     */
    public function buildIdMap(array $newRecords, array $remoteMaxIds): array
    {
        $idMap = [];
        $counters = $remoteMaxIds;

        foreach (self::TABLES as $table) {
            if (!isset($newRecords[$table])) {
                continue;
            }
            $idMap[$table] = [];
            foreach ($newRecords[$table] as $row) {
                $localId = (int) ($row['id'] ?? 0);
                $idMap[$table][$localId] = ++$counters[$table];
            }
        }

        return $idMap;
    }

    /**
     * Remaps IDs and FK references in all new records.
     * New tl_page records are always pushed with published='' (unpublished).
     *
     * @param array<string, list<array<string, string|null>>> $newRecords
     * @param array<string, array<int, int>> $idMap
     * @return array<string, list<array<string, string|null>>>
     */
    public function remapRecords(array $newRecords, array $idMap): array
    {
        $remapped = [];

        foreach (self::TABLES as $table) {
            if (!isset($newRecords[$table])) {
                continue;
            }
            $fkDefs = self::FK_DEFS[$table] ?? [];
            $remapped[$table] = [];

            foreach ($newRecords[$table] as $row) {
                // Remap own ID
                $localId = (int) ($row['id'] ?? 0);
                if (isset($idMap[$table][$localId])) {
                    $row['id'] = (string) $idMap[$table][$localId];
                }

                // Safety-net: new pages always unpublished on remote
                if ($table === 'tl_page') {
                    $row['published'] = '';
                }

                // Remap FK columns that reference new records in other tables
                foreach ($fkDefs as $column => $refTable) {
                    if (!array_key_exists($column, $row) || $row[$column] === null || $row[$column] === '' || $row[$column] === '0') {
                        continue;
                    }
                    $refId = (int) $row[$column];
                    if (isset($idMap[$refTable][$refId])) {
                        $row[$column] = (string) $idMap[$refTable][$refId];
                    }
                }

                $remapped[$table][] = $row;
            }
        }

        return $remapped;
    }

    /**
     * Inserts remapped records into the remote database via SQL piped through SSH.
     *
     * @param array<string, list<array<string, string|null>>> $remappedRecords
     * @return int Number of inserted rows
     */
    public function pushToRemote(string $sshProfile, array $db, array $remappedRecords): int
    {
        $sqls = ['SET NAMES utf8mb4;'];
        $totalRows = 0;

        foreach (self::TABLES as $table) {
            if (!isset($remappedRecords[$table]) || $remappedRecords[$table] === []) {
                continue;
            }

            $rows = $remappedRecords[$table];
            $columns = array_keys($rows[0]);
            $columnList = implode(', ', array_map(fn ($c) => '`' . $c . '`', $columns));

            foreach ($rows as $row) {
                $values = array_map(function ($v) {
                    if ($v === null) {
                        return 'NULL';
                    }

                    return "'" . $this->mysqlEscape((string) $v) . "'";
                }, array_values($row));

                $sqls[] = sprintf(
                    'INSERT INTO `%s` (%s) VALUES (%s);',
                    $table,
                    $columnList,
                    implode(', ', $values),
                );
                ++$totalRows;
            }
        }

        if ($sqls === []) {
            return 0;
        }

        $remoteCmd = sprintf(
            'mysql --password=%s -h %s -P %d -u %s %s',
            escapeshellarg($db['password']),
            escapeshellarg($db['host']),
            (int) $db['port'],
            escapeshellarg($db['user']),
            escapeshellarg($db['name']),
        );

        $process = new Process(['ssh', '-o', 'BatchMode=yes', $sshProfile, $remoteCmd], timeout: 60);
        $process->setInput(implode("\n", $sqls) . "\n");
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Remote INSERT fehlgeschlagen: ' . ($process->getErrorOutput() ?: $process->getOutput()));
        }

        return $totalRows;
    }

    private function mysqlEscape(string $value): string
    {
        return str_replace(
            ['\\', "\0", "\n", "\r", "'", '"', "\x1a"],
            ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
            $value,
        );
    }
}

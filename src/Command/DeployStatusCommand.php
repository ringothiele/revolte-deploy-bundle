<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Command;

use Revolte\DeployTools\Service\DeployConfigResolver;
use Revolte\DeployTools\Service\ProcessRunner;
use Revolte\DeployTools\Service\RemoteCommandRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'revolte:deploy:status',
    description: 'Zeigt welcher Commit auf welchen Umgebungen deployed ist',
)]
class DeployStatusCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly DeployConfigResolver $configResolver,
        private readonly RemoteCommandRunner $remote,
        private readonly ProcessRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('environment', InputArgument::OPTIONAL, 'Nur diese Umgebung anzeigen (Standard: alle)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filterEnv = $input->getArgument('environment');

        $io->title('Revolte Deploy — Status');

        try {
            $config = $this->configResolver->load();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $environments = $config['environments'] ?? [];

        if ($filterEnv !== null) {
            if (!isset($environments[$filterEnv])) {
                $io->error(sprintf(
                    'Umgebung "%s" nicht gefunden. Verfügbar: %s',
                    $filterEnv,
                    implode(', ', array_keys($environments)) ?: '(keine)',
                ));

                return Command::FAILURE;
            }
            $environments = [$filterEnv => $environments[$filterEnv]];
        }

        // ── Lokaler Stand ─────────────────────────────────────────────────────
        $localHash = $this->runner->captureOrNull(['git', '-C', $this->projectRoot, 'rev-parse', 'HEAD']) ?? '';
        $localSubject = $this->runner->captureOrNull(['git', '-C', $this->projectRoot, 'log', '-1', '--format=%s']) ?? '?';

        if ($localHash !== '') {
            $io->writeln(sprintf(
                ' Lokal:  <info>%s</info>  %s',
                substr($localHash, 0, 8),
                $localSubject,
            ));
            $io->newLine();
        }

        // ── Umgebungen ────────────────────────────────────────────────────────
        $rows = [];

        foreach ($environments as $envName => $envConfig) {
            $sshProfile = (string) ($envConfig['ssh_profile'] ?? '');
            $remotePath = (string) ($envConfig['remote_path'] ?? '');

            if ($sshProfile === '' || $remotePath === '') {
                $rows[] = [$envName, '—', '—', '—', '<comment>Keine SSH-Konfiguration</comment>'];
                continue;
            }

            try {
                $remoteInfo = $this->remote->capture(
                    $sshProfile,
                    sprintf(
                        'git -C %s log -1 --format=\'%%H%%x09%%s%%x09%%ci\' 2>/dev/null || true',
                        escapeshellarg($remotePath),
                    ),
                    timeout: 15,
                );

                if (trim($remoteInfo) === '') {
                    $rows[] = [$envName, '—', '—', '—', '<comment>Nicht initialisiert</comment>'];
                    continue;
                }

                [$remoteHash, $remoteSubject, $remoteDate] = array_pad(explode("\t", $remoteInfo, 3), 3, '');
                $remoteHash = trim($remoteHash);
                $remoteSubject = trim($remoteSubject);

                // Commits ahead
                $standStr = '—';
                if ($remoteHash !== '' && $localHash !== '') {
                    if ($remoteHash === $localHash) {
                        $standStr = '<info>✓ aktuell</info>';
                    } else {
                        $ahead = $this->runner->captureOrNull(
                            ['git', '-C', $this->projectRoot, 'rev-list', '--count', $remoteHash . '..' . $localHash],
                        );
                        $aheadCount = (int) ($ahead ?? '0');

                        $standStr = $aheadCount > 0
                            ? sprintf('<comment>%d Commit(s) ausstehend</comment>', $aheadCount)
                            : '<comment>divergiert</comment>';
                    }
                }

                $dateFormatted = strlen(trim($remoteDate)) >= 16 ? substr(trim($remoteDate), 0, 16) : trim($remoteDate);

                $rows[] = [
                    $envName,
                    substr($remoteHash, 0, 8),
                    mb_strimwidth($remoteSubject, 0, 48, '…'),
                    $dateFormatted,
                    $standStr,
                ];
            } catch (\RuntimeException) {
                $rows[] = [$envName, '—', '—', '—', '<error>SSH fehlgeschlagen</error>'];
            }
        }

        $io->table(
            ['Umgebung', 'Commit', 'Message', 'Deployed am', 'Stand'],
            $rows,
        );

        return Command::SUCCESS;
    }
}

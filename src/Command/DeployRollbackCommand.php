<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Command;

use Revolte\DeployTools\Service\DatabaseSyncService;
use Revolte\DeployTools\Service\DeployConfigResolver;
use Revolte\DeployTools\Service\RemoteBackupService;
use Revolte\DeployTools\Service\RemoteCommandRunner;
use Revolte\DeployTools\Service\SshProfileChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'revolte:deploy:rollback',
    description: 'Rollback auf ein früheres Backup (Code + optional DB)',
)]
class DeployRollbackCommand extends Command
{
    public function __construct(
        private readonly DeployConfigResolver $configResolver,
        private readonly SshProfileChecker $sshChecker,
        private readonly RemoteCommandRunner $remote,
        private readonly RemoteBackupService $backup,
        private readonly DatabaseSyncService $dbSync,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('environment', InputArgument::REQUIRED, 'Zielumgebung (z. B. stage, live)')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'Verfügbare Backups auflisten')
            ->addOption('backup', 'b', InputOption::VALUE_REQUIRED, 'Backup-Name (Standard: neuestes)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $environment = (string) $input->getArgument('environment');

        $io->title(sprintf('Revolte Deploy — Rollback: %s', $environment));

        try {
            $envConfig = $this->configResolver->getEnvironment($environment);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $config = $this->configResolver->load();
        $sshProfile = (string) ($envConfig['ssh_profile'] ?? '');
        $remotePath = (string) ($envConfig['remote_path'] ?? '');
        $phpCli = $envConfig['php_cli'] ?? 'auto';
        $backupBase = (string) ($config['rollback']['backup_path'] ?? '~/revolte-deploy-backups');
        $projectName = (string) ($config['project'] ?? 'project');

        if ('auto' === $phpCli) {
            $phpCli = 'php';
        }

        // ── SSH-Check ─────────────────────────────────────────────────────────
        if (!$this->sshChecker->testConnection($sshProfile)) {
            $profileDetails = $this->sshChecker->getProfileDetails($sshProfile);
            $identityFile = $profileDetails['identityfile'] ?? null;
            $keyPath = $identityFile !== null
                ? str_replace('~', (string) ($_SERVER['HOME'] ?? '~'), $identityFile)
                : '~/.ssh/id_ed25519';
            $io->error([
                sprintf('SSH-Verbindung zu "%s" fehlgeschlagen.', $sshProfile),
                'SSH-Agent starten und Key laden:',
                '  eval "$(ssh-agent -s)" && ssh-add ' . $keyPath,
            ]);

            return Command::FAILURE;
        }

        // ── Backups auflisten ─────────────────────────────────────────────────
        $backups = $this->backup->listBackups($this->remote, $sshProfile, $backupBase, $projectName);

        if ($backups === []) {
            $io->warning('Keine Backups gefunden.');

            return Command::SUCCESS;
        }

        if ($input->getOption('list')) {
            $io->table(
                ['#', 'Datum', 'Commit', 'Typ', 'Name'],
                array_map(
                    fn (int $i, array $b) => [$i + 1, $b['date'], $b['commit'], $b['type'], $b['name']],
                    array_keys($backups),
                    $backups,
                ),
            );

            return Command::SUCCESS;
        }

        // ── Backup auswählen ──────────────────────────────────────────────────
        $selectedName = $input->getOption('backup');

        if ($selectedName !== null) {
            $found = array_filter($backups, fn ($b) => $b['name'] === $selectedName);
            if ($found === []) {
                $io->error(sprintf('Backup "%s" nicht gefunden. Mit --list alle anzeigen.', $selectedName));

                return Command::FAILURE;
            }
            $selected = array_values($found)[0];
        } else {
            $selected = $backups[0]; // neuestes
        }

        $io->section('Ausgewähltes Backup');
        $io->definitionList(
            ['Datum' => $selected['date']],
            ['Commit' => $selected['commit']],
            ['Typ' => $selected['type']],
            ['DB enthalten' => isset($selected['type']) && 'full' === $selected['type'] ? 'ja' : 'nein'],
        );

        if (!$io->confirm(sprintf('Rollback auf "%s" durchführen?', $selected['name']), false)) {
            $io->note('Abgebrochen.');

            return Command::SUCCESS;
        }

        // ── Rollback ──────────────────────────────────────────────────────────
        $io->section('Rollback');

        try {
            $remoteDatabaseUrl = $this->dbSync->readRemoteDatabaseUrl($this->remote, $sshProfile, $remotePath);
            $remoteDb = $this->dbSync->parseDatabaseUrl($remoteDatabaseUrl);
        } catch (\RuntimeException) {
            $remoteDb = null;
        }

        try {
            $io->writeln(' Code zurücksetzen ...');
            $result = $this->backup->restoreBackup($this->remote, $sshProfile, $remotePath, $selected['path'], $remoteDb);

            $io->writeln(sprintf('   von: <comment>%s</comment>', substr($result['from'], 0, 8)));
            $io->writeln(sprintf('   auf: <info>%s</info>', substr($result['to'], 0, 8)));
            $io->newLine();

            foreach (explode("\n", $result['git_output']) as $line) {
                if (trim($line)) {
                    $io->writeln('   ' . $line);
                }
            }

            $io->writeln($result['db_restored']
                ? ' <info>✓</info> Code + DB zurückgesetzt'
                : ' <info>✓</info> Code zurückgesetzt (kein DB-Backup vorhanden)');
        } catch (\RuntimeException $e) {
            $io->error(['Rollback fehlgeschlagen:', $e->getMessage()]);

            return Command::FAILURE;
        }

        // ── Composer, Cache, Migrate, Filesync ───────────────────────────────
        $io->section('Wiederherstellung');

        foreach ([
            ['Composer installieren ...', sprintf('cd %s && composer install --no-dev --no-interaction --no-scripts 2>&1 && composer dump-autoload --no-dev --optimize --no-interaction 2>&1', escapeshellarg($remotePath)), 300, 'Abhängigkeiten installiert'],
            ['Cache leeren ...', sprintf('cd %s && %s vendor/bin/contao-console cache:clear --no-interaction 2>&1', escapeshellarg($remotePath), $phpCli), 120, 'Cache geleert'],
            ['Migrationen ...', sprintf('cd %s && %s vendor/bin/contao-console contao:migrate --no-interaction 2>&1', escapeshellarg($remotePath), $phpCli), 180, 'Migrationen abgeschlossen'],
        ] as [$label, $cmd, $timeout, $success]) {
            try {
                $io->writeln(sprintf(' %s', $label));
                $this->remote->run(
                    $sshProfile,
                    $cmd,
                    function (string $_type, string $buffer) use ($io): void {
                        foreach (explode("\n", $buffer) as $line) {
                            if (trim($line)) {
                                $io->writeln('   ' . $line);
                            }
                        }
                    },
                    $timeout,
                );
                $io->writeln(sprintf(' <info>✓</info> %s', $success));
            } catch (\RuntimeException $e) {
                $io->error([$label . ' fehlgeschlagen', $e->getMessage()]);

                return Command::FAILURE;
            }
        }

        try {
            $this->remote->run(
                $sshProfile,
                sprintf('cd %s && %s vendor/bin/contao-console contao:filesync --no-interaction 2>&1', escapeshellarg($remotePath), $phpCli),
                null,
                120,
            );
        } catch (\RuntimeException) {
            // nicht kritisch
        }

        $io->success(sprintf('Rollback auf "%s" erfolgreich abgeschlossen.', $selected['name']));

        return Command::SUCCESS;
    }
}

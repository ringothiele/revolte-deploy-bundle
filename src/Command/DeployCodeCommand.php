<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Command;

use Revolte\DeployTools\Service\DeployConfigResolver;
use Revolte\DeployTools\Service\GitStatusChecker;
use Revolte\DeployTools\Service\ProcessRunner;
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
    name: 'revolte:deploy:code',
    description: 'Code-Deployment auf eine Zielumgebung (ohne DB-Übertragung)',
)]
class DeployCodeCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly DeployConfigResolver $configResolver,
        private readonly GitStatusChecker $git,
        private readonly SshProfileChecker $sshChecker,
        private readonly RemoteCommandRunner $remote,
        private readonly RemoteBackupService $backupService,
        private readonly ProcessRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('environment', InputArgument::REQUIRED, 'Zielumgebung (z. B. stage, live)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Zeigt was passieren würde, ohne etwas auszuführen')
            ->addOption('allow-dirty', null, InputOption::VALUE_NONE, 'Deploy auch bei nicht committeten Änderungen (nur Notfall)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $environment = (string) $input->getArgument('environment');
        $dryRun = (bool) $input->getOption('dry-run');
        $allowDirty = (bool) $input->getOption('allow-dirty');

        $io->title(sprintf('Revolte Deploy — Code: %s%s', $environment, $dryRun ? ' [DRY RUN]' : ''));

        try {
            $envConfig = $this->configResolver->getEnvironment($environment);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $sshProfile = (string) ($envConfig['ssh_profile'] ?? '');
        $remotePath = (string) ($envConfig['remote_path'] ?? '');
        $targetBranch = (string) ($envConfig['branch'] ?? 'main');
        $phpCli = $envConfig['php_cli'] ?? 'auto';
        $guardConfig = $envConfig['deploy_guard'] ?? false;
        $guardEnabled = $guardConfig !== false && $guardConfig !== null;
        $guardSingleUse = is_array($guardConfig) && ($guardConfig['single_use'] ?? false);

        if ('auto' === $phpCli) {
            $phpCli = 'php';
        }

        // ── Lokale Checks ─────────────────────────────────────────────────────
        $io->section('Lokale Checks');

        if (!$this->git->isRepository($this->projectRoot)) {
            $io->error('Kein lokales Git-Repository gefunden.');

            return Command::FAILURE;
        }

        $branch = $this->git->getCurrentBranch($this->projectRoot);
        $allowedBranches = $envConfig['allowed_branches'] ?? [$targetBranch];

        if (!$this->git->isBranchAllowed($branch, $allowedBranches)) {
            $io->error([
                sprintf('Branch "%s" ist für "%s" nicht erlaubt.', $branch, $environment),
                sprintf('Erlaubt: %s', implode(', ', $allowedBranches)),
            ]);

            return Command::FAILURE;
        }

        $io->writeln(sprintf(' <info>✓</info> Branch: %s', $branch));

        if (!$this->git->isClean($this->projectRoot)) {
            if ($allowDirty) {
                $io->writeln(' <comment>!</comment> Working Tree nicht sauber — wird mit --allow-dirty ignoriert');
            } else {
                $io->error([
                    'Working Tree enthält nicht committete Änderungen.',
                    'Bitte committen oder mit --allow-dirty fortfahren (nur Notfall).',
                ]);

                return Command::FAILURE;
            }
        } else {
            $io->writeln(' <info>✓</info> Working Tree sauber');
        }

        if (!$this->git->isCommitPushed($this->projectRoot)) {
            $io->error([
                'Lokale Commits sind nicht auf Remote gepusht.',
                'Bitte zuerst pushen: git push',
            ]);

            return Command::FAILURE;
        }

        $io->writeln(' <info>✓</info> Aktueller Commit ist gepusht');

        // ── SSH-Check ─────────────────────────────────────────────────────────
        $io->section('Verbindung');

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
                'Danach testen: ssh ' . $sshProfile . ' "echo OK"',
            ]);

            return Command::FAILURE;
        }

        $io->writeln(sprintf(' <info>✓</info> SSH-Verbindung zu %s erfolgreich', $sshProfile));

        $isInitialized = $this->remote->test(
            $sshProfile,
            sprintf('test -f %s', escapeshellarg($remotePath . '/.git/HEAD')),
        );

        if (!$isInitialized) {
            $io->error([
                sprintf('Remote-Pfad "%s" ist nicht initialisiert.', $remotePath),
                sprintf('Bitte zuerst ausführen: revolte:deploy:init %s', $environment),
            ]);

            return Command::FAILURE;
        }

        $io->writeln(sprintf(' <info>✓</info> Remote-Pfad ist initialisiert: %s', $remotePath));

        // ── Deploy-Guard ──────────────────────────────────────────────────────
        $guardFile = $remotePath . '/.allow_deploy_code';

        if ($guardEnabled && !$dryRun) {
            if (!$this->remote->test($sshProfile, sprintf('test -f %s', escapeshellarg($guardFile)))) {
                $io->error([
                    sprintf('Deploy auf "%s" ist nicht freigegeben.', $environment),
                    sprintf('Freigabe erteilen (Datei anlegen):'),
                    sprintf('  ssh %s "touch %s"', $sshProfile, $guardFile),
                ]);

                return Command::FAILURE;
            }
            $io->writeln(' <info>✓</info> Deploy-Freigabe vorhanden (.allow_deploy_code)');
        }

        // Remote-HEAD holen (wird für Dry-Run-Diff gebraucht)
        $remoteDeployedHash = null;
        try {
            $hash = $this->remote->capture(
                $sshProfile,
                sprintf('git -C %s rev-parse HEAD 2>/dev/null', escapeshellarg($remotePath)),
                timeout: 10,
            );
            $remoteDeployedHash = $hash !== '' ? $hash : null;
        } catch (\RuntimeException) {
            // Non-critical
        }

        if ($dryRun) {
            $io->section('Dry Run');

            if ($remoteDeployedHash !== null) {
                $remoteSubject = $this->runner->captureOrNull(
                    ['git', '-C', $this->projectRoot, 'log', '-1', '--format=%s', $remoteDeployedHash],
                ) ?? '?';
                $io->writeln(sprintf(
                    ' Remote: <comment>%s</comment>  %s',
                    substr($remoteDeployedHash, 0, 8),
                    $remoteSubject,
                ));

                $commitLog = $this->runner->captureOrNull([
                    'git', '-C', $this->projectRoot,
                    'log', $remoteDeployedHash . '..HEAD', '--oneline',
                ]);

                $io->newLine();

                if ($commitLog !== null && $commitLog !== '') {
                    $commits = array_values(array_filter(explode("\n", $commitLog)));
                    $io->writeln(sprintf(' <info>%d</info> neuer/neue Commit(s) werden deployed:', count($commits)));
                    foreach ($commits as $line) {
                        $io->writeln('   ' . $line);
                    }
                } else {
                    $io->writeln(' <comment>!</comment> Kein Unterschied zum aktuell deployten Stand.');
                }

                $io->newLine();

                $changedFiles = $this->runner->captureOrNull([
                    'git', '-C', $this->projectRoot,
                    'diff', '--name-only', $remoteDeployedHash . '..HEAD',
                ]);
                $changedList = $changedFiles !== null
                    ? array_values(array_filter(explode("\n", $changedFiles)))
                    : [];

                if (in_array('composer.lock', $changedList, true)) {
                    $io->writeln(' <comment>!</comment> composer.lock geändert → Abhängigkeiten werden neu installiert, ggf. neue Migrationen');
                } else {
                    $io->writeln(' <info>✓</info> composer.lock unverändert');
                }

                $migrationFiles = array_filter($changedList, static fn (string $f) => stripos($f, 'migration') !== false);
                if ($migrationFiles !== []) {
                    $io->writeln(' <comment>!</comment> Migrations-Dateien geändert → Datenbankmigrationen werden ausgeführt');
                }

                $io->newLine();
            }

            $io->writeln(' Schritte die ausgeführt würden:');
            $io->listing([
                sprintf('git fetch && git reset --hard origin/%s', $targetBranch),
                'composer install --no-dev --optimize-autoloader',
                sprintf('%s vendor/bin/contao-console cache:clear', $phpCli),
                sprintf('%s vendor/bin/contao-console contao:migrate --no-interaction', $phpCli),
                'contao:filesync',
            ]);
            $io->note('Dry Run abgeschlossen — nichts wurde ausgeführt.');

            return Command::SUCCESS;
        }

        // ── Backup ────────────────────────────────────────────────────────────
        $config = $this->configResolver->load();
        $backupBase = (string) ($config['rollback']['backup_path'] ?? '~/revolte-deploy-backups');
        $projectName = (string) ($config['project'] ?? 'project');
        $keepBackups = (int) ($config['rollback']['keep_backups'] ?? 3);

        try {
            $io->section('Backup');
            $io->writeln(' Erstelle Backup vor Deploy ...');
            $backupPath = $this->backupService->createCodeBackup(
                $this->remote, $sshProfile, $remotePath, $backupBase, $projectName,
            );
            $io->writeln(sprintf(' <info>✓</info> Backup erstellt: %s', basename($backupPath)));
            $pruned = $this->backupService->pruneBackups($this->remote, $sshProfile, $backupBase, $projectName, $keepBackups);
            if ($pruned > 0) {
                $io->writeln(sprintf(' <comment>!</comment> %d alte(s) Backup(s) gelöscht', $pruned));
            }
        } catch (\RuntimeException $e) {
            $io->warning('Backup fehlgeschlagen (Deploy wird trotzdem fortgesetzt): ' . $e->getMessage());
        }

        // ── Git Pull ──────────────────────────────────────────────────────────
        $io->section('Git');

        try {
            $io->writeln(sprintf(' Hole Stand von origin/%s ...', $targetBranch));

            $gitOutput = $this->remote->capture(
                $sshProfile,
                sprintf(
                    'cd %s && git fetch origin 2>&1 && git reset --hard origin/%s 2>&1',
                    escapeshellarg($remotePath),
                    escapeshellarg($targetBranch),
                ),
                timeout: 60,
            );

            foreach (explode("\n", $gitOutput) as $line) {
                if (trim($line)) {
                    $io->writeln('   ' . $line);
                }
            }

            $io->writeln(sprintf(' <info>✓</info> Auf Stand origin/%s gebracht', $targetBranch));
        } catch (\RuntimeException $e) {
            $io->error(['Git fehlgeschlagen:', $e->getMessage()]);

            return Command::FAILURE;
        }

        // ── Composer Install ──────────────────────────────────────────────────
        $io->section('Composer');

        try {
            $io->writeln(' Installiere Abhängigkeiten ...');
            $io->newLine();

            $this->remote->run(
                $sshProfile,
                sprintf(
                    'cd %s && composer install --no-dev --no-interaction --no-scripts 2>&1 && composer dump-autoload --no-dev --optimize --no-interaction 2>&1',
                    escapeshellarg($remotePath),
                ),
                function (string $type, string $buffer) use ($io): void {
                    foreach (explode("\n", $buffer) as $line) {
                        if (trim($line)) {
                            $io->writeln('   ' . $line);
                        }
                    }
                },
                timeout: 300,
            );

            $io->writeln(' <info>✓</info> Abhängigkeiten installiert');
        } catch (\RuntimeException $e) {
            $io->error(['Composer fehlgeschlagen:', $e->getMessage()]);

            return Command::FAILURE;
        }

        // ── Cache & Migrations ────────────────────────────────────────────────
        $io->section('Cache & Migrations');

        try {
            $io->writeln(' Cache leeren ...');
            $io->newLine();

            $this->remote->run(
                $sshProfile,
                sprintf(
                    'cd %s && %s vendor/bin/contao-console cache:clear --no-interaction 2>&1',
                    escapeshellarg($remotePath),
                    $phpCli,
                ),
                function (string $type, string $buffer) use ($io): void {
                    foreach (explode("\n", $buffer) as $line) {
                        if (trim($line)) {
                            $io->writeln('   ' . $line);
                        }
                    }
                },
                timeout: 120,
            );

            $io->writeln(' <info>✓</info> Cache geleert');
        } catch (\RuntimeException $e) {
            $io->error(['Cache-Clear fehlgeschlagen:', $e->getMessage() ?: '(kein Output — Timeout oder Berechtigungsfehler)']);

            return Command::FAILURE;
        }

        try {
            $io->writeln(' Datenbankmigrationen ...');
            $io->newLine();

            $this->remote->run(
                $sshProfile,
                sprintf(
                    'cd %s && %s vendor/bin/contao-console contao:migrate --no-interaction 2>&1',
                    escapeshellarg($remotePath),
                    $phpCli,
                ),
                function (string $type, string $buffer) use ($io): void {
                    foreach (explode("\n", $buffer) as $line) {
                        if (trim($line)) {
                            $io->writeln('   ' . $line);
                        }
                    }
                },
                timeout: 180,
            );

            $io->writeln(' <info>✓</info> Migrationen abgeschlossen');
        } catch (\RuntimeException $e) {
            $io->error(['Contao Migrate fehlgeschlagen:', $e->getMessage() ?: '(kein Output)']);

            return Command::FAILURE;
        }

        // ── File Sync ─────────────────────────────────────────────────────────
        try {
            $io->writeln(' Dateisystem synchronisieren (DBAFS) ...');
            $io->newLine();

            $this->remote->run(
                $sshProfile,
                sprintf(
                    'cd %s && %s vendor/bin/contao-console contao:filesync --no-interaction 2>&1',
                    escapeshellarg($remotePath),
                    $phpCli,
                ),
                function (string $type, string $buffer) use ($io): void {
                    foreach (explode("\n", $buffer) as $line) {
                        if (trim($line)) {
                            $io->writeln('   ' . $line);
                        }
                    }
                },
                timeout: 120,
            );

            $io->writeln(' <info>✓</info> Dateisystem synchronisiert');
        } catch (\RuntimeException $e) {
            $io->warning('contao:filesync fehlgeschlagen (nicht kritisch): ' . $e->getMessage());
        }

        // ── Deploy-Guard entfernen (single_use) ──────────────────────────────
        if ($guardEnabled && $guardSingleUse) {
            try {
                $this->remote->run($sshProfile, sprintf('rm -f %s', escapeshellarg($guardFile)));
                $io->writeln(' <comment>!</comment> Deploy-Freigabe entfernt (single_use)');
            } catch (\RuntimeException) {
                // Non-critical
            }
        }

        // ── Fertig ────────────────────────────────────────────────────────────
        $io->success(sprintf('Code Deploy auf "%s" erfolgreich abgeschlossen.', $environment));

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Command;

use Revolte\DeployTools\Service\DatabaseSyncService;
use Revolte\DeployTools\Service\DeployConfigResolver;
use Revolte\DeployTools\Service\GitStatusChecker;
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
    name: 'revolte:deploy:full',
    description: 'Vollständiges Deployment auf eine Zielumgebung',
)]
class DeployFullCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly DeployConfigResolver $configResolver,
        private readonly GitStatusChecker $git,
        private readonly SshProfileChecker $sshChecker,
        private readonly RemoteCommandRunner $remote,
        private readonly DatabaseSyncService $dbSync,
        private readonly RemoteBackupService $backupService,
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

        $io->title(sprintf('Revolte Deploy — Full: %s%s', $environment, $dryRun ? ' [DRY RUN]' : ''));

        try {
            $envConfig = $this->configResolver->getEnvironment($environment);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $config = $this->configResolver->load();
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

        // Prüfen ob Init bereits durchgeführt wurde
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
        $guardFile = $remotePath . '/.allow_deploy_full';

        if ($guardEnabled && !$dryRun) {
            if (!$this->remote->test($sshProfile, sprintf('test -f %s', escapeshellarg($guardFile)))) {
                $io->error([
                    sprintf('Deploy auf "%s" ist nicht freigegeben.', $environment),
                    sprintf('Freigabe erteilen (Datei anlegen):'),
                    sprintf('  ssh %s "touch %s"', $sshProfile, $guardFile),
                ]);

                return Command::FAILURE;
            }
            $io->writeln(' <info>✓</info> Deploy-Freigabe vorhanden (.allow_deploy_full)');
        }

        if ($dryRun) {
            $io->section('Dry Run — folgende Schritte würden ausgeführt');
            $dumpSource = $this->dbSync->isDdev($this->projectRoot) ? 'ddev export-db' : 'mysqldump (DATABASE_URL)';
            $io->listing([
                sprintf('git fetch && git reset --hard origin/%s', $targetBranch),
                'composer install --no-dev --optimize-autoloader',
                sprintf('Lokale DB dumpen (%s)', $dumpSource),
                'DB-Dump auf Remote importieren (überschreibt Remote-DB)',
                sprintf('%s vendor/bin/contao-console cache:clear', $phpCli),
                sprintf('%s vendor/bin/contao-console contao:migrate --no-interaction', $phpCli),
            ]);
            $io->note('Dry Run abgeschlossen — nichts wurde ausgeführt.');

            return Command::SUCCESS;
        }

        // ── Backup ────────────────────────────────────────────────────────────
        $backupBase = (string) ($config['rollback']['backup_path'] ?? '~/revolte-deploy-backups');
        $projectName = (string) ($config['project'] ?? 'project');
        $noDataTables = $config['rollback']['no_data_tables'] ?? [];
        $keepBackups = (int) ($config['rollback']['keep_backups'] ?? 3);

        try {
            $io->section('Backup');
            $io->writeln(' Erstelle Backup vor Deploy ...');
            $remoteDatabaseUrl = $this->dbSync->readRemoteDatabaseUrl($this->remote, $sshProfile, $remotePath);
            $remoteDb = $this->dbSync->parseDatabaseUrl($remoteDatabaseUrl);
            $backupPath = $this->backupService->createFullBackup(
                $this->remote, $sshProfile, $remotePath, $remoteDb, $backupBase, $projectName, $noDataTables,
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

        // ── File Transfer (nicht-versionierte Verzeichnisse) ──────────────────
        $transferPatterns = $config['files']['transfer_on_full_deploy'] ?? [];

        if ($transferPatterns !== []) {
            $io->section('Datei-Transfer');

            foreach ($transferPatterns as $pattern) {
                $localDirs = glob($this->projectRoot . '/' . ltrim($pattern, '/'), \GLOB_ONLYDIR | \GLOB_MARK);

                if (empty($localDirs)) {
                    $io->writeln(sprintf(' <comment>!</comment> Keine lokalen Verzeichnisse für Muster "%s" gefunden — übersprungen', $pattern));
                    continue;
                }

                foreach ($localDirs as $localDir) {
                    $relativeDir = ltrim(str_replace($this->projectRoot, '', rtrim($localDir, '/')), '/');
                    $remoteDir = $remotePath . '/' . $relativeDir;

                    $io->writeln(sprintf(' Übertrage %s ...', $relativeDir));

                    $process = new \Symfony\Component\Process\Process(
                        ['rsync', '-az', '--delete', '-e', 'ssh -o BatchMode=yes', $localDir, $sshProfile . ':' . $remoteDir . '/'],
                        timeout: 300,
                    );
                    $process->run();

                    if (!$process->isSuccessful()) {
                        $io->error([sprintf('Datei-Transfer fehlgeschlagen: %s', $relativeDir), $process->getErrorOutput()]);

                        return Command::FAILURE;
                    }

                    $io->writeln(sprintf(' <info>✓</info> %s übertragen', $relativeDir));
                }
            }
        }

        // ── Datenbank ─────────────────────────────────────────────────────────
        $io->section('Datenbank');

        $dumpPath = null;

        $preserveFields = $config['database']['preserve_on_full_deploy'] ?? [];

        try {
            $remoteDatabaseUrl = $this->dbSync->readRemoteDatabaseUrl($this->remote, $sshProfile, $remotePath);
            $remoteDb = $this->dbSync->parseDatabaseUrl($remoteDatabaseUrl);

            if ($preserveFields !== []) {
                $io->writeln(sprintf(' Sichere %d geschützte Felder ...', array_sum(array_map('count', $preserveFields))));
                $preserved = $this->dbSync->readPreservedFields($this->remote, $sshProfile, $remoteDb, $preserveFields);
                $io->writeln(' <info>✓</info> Felder gesichert');
            } else {
                $preserved = [];
            }

            $io->writeln(' Lokale Datenbank dumpen ...');
            $dumpPath = $this->dbSync->dumpLocal($this->projectRoot);
            $io->writeln(' <info>✓</info> Dump erstellt');

            $io->writeln(' Importiere auf Remote (überschreibt Remote-DB) ...');
            $this->dbSync->importRemote($sshProfile, $remotePath, $dumpPath, $this->remote);
            $io->writeln(' <info>✓</info> Datenbank importiert');

            if ($preserved !== []) {
                $io->writeln(' Stelle geschützte Felder wieder her ...');
                $this->dbSync->restorePreservedFields($this->remote, $sshProfile, $remoteDb, $preserved);
                $io->writeln(' <info>✓</info> Felder wiederhergestellt');
            }
        } catch (\RuntimeException $e) {
            $io->error(['Datenbank-Sync fehlgeschlagen:', $e->getMessage()]);

            return Command::FAILURE;
        } finally {
            if ($dumpPath !== null && is_file($dumpPath)) {
                unlink($dumpPath);
            }
        }

        // ── Cache leeren ──────────────────────────────────────────────────────
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

        // ── Contao Migrate ────────────────────────────────────────────────────
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
        $io->success(sprintf('Full Deploy auf "%s" erfolgreich abgeschlossen.', $environment));

        return Command::SUCCESS;
    }
}

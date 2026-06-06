<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Command;

use Revolte\DeployTools\Service\ContentPullService;
use Revolte\DeployTools\Service\DeployConfigResolver;
use Revolte\DeployTools\Service\GitStatusChecker;
use Revolte\DeployTools\Service\RemoteCommandRunner;
use Revolte\DeployTools\Service\SshProfileChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'revolte:deploy:content:pull',
    description: 'Content von stage/live in die lokale Entwicklungsumgebung holen',
)]
class ContentPullCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly DeployConfigResolver $configResolver,
        private readonly GitStatusChecker $git,
        private readonly SshProfileChecker $sshChecker,
        private readonly RemoteCommandRunner $remote,
        private readonly ContentPullService $contentPull,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('environment', InputArgument::REQUIRED, 'Quellumgebung (z. B. stage, live)')
            ->addOption('skip-git-pull', null, InputOption::VALUE_NONE, 'Kein git pull aus dem Repository')
            ->addOption('skip-database', null, InputOption::VALUE_NONE, 'Keine Datenbank übernehmen')
            ->addOption('skip-files', null, InputOption::VALUE_NONE, 'Keine Dateien übernehmen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $environment = (string) $input->getArgument('environment');
        $skipGitPull = (bool) $input->getOption('skip-git-pull');
        $skipDatabase = (bool) $input->getOption('skip-database');
        $skipFiles = (bool) $input->getOption('skip-files');

        $io->title(sprintf('Revolte Deploy — Content Pull: %s → lokal', $environment));

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

        if ('auto' === $phpCli) {
            $phpCli = 'php';
        }

        $pullConfig = $config['content_pull'] ?? [];
        $directories = $pullConfig['directories'] ?? [];
        $preserveLocalFields = $pullConfig['preserve_local_fields'] ?? [];
        $noDataTables = $pullConfig['no_data_tables'] ?? ($config['rollback']['no_data_tables'] ?? []);

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
            ]);

            return Command::FAILURE;
        }

        $io->writeln(sprintf(' <info>✓</info> SSH-Verbindung zu %s erfolgreich', $sshProfile));

        // ── Git Pull ──────────────────────────────────────────────────────────
        if (!$skipGitPull) {
            $io->section('Git');

            $branch = $this->git->getCurrentBranch($this->projectRoot);
            $io->writeln(sprintf(' Hole aktuellen Stand von origin/%s ...', $branch));

            $process = new Process(['git', 'pull', 'origin', $branch], cwd: $this->projectRoot, timeout: 60);
            $process->run();

            if (!$process->isSuccessful()) {
                $io->error(['git pull fehlgeschlagen:', $process->getErrorOutput() ?: $process->getOutput()]);

                return Command::FAILURE;
            }

            foreach (explode("\n", $process->getOutput()) as $line) {
                if (trim($line)) {
                    $io->writeln('   ' . $line);
                }
            }

            $io->writeln(sprintf(' <info>✓</info> Code aktualisiert'));
        }

        // ── Datenbank ─────────────────────────────────────────────────────────
        if (!$skipDatabase) {
            $io->section('Datenbank');

            try {
                if ($preserveLocalFields !== []) {
                    $io->writeln(sprintf(' Sichere %d lokale Felder ...', array_sum(array_map('count', $preserveLocalFields))));
                    $preserved = $this->contentPull->readLocalPreservedFields($this->projectRoot, $preserveLocalFields);
                    $io->writeln(' <info>✓</info> Felder gesichert');
                } else {
                    $preserved = [];
                }

                $io->writeln(sprintf(' Hole Datenbank von %s ...', $environment));
                $this->contentPull->pullDatabase($sshProfile, $remotePath, $this->projectRoot, $this->remote, $noDataTables);
                $io->writeln(' <info>✓</info> Datenbank importiert');

                $this->contentPull->writeBaseline($this->projectRoot, $environment);
                $io->writeln(' <info>✓</info> Baseline gespeichert (.revolte-content-baseline.json)');

                if ($preserved !== []) {
                    $io->writeln(' Stelle lokale Felder wieder her ...');
                    $restoreWarnings = $this->contentPull->restoreLocalPreservedFields($this->projectRoot, $preserved);
                    foreach ($restoreWarnings as $warning) {
                        $io->writeln(sprintf(' <comment>!</comment> Feld konnte nicht wiederhergestellt werden: %s', $warning));
                    }
                    $io->writeln(' <info>✓</info> Felder wiederhergestellt');
                }
            } catch (\RuntimeException $e) {
                $io->error(['Datenbank-Pull fehlgeschlagen:', $e->getMessage()]);

                return Command::FAILURE;
            }
        }

        // ── Dateien ───────────────────────────────────────────────────────────
        if (!$skipFiles && $directories !== []) {
            $io->section('Dateien');

            // Expand glob patterns using local files/ subdirectories as base
            $projectFolders = array_map(
                fn ($d) => basename(rtrim($d, '/')),
                glob($this->projectRoot . '/files/*/', \GLOB_ONLYDIR) ?: [],
            );

            foreach ($directories as $pattern) {
                // Pattern like 'files/*/content/' → expand * to actual folder names
                $parts = explode('*', $pattern, 2);

                if (count($parts) !== 2) {
                    // No wildcard — use as-is
                    $localDir = rtrim($this->projectRoot . '/' . ltrim($pattern, '/'), '/');
                    $remoteDir = rtrim($remotePath . '/' . ltrim($pattern, '/'), '/');
                    try {
                        $io->writeln(sprintf(' Übertrage %s ...', ltrim($pattern, '/')));
                        $this->contentPull->pullDirectory($sshProfile, $remoteDir, $localDir);
                        $io->writeln(sprintf(' <info>✓</info> %s', ltrim($pattern, '/')));
                    } catch (\RuntimeException $e) {
                        $io->warning(sprintf('%s übersprungen: %s', ltrim($pattern, '/'), $e->getMessage()));
                    }
                    continue;
                }

                [$prefix, $suffix] = $parts;

                foreach ($projectFolders as $folder) {
                    $relPath = rtrim($prefix . $folder . $suffix, '/');
                    $localDir = $this->projectRoot . '/' . $relPath;
                    $remoteDir = $remotePath . '/' . $relPath;

                    try {
                        $io->writeln(sprintf(' Übertrage %s ...', $relPath));
                        $this->contentPull->pullDirectory($sshProfile, $remoteDir, $localDir);
                        $io->writeln(sprintf(' <info>✓</info> %s', $relPath));
                    } catch (\RuntimeException $e) {
                        $io->writeln(sprintf(' <comment>!</comment> %s übersprungen (nicht vorhanden oder leer)', $relPath));
                    }
                }
            }
        }

        // ── Lokaler Cache & Migrate ───────────────────────────────────────────
        if (!$skipDatabase) {
            $io->section('Lokaler Cache & Migrate');

            $isDdevHost = is_dir($this->projectRoot . '/.ddev')
                && !isset($_SERVER['IS_DDEV_PROJECT'])
                && !isset($_ENV['IS_DDEV_PROJECT']);

            foreach ([
                ['Cache leeren ...', $isDdevHost
                    ? ['ddev', 'exec', $phpCli, 'vendor/bin/contao-console', 'cache:clear', '--no-interaction']
                    : [$phpCli, 'vendor/bin/contao-console', 'cache:clear', '--no-interaction'],
                    'Cache geleert'],
                ['Migrationen ...', $isDdevHost
                    ? ['ddev', 'exec', $phpCli, 'vendor/bin/contao-console', 'contao:migrate', '--no-interaction']
                    : [$phpCli, 'vendor/bin/contao-console', 'contao:migrate', '--no-interaction'],
                    'Migrationen abgeschlossen'],
            ] as [$label, $cmd, $success]) {
                $io->writeln(sprintf(' %s', $label));
                $process = new Process($cmd, cwd: $this->projectRoot, timeout: 120);
                $process->run(function (string $_type, string $buffer) use ($io): void {
                    foreach (explode("\n", $buffer) as $line) {
                        if (trim($line)) {
                            $io->writeln('   ' . $line);
                        }
                    }
                });

                if (!$process->isSuccessful()) {
                    $io->warning(sprintf('%s fehlgeschlagen (nicht kritisch): %s', $label, $process->getErrorOutput()));
                } else {
                    $io->writeln(sprintf(' <info>✓</info> %s', $success));
                }
            }
        }

        $io->success(sprintf('Content Pull von "%s" abgeschlossen.', $environment));

        return Command::SUCCESS;
    }
}

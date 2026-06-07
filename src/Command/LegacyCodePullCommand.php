<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Command;

use Revolte\DeployTools\Service\DeployConfigResolver;
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
    name: 'revolte:legacy:code:pull',
    description: 'Code eines Legacy-Projekts vom Server lokal holen (für Projekte ohne Git-Repository)',
)]
class LegacyCodePullCommand extends Command
{
    private const REMOTE_EXCLUDE = [
        '.git/',
        '.ddev/',
        'vendor/',
        'var/cache/',
        'var/log/',
        'node_modules/',
        '.env.local',
        '.env.*.local',
    ];

    public function __construct(
        private readonly string $projectRoot,
        private readonly DeployConfigResolver $configResolver,
        private readonly SshProfileChecker $sshChecker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('environment', InputArgument::REQUIRED, 'Quellumgebung (z. B. live)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Zeigt was übertragen würde, ohne etwas auszuführen')
            ->addOption('skip-composer', null, InputOption::VALUE_NONE, 'Kein composer install nach dem Sync');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $environment  = (string) $input->getArgument('environment');
        $dryRun       = (bool) $input->getOption('dry-run');
        $skipComposer = (bool) $input->getOption('skip-composer');

        $io->title(sprintf('Revolte — Legacy Code Pull: %s → lokal', $environment));

        $io->warning([
            'Dieser Command überschreibt lokale Code-Dateien mit dem Stand vom Server.',
            'Nicht betroffen: .ddev/, .git/, vendor/, .env.local',
        ]);

        if (!$dryRun && !$io->confirm('Fortfahren?', false)) {
            return Command::SUCCESS;
        }

        try {
            $envConfig = $this->configResolver->getEnvironment($environment);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $sshProfile = (string) ($envConfig['ssh_profile'] ?? '');
        $remotePath = (string) ($envConfig['remote_path'] ?? '');

        if (!$sshProfile || !$remotePath) {
            $io->error('ssh_profile und remote_path müssen in revolte_deploy.yaml gesetzt sein.');

            return Command::FAILURE;
        }

        // ── SSH prüfen ────────────────────────────────────────────────────────
        $io->section('Verbindung');

        if (!$this->sshChecker->testConnection($sshProfile)) {
            $isInsideDdev = isset($_SERVER['IS_DDEV_PROJECT']) || isset($_ENV['IS_DDEV_PROJECT']);
            $hint = $isInsideDdev
                ? 'SSH-Key nicht im ddev-Agent — bitte im Host-Terminal ausführen: ddev auth ssh'
                : sprintf('SSH-Key nicht im Agent — bitte ausführen: ssh-add ~/.ssh/<key> und dann erneut versuchen');

            $io->error([sprintf('SSH-Verbindung zu "%s" fehlgeschlagen.', $sshProfile), $hint]);

            return Command::FAILURE;
        }

        $io->writeln(sprintf(' <info>✓</info> SSH-Verbindung zu %s erfolgreich', $sshProfile));

        // ── Code-Sync ─────────────────────────────────────────────────────────
        $io->section($dryRun ? 'Code-Sync (dry-run)' : 'Code-Sync');

        $excludeArgs = array_map(
            static fn (string $path) => '--exclude=' . $path,
            self::REMOTE_EXCLUDE,
        );

        $cmd = [
            'rsync',
            '--archive',
            '--itemize-changes',
            '--human-readable',
            '-e', 'ssh -o BatchMode=yes',
        ];

        if ($dryRun) {
            $cmd[] = '--dry-run';
        } else {
            $cmd[] = '--delete';
            $cmd[] = '--filter=protect .ddev/';
            $cmd[] = '--filter=protect .git/';
            $cmd[] = '--filter=protect .env.local';
        }

        $cmd = array_merge($cmd, $excludeArgs, [
            $sshProfile . ':' . rtrim($remotePath, '/') . '/',
            rtrim($this->projectRoot, '/') . '/',
        ]);

        $process = new Process($cmd, timeout: 600);
        $hasChanges = false;

        $process->run(function (string $type, string $buffer) use ($io, &$hasChanges): void {
            foreach (explode("\n", $buffer) as $line) {
                if (!trim($line)) {
                    continue;
                }
                $hasChanges = true;
                $io->writeln('   ' . $line);
            }
        });

        if (!$process->isSuccessful()) {
            $io->error(['Code-Sync fehlgeschlagen:', $process->getErrorOutput() ?: $process->getOutput()]);

            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->writeln($hasChanges
                ? ' <comment>Dry-run: obige Dateien würden übertragen werden.</comment>'
                : ' <info>Keine Änderungen — lokaler Stand ist identisch mit Server.</info>'
            );

            return Command::SUCCESS;
        }

        $io->writeln(' <info>✓</info> Code synchronisiert');

        // ── Composer install ──────────────────────────────────────────────────
        if (!$skipComposer) {
            $io->section('Composer install');

            $isInsideContainer = isset($_SERVER['IS_DDEV_PROJECT']) || isset($_ENV['IS_DDEV_PROJECT']);
            $hasDdev = is_dir($this->projectRoot . '/.ddev');

            if ($isInsideContainer) {
                // Läuft bereits im ddev-Container — composer direkt ausführen
                $composerCmd = ['composer', 'install', '--no-interaction'];
                $io->writeln(' Installiere Abhängigkeiten (composer install) ...');
            } elseif ($hasDdev) {
                // Läuft auf dem Host — via ddev in den Container
                $composerCmd = ['ddev', 'exec', 'composer', 'install', '--no-interaction'];
                $io->writeln(' Installiere Abhängigkeiten (ddev exec composer install) ...');
            } else {
                $composerCmd = ['composer', 'install', '--no-interaction'];
                $io->writeln(' Installiere Abhängigkeiten (composer install) ...');
            }

            $composer = new Process($composerCmd, cwd: $this->projectRoot, timeout: 300);
            $composer->run(function (string $type, string $buffer) use ($io): void {
                foreach (explode("\n", $buffer) as $line) {
                    if (trim($line)) {
                        $io->writeln('   ' . $line);
                    }
                }
            });

            if (!$composer->isSuccessful()) {
                $io->warning('composer install fehlgeschlagen — bitte manuell ausführen.');
            } else {
                $io->writeln(' <info>✓</info> Abhängigkeiten installiert');
            }
        }

        // ── Nächste Schritte ──────────────────────────────────────────────────
        $io->section('Nächste Schritte');
        $io->listing([
            sprintf('Datenbank und Content-Dateien holen: revolte:deploy:content:pull %s --skip-git-pull', $environment),
            'Git-Repository anlegen: git init && git remote add origin git@github.com:…/….git',
            'Alles committen und pushen',
            sprintf('revolte-ssh-setup erneut ausführen für neue Stage-Umgebung'),
            'revolte:deploy:init für den neuen Server-Pfad ausführen',
        ]);

        $io->success(sprintf('Code von "%s" erfolgreich lokal geholt.', $environment));

        return Command::SUCCESS;
    }
}

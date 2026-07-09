<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Command;

use Revolte\DeployTools\Service\ContaoVersionDetector;
use Revolte\DeployTools\Service\DeployConfigResolver;
use Revolte\DeployTools\Service\GitStatusChecker;
use Revolte\DeployTools\Service\ProcessRunner;
use Revolte\DeployTools\Service\SshProfileChecker;
use Revolte\DeployTools\Service\WebDirDetector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'revolte:deploy:doctor',
    description: 'Prüft die lokale Entwicklungsumgebung für Deployments',
)]
class DeployDoctorCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly ProcessRunner $runner,
        private readonly GitStatusChecker $git,
        private readonly ContaoVersionDetector $contaoDetector,
        private readonly WebDirDetector $webDirDetector,
        private readonly SshProfileChecker $sshChecker,
        private readonly DeployConfigResolver $configResolver,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Revolte Deploy — Lokale Umgebungsprüfung');

        $allGood = true;

        // ── System-Tools ────────────────────────────────────────────────────
        $io->section('System-Tools');

        $allGood = $this->checkPhp($io) && $allGood;
        $allGood = $this->checkComposer($io) && $allGood;
        $allGood = $this->checkGit($io) && $allGood;
        $this->checkSsh($io);
        $this->checkRsync($io);
        $this->checkDdev($io);

        // ── Projekt ──────────────────────────────────────────────────────────
        $io->section('Projekt');

        $allGood = $this->checkContaoConsole($io) && $allGood;
        $this->checkContaoVersion($io);
        $this->checkWebDir($io);
        $this->checkGitRepository($io);

        // ── Konfiguration ────────────────────────────────────────────────────
        $io->section('Deploy-Konfiguration');

        $this->checkDeployConfig($io);

        // ── KI-Schutz ────────────────────────────────────────────────────────
        $io->section('KI-Schutz');

        $this->checkKiGuard($io);

        // ── Ergebnis ─────────────────────────────────────────────────────────
        if ($allGood) {
            $io->success('Lokale Umgebung ist bereit.');
        } else {
            $io->warning('Es gibt offene Punkte. Bitte die markierten Fehler beheben.');
        }

        return $allGood ? Command::SUCCESS : Command::FAILURE;
    }

    private function checkPhp(SymfonyStyle $io): bool
    {
        $version = $this->runner->captureOrNull(['php', '--version']);

        if (null === $version) {
            $io->writeln(' <error>✗</error> PHP nicht gefunden');

            return false;
        }

        $firstLine = explode("\n", $version)[0];
        $io->writeln(sprintf(' <info>✓</info> %s', $firstLine));

        return true;
    }

    private function checkComposer(SymfonyStyle $io): bool
    {
        $version = $this->runner->captureOrNull(['composer', '--version']);

        if (null === $version) {
            $io->writeln(' <error>✗</error> Composer nicht gefunden');

            return false;
        }

        $io->writeln(sprintf(' <info>✓</info> %s', $version));

        return true;
    }

    private function checkGit(SymfonyStyle $io): bool
    {
        if (!$this->git->isInstalled()) {
            $io->writeln(' <error>✗</error> Git nicht gefunden');

            return false;
        }

        $version = $this->runner->captureOrNull(['git', '--version']) ?? 'unbekannte Version';
        $io->writeln(sprintf(' <info>✓</info> %s', $version));

        return true;
    }

    private function checkSsh(SymfonyStyle $io): void
    {
        $available = $this->runner->isAvailable('ssh');

        if (!$available) {
            $io->writeln(' <comment>?</comment> SSH-Client nicht gefunden (für Remote-Deployments benötigt)');

            return;
        }

        if ($this->sshChecker->sshConfigExists()) {
            $io->writeln(sprintf(' <info>✓</info> SSH vorhanden — ~/.ssh/config gefunden (%s)', $this->sshChecker->getSshConfigPath()));
        } else {
            $io->writeln(' <comment>!</comment> SSH vorhanden — aber keine ~/.ssh/config gefunden');
            $io->writeln('   → SSH-Profile für Projekte werden in ~/.ssh/config definiert');
        }
    }

    private function checkRsync(SymfonyStyle $io): void
    {
        if ($this->runner->isAvailable('rsync')) {
            $version = $this->runner->captureOrNull(['rsync', '--version']);
            $firstLine = $version ? explode("\n", $version)[0] : 'rsync';
            $io->writeln(sprintf(' <info>✓</info> %s', $firstLine));
        } else {
            $io->writeln(' <comment>!</comment> rsync nicht gefunden — wird für Content-Transfer benötigt');
            $io->writeln('   → WSL2/Ubuntu: sudo apt install rsync');
            $io->writeln('   → macOS: vorinstalliert, ggf. über Xcode Command Line Tools');
        }
    }

    private function checkDdev(SymfonyStyle $io): void
    {
        $isDdev = is_file($this->projectRoot . '/.ddev/config.yaml')
            || isset($_SERVER['DDEV_SITENAME'])
            || isset($_ENV['DDEV_SITENAME']);

        if ($isDdev) {
            $io->writeln(' <info>✓</info> DDEV-Umgebung erkannt — Deployment-Commands laufen lokal über DDEV');
        } else {
            $io->writeln(' <comment>i</comment> Kein DDEV erkannt');
        }
    }

    private function checkContaoConsole(SymfonyStyle $io): bool
    {
        $consolePath = $this->projectRoot . '/vendor/bin/contao-console';

        if (!is_file($consolePath)) {
            $io->writeln(' <error>✗</error> vendor/bin/contao-console nicht gefunden — ist Composer ausgeführt?');

            return false;
        }

        $io->writeln(' <info>✓</info> vendor/bin/contao-console vorhanden');

        return true;
    }

    private function checkContaoVersion(SymfonyStyle $io): void
    {
        $version = $this->contaoDetector->detect($this->projectRoot);

        if (null === $version) {
            $io->writeln(' <comment>?</comment> Contao-Version nicht erkannt (composer.lock fehlt oder kein Contao-Paket)');

            return;
        }

        $major = $this->contaoDetector->getMajorVersion($this->projectRoot);
        $supported = in_array($major, [4, 5], true);

        if ($supported) {
            $io->writeln(sprintf(' <info>✓</info> Contao %s erkannt', $version));
        } else {
            $io->writeln(sprintf(' <comment>!</comment> Contao %s erkannt — Version nicht explizit unterstützt', $version));
        }
    }

    private function checkWebDir(SymfonyStyle $io): void
    {
        $webDir = $this->webDirDetector->detect($this->projectRoot);

        if (null === $webDir) {
            $io->writeln(' <comment>?</comment> Webroot nicht erkannt (weder public/ noch web/ vorhanden)');

            return;
        }

        $io->writeln(sprintf(' <info>✓</info> Webroot: %s/', $webDir));
    }

    private function checkGitRepository(SymfonyStyle $io): void
    {
        if (!$this->git->isInstalled()) {
            return;
        }

        if (!$this->git->isRepository($this->projectRoot)) {
            $io->writeln(' <comment>!</comment> Kein Git-Repository — für Deployments wird Git benötigt');
            $io->writeln(sprintf('   → git init in %s', $this->projectRoot));

            return;
        }

        $branch = $this->git->getCurrentBranch($this->projectRoot);
        $io->writeln(sprintf(' <info>✓</info> Git-Repository — Branch: %s', $branch));
    }

    private function checkKiGuard(SymfonyStyle $io): void
    {
        $path = $this->projectRoot . '/.claude/settings.json';

        if (!is_file($path)) {
            $io->writeln(' <comment>!</comment> Keine .claude/settings.json — SSH-Deny-Regeln für Claude Code nicht aktiv');
            $io->writeln('   → Vorlage kopieren:');
            $io->writeln('   mkdir -p .claude && cp vendor/revolte/contao-deploy-tools/resources/ki/claude-settings.dist.json .claude/settings.json');

            return;
        }

        $content = (string) file_get_contents($path);

        if (str_contains($content, 'Bash(ssh')) {
            $io->writeln(' <info>✓</info> .claude/settings.json mit SSH-Deny-Regeln vorhanden');
        } else {
            $io->writeln(' <comment>!</comment> .claude/settings.json vorhanden, aber ohne SSH-Deny-Regeln');
            $io->writeln('   → Deny-Liste aus vendor/revolte/contao-deploy-tools/resources/ki/claude-settings.dist.json übernehmen');
        }
    }

    private function checkDeployConfig(SymfonyStyle $io): void
    {
        if (!$this->configResolver->configExists()) {
            $io->writeln(' <comment>!</comment> Keine config/revolte_deploy.yaml gefunden');
            $io->writeln('   → Vorlage kopieren:');
            $io->writeln('   cp vendor/revolte/contao-deploy-tools/resources/revolte_deploy.yaml.dist config/revolte_deploy.yaml');

            return;
        }

        try {
            $config = $this->configResolver->load();
            $project = $this->configResolver->getProjectName();
            $environments = $this->configResolver->getAvailableEnvironments();
            $profiles = $this->configResolver->getAvailableProfiles();

            $io->writeln(sprintf(' <info>✓</info> config/revolte_deploy.yaml geladen — Projekt: %s', $project));
            $io->writeln(sprintf('   Umgebungen: %s', implode(', ', $environments) ?: '(keine)'));
            $io->writeln(sprintf('   Profile:    %s', implode(', ', $profiles) ?: '(keine)'));

            // Check SSH profiles for all environments
            foreach ($environments as $env) {
                $envConfig = $config['environments'][$env] ?? [];
                $sshProfile = $envConfig['ssh_profile'] ?? null;

                if (null === $sshProfile) {
                    $io->writeln(sprintf('   <comment>!</comment> Umgebung "%s": kein ssh_profile konfiguriert', $env));
                    continue;
                }

                if ($this->sshChecker->profileExists($sshProfile)) {
                    $io->writeln(sprintf('   <info>✓</info> SSH-Profil "%s" (für %s) in ~/.ssh/config gefunden', $sshProfile, $env));
                } else {
                    $io->writeln(sprintf('   <comment>!</comment> SSH-Profil "%s" (für %s) nicht in ~/.ssh/config', $sshProfile, $env));
                    $io->writeln(sprintf('   → Empfohlener Eintrag für ~/.ssh/config:'));
                    $snippet = $this->sshChecker->buildRecommendedSnippet(
                        $sshProfile,
                        $this->configResolver->getProjectName(),
                        $env,
                    );
                    foreach (explode(PHP_EOL, $snippet) as $line) {
                        $io->writeln('   ' . $line);
                    }
                }
            }
        } catch (\RuntimeException $e) {
            $io->writeln(sprintf(' <error>✗</error> Konfigurationsfehler: %s', $e->getMessage()));
        }
    }
}

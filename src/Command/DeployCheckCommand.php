<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Command;

use Revolte\DeployTools\Service\DeployConfigResolver;
use Revolte\DeployTools\Service\GitStatusChecker;
use Revolte\DeployTools\Service\SshProfileChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'revolte:deploy:check',
    description: 'Prüft eine Zielumgebung vor dem Deployment',
)]
class DeployCheckCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly DeployConfigResolver $configResolver,
        private readonly GitStatusChecker $git,
        private readonly SshProfileChecker $sshChecker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('environment', InputArgument::REQUIRED, 'Zielumgebung (z. B. stage, live)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $environment = (string) $input->getArgument('environment');

        $io->title(sprintf('Revolte Deploy — Umgebungs-Check: %s', $environment));

        $allGood = true;

        // ── Konfiguration ────────────────────────────────────────────────────
        $io->section('Konfiguration');

        if (!$this->configResolver->configExists()) {
            $io->error('Keine config/revolte_deploy.yaml gefunden. Bitte zuerst revolte:deploy:doctor ausführen.');

            return Command::FAILURE;
        }

        try {
            $envConfig = $this->configResolver->getEnvironment($environment);
            $io->writeln(sprintf(' <info>✓</info> Umgebung "%s" in Konfiguration gefunden', $environment));
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        // ── SSH ──────────────────────────────────────────────────────────────
        $io->section('SSH');

        $sshProfile = $envConfig['ssh_profile'] ?? null;

        if (null === $sshProfile) {
            $io->writeln(' <error>✗</error> Kein ssh_profile in der Konfiguration für diese Umgebung');
            $allGood = false;
        } elseif (!$this->sshChecker->sshConfigExists()) {
            $io->writeln(sprintf(' <error>✗</error> SSH-Profil "%s" benötigt, aber keine ~/.ssh/config vorhanden', $sshProfile));
            $allGood = false;
        } elseif (!$this->sshChecker->profileExists($sshProfile)) {
            $io->writeln(sprintf(' <error>✗</error> SSH-Profil "%s" nicht in ~/.ssh/config gefunden', $sshProfile));
            $io->writeln('   → Empfohlener Eintrag:');
            $snippet = $this->sshChecker->buildRecommendedSnippet(
                $sshProfile,
                $this->configResolver->getProjectName(),
                $environment,
            );
            foreach (explode(PHP_EOL, $snippet) as $line) {
                $io->writeln('   ' . $line);
            }
            $allGood = false;
        } else {
            $details = $this->sshChecker->getProfileDetails($sshProfile);
            $host = $details['hostname'] ?? '(kein HostName)';
            $user = $details['user'] ?? '(kein User)';
            $io->writeln(sprintf(' <info>✓</info> SSH-Profil "%s" gefunden — %s@%s', $sshProfile, $user, $host));

            if (!isset($details['identityfile'])) {
                $io->writeln('   <comment>!</comment> Kein IdentityFile im SSH-Profil — passwortloser Login nicht sichergestellt');
            }

            // Echte Verbindung testen
            $io->write(sprintf(' <comment>…</comment> Verbinde mit %s ...', $sshProfile));
            $connected = $this->sshChecker->testConnection($sshProfile);

            if ($connected) {
                $io->writeln(sprintf("\r <info>✓</info> Verbindung zu %s erfolgreich", $sshProfile));
            } else {
                $io->writeln(sprintf("\r <error>✗</error> Verbindung zu %s fehlgeschlagen", $sshProfile));
                $identityFile = $details['identityfile'] ?? null;
                if ($identityFile !== null) {
                    $keyPath = str_replace('~', (string) ($_SERVER['HOME'] ?? '~'), $identityFile);
                    $io->writeln('   → SSH-Agent starten und Key laden:');
                    $io->writeln('     <comment>eval "$(ssh-agent -s)" && ssh-add ' . $keyPath . '</comment>');
                } else {
                    $io->writeln('   → SSH-Agent starten und Key laden:');
                    $io->writeln('     <comment>eval "$(ssh-agent -s)" && ssh-add ~/.ssh/id_ed25519</comment>');
                }
                $io->writeln('   → Danach testen: <comment>ssh ' . $sshProfile . ' "echo OK"</comment>');
                $allGood = false;
            }
        }

        // ── Git ──────────────────────────────────────────────────────────────
        $io->section('Git');

        if (!$this->git->isInstalled()) {
            $io->writeln(' <error>✗</error> Git nicht installiert');
            $allGood = false;
        } elseif (!$this->git->isRepository($this->projectRoot)) {
            $io->writeln(' <error>✗</error> Kein Git-Repository');
            $allGood = false;
        } else {
            $branch = $this->git->getCurrentBranch($this->projectRoot);
            $io->writeln(sprintf(' <info>✓</info> Branch: %s', $branch));

            $allowedBranches = $envConfig['allowed_branches'] ?? [$envConfig['branch'] ?? $branch];
            $branchAllowed = $this->git->isBranchAllowed($branch, $allowedBranches);

            if ($branchAllowed) {
                $io->writeln(sprintf(' <info>✓</info> Branch "%s" ist für "%s" erlaubt', $branch, $environment));
            } else {
                $io->writeln(sprintf(' <error>✗</error> Branch "%s" ist für "%s" nicht erlaubt', $branch, $environment));
                $io->writeln(sprintf('   Erlaubte Branches: %s', implode(', ', $allowedBranches)));
                $allGood = false;
            }

            $isClean = $this->git->isClean($this->projectRoot);

            if ($isClean) {
                $io->writeln(' <info>✓</info> Working Tree sauber');
            } else {
                $io->writeln(' <comment>!</comment> Working Tree enthält nicht committete Änderungen');
                $io->writeln('   → Bitte committen oder mit --allow-dirty arbeiten (nur Notfall)');
            }

            $hasRemote = $this->git->hasRemote($this->projectRoot);

            if (!$hasRemote) {
                $io->writeln(' <error>✗</error> Kein Git-Remote "origin" konfiguriert');
                $allGood = false;
            } else {
                $remoteUrl = $this->git->getRemoteUrl($this->projectRoot);
                $io->writeln(sprintf(' <info>✓</info> Remote "origin": %s', $remoteUrl));

                $isPushed = $this->git->isCommitPushed($this->projectRoot);

                if ($isPushed) {
                    $io->writeln(' <info>✓</info> Aktueller Commit ist auf Remote gepusht');
                } else {
                    $io->writeln(' <comment>!</comment> Lokale Commits sind noch nicht gepusht');
                    $io->writeln('   → git push vor dem Deployment ausführen');
                }
            }
        }

        // ── Remote-Pfad (Info) ───────────────────────────────────────────────
        $io->section('Zielkonfiguration');

        $remotePath = $envConfig['remote_path'] ?? null;
        $targetBranch = $envConfig['branch'] ?? '(nicht konfiguriert)';

        if ($remotePath) {
            $io->writeln(sprintf(' <info>i</info> Remote-Pfad: %s', $remotePath));
        } else {
            $io->writeln(' <comment>!</comment> Kein remote_path in der Konfiguration');
        }

        $io->writeln(sprintf(' <info>i</info> Ziel-Branch: %s', $targetBranch));
        $io->writeln(sprintf(' <info>i</info> PHP CLI: %s', $envConfig['php_cli'] ?? 'auto'));

        // ── Ergebnis ─────────────────────────────────────────────────────────
        if ($allGood) {
            $io->success(sprintf('Umgebung "%s" ist bereit für ein Deployment.', $environment));
        } else {
            $io->error(sprintf('Umgebung "%s" hat offene Probleme. Bitte die Fehler beheben.', $environment));
        }

        return $allGood ? Command::SUCCESS : Command::FAILURE;
    }
}

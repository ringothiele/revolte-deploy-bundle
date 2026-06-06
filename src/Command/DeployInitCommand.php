<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Command;

use Revolte\DeployTools\Service\DeployConfigResolver;
use Revolte\DeployTools\Service\GitStatusChecker;
use Revolte\DeployTools\Service\RemoteCommandRunner;
use Revolte\DeployTools\Service\SshProfileChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'revolte:deploy:init',
    description: 'Initialisiert eine neue Zielumgebung (erster Deploy auf leerem Server)',
)]
class DeployInitCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly DeployConfigResolver $configResolver,
        private readonly GitStatusChecker $git,
        private readonly SshProfileChecker $sshChecker,
        private readonly RemoteCommandRunner $remote,
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

        $io->title(sprintf('Revolte Deploy — Init: %s', $environment));

        try {
            $envConfig = $this->configResolver->getEnvironment($environment);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $config = $this->configResolver->load();
        $sshProfile = (string) ($envConfig['ssh_profile'] ?? '');
        $remotePath = (string) ($envConfig['remote_path'] ?? '');
        $repository = (string) ($config['git']['repository'] ?? '');

        // ── Voraussetzungen ───────────────────────────────────────────────────
        $io->section('Voraussetzungen');

        if (!$sshProfile || !$remotePath || !$repository) {
            $io->error('Konfiguration unvollständig — ssh_profile, remote_path und git.repository müssen gesetzt sein.');

            return Command::FAILURE;
        }

        if (!$this->git->isRepository($this->projectRoot)) {
            $io->error('Kein lokales Git-Repository gefunden.');

            return Command::FAILURE;
        }

        if (!$this->git->isCommitPushed($this->projectRoot)) {
            $io->warning('Nicht alle lokalen Commits sind auf Remote gepusht. Der Server klont den letzten gepushten Stand.');
        } else {
            $io->writeln(' <info>✓</info> Git-Stand ist gepusht');
        }

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

        // Remote-Pfad prüfen
        $pathNotEmpty = $this->remote->test(
            $sshProfile,
            sprintf('test -d %s && test -n "$(ls -A %s 2>/dev/null)"', escapeshellarg($remotePath), escapeshellarg($remotePath)),
        );

        if ($pathNotEmpty) {
            $io->error([
                sprintf('Remote-Pfad "%s" ist nicht leer.', $remotePath),
                'Init setzt einen leeren Zielordner voraus.',
                'Für einen erneuten Init: Ordner auf dem Server leeren und nochmal ausführen.',
            ]);

            return Command::FAILURE;
        }

        $io->writeln(sprintf(' <info>✓</info> Remote-Pfad ist leer: %s', $remotePath));

        // ── Git Clone ─────────────────────────────────────────────────────────
        $io->section('Git Clone');
        $io->writeln(sprintf('   Repository: %s', $repository));
        $io->writeln(sprintf('   Ziel:       %s', $remotePath));
        $io->newLine();

        try {
            $cloneOutput = $this->remote->capture(
                $sshProfile,
                sprintf(
                    'mkdir -p %s && git clone %s %s 2>&1',
                    escapeshellarg($remotePath),
                    escapeshellarg($repository),
                    escapeshellarg($remotePath),
                ),
                timeout: 120,
            );

            foreach (explode("\n", $cloneOutput) as $line) {
                if (trim($line)) {
                    $io->writeln('   ' . $line);
                }
            }

            $io->writeln(' <info>✓</info> Repository geklont');
        } catch (\RuntimeException $e) {
            $io->error(['Git Clone fehlgeschlagen:', $e->getMessage()]);

            return Command::FAILURE;
        }

        // ── Composer Install ──────────────────────────────────────────────────
        $io->section('Composer Install');
        $io->writeln('   Installiere Abhängigkeiten (das kann einen Moment dauern) ...');
        $io->newLine();

        try {
            $this->remote->run(
                $sshProfile,
                sprintf(
                    'cd %s && composer install --no-dev --optimize-autoloader --no-interaction 2>&1',
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
            $io->error(['Composer Install fehlgeschlagen:', $e->getMessage()]);

            return Command::FAILURE;
        }

        // ── Nächste Schritte ──────────────────────────────────────────────────
        $io->section('Nächste Schritte — manuell auf dem Server');

        $io->writeln(' Die folgenden Schritte enthalten Zugangsdaten und müssen manuell erledigt werden.');
        $io->newLine();

        $io->writeln(' <comment>1. SSH auf den Server:</comment>');
        $io->writeln(sprintf('    ssh %s', $sshProfile));
        $io->newLine();

        $io->writeln(sprintf(' <comment>2. .env.local anlegen:</comment>'));
        $io->writeln(sprintf('    nano %s/.env.local', $remotePath));
        $io->newLine();
        $io->writeln('    Mindestinhalt:');
        $io->writeln('    APP_ENV=prod');
        $io->writeln(sprintf('    APP_SECRET=%s', bin2hex(random_bytes(32))));
        $io->writeln('    DATABASE_URL="mysql://user:passwort@localhost:3306/db?serverVersion=8.0&charset=utf8mb4"');
        $io->newLine();
        $io->writeln('    <comment>Wichtig:</comment> Sonderzeichen im Passwort (@, #, / usw.) müssen URL-kodiert werden.');
        $io->writeln('    Sonst startet Contao nicht. URL-Generator:');
        $io->writeln('    https://docs.contao.org/5.x/manual/de/system/einstellungen/#konvertieren-deiner-datenbank-parameter');
        $io->newLine();

        $io->writeln(' <comment>3. Verbindung testen:</comment>');
        $io->writeln(sprintf('    php %s/vendor/bin/contao-console --version', $remotePath));
        $io->writeln('    (muss eine Versionsnummer ausgeben, kein Fehler)');
        $io->newLine();

        $io->writeln(' <comment>4. SSH-Verbindung beenden:</comment>');
        $io->writeln('    exit');
        $io->newLine();

        $io->writeln(' <comment>5. Wenn .env.local bereit ist — Deployment abschließen:</comment>');
        $io->writeln('    Sicherstellen dass alle lokalen Commits gepusht sind:');
        $io->writeln('    git push');
        $io->newLine();
        $io->writeln(sprintf('    APP_ENV=dev php vendor/bin/contao-console revolte:deploy:full %s', $environment));

        $io->newLine();

        $io->success(sprintf('Init für "%s" abgeschlossen. Bitte jetzt die manuellen Schritte ausführen.', $environment));

        return Command::SUCCESS;
    }
}

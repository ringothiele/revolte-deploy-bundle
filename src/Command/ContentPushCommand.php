<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Command;

use Revolte\DeployTools\Service\ContentPushService;
use Revolte\DeployTools\Service\DatabaseSyncService;
use Revolte\DeployTools\Service\DeployConfigResolver;
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
    name: 'revolte:deploy:content:push',
    description: 'Neue lokale Content-Datensätze auf stage/live übertragen',
)]
class ContentPushCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly DeployConfigResolver $configResolver,
        private readonly SshProfileChecker $sshChecker,
        private readonly RemoteCommandRunner $remote,
        private readonly ContentPushService $contentPush,
        private readonly DatabaseSyncService $dbSync,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('environment', InputArgument::REQUIRED, 'Zielumgebung (z. B. stage, live)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Zeigt was übertragen würde, ohne etwas auszuführen')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ohne Bestätigungsdialog ausführen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $environment = (string) $input->getArgument('environment');
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        $io->title(sprintf('Revolte Deploy — Content Push: lokal → %s%s', $environment, $dryRun ? ' [DRY RUN]' : ''));

        try {
            $envConfig = $this->configResolver->getEnvironment($environment);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $sshProfile = (string) ($envConfig['ssh_profile'] ?? '');
        $remotePath = (string) ($envConfig['remote_path'] ?? '');
        $phpCli = $envConfig['php_cli'] ?? 'auto';

        if ('auto' === $phpCli) {
            $phpCli = 'php';
        }

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

        // ── Baseline lesen ────────────────────────────────────────────────────
        $io->section('Baseline');

        try {
            $baseline = $this->contentPush->readBaseline($this->projectRoot);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $pulledAt = (int) ($baseline['pulled_at'] ?? 0);
        $baselineEnv = (string) ($baseline['environment'] ?? '?');
        $io->writeln(sprintf(
            ' <info>✓</info> Baseline gefunden: %s, gezogen am %s',
            $baselineEnv,
            date('d.m.Y H:i', $pulledAt),
        ));

        if ($baselineEnv !== $environment) {
            $io->warning(sprintf(
                'Baseline wurde von "%s" gezogen, du pushst aber nach "%s".',
                $baselineEnv,
                $environment,
            ));
        }

        // ── Pre-Push-Validierung ──────────────────────────────────────────────
        $io->section('Validierung');

        $warnings = $this->contentPush->validatePrePush($this->projectRoot, $baseline);
        if ($warnings !== []) {
            foreach ($warnings as $warning) {
                $io->writeln(' <comment>!</comment> ' . $warning);
            }
        } else {
            $io->writeln(' <info>✓</info> Keine unerwarteten lokalen Änderungen an bestehenden Datensätzen');
        }

        // ── Neue Datensätze sammeln ───────────────────────────────────────────
        $io->section('Neue Datensätze');

        $newRecords = $this->contentPush->collectNewRecords($this->projectRoot, $baseline);

        if ($newRecords === []) {
            $io->success('Keine neuen Datensätze seit dem letzten Pull — nichts zu pushen.');

            return Command::SUCCESS;
        }

        $tableRows = [];
        $totalCount = 0;

        foreach (ContentPushService::TABLES as $table) {
            if (!isset($newRecords[$table])) {
                continue;
            }
            $count = count($newRecords[$table]);
            $tableRows[] = [$table, (string) $count];
            $totalCount += $count;
        }

        $io->table(['Tabelle', 'Records'], $tableRows);
        $io->writeln(sprintf(' Gesamt: <info>%d</info> Datensätze → %s', $totalCount, $environment));

        if (isset($newRecords['tl_page'])) {
            $io->writeln(' <comment>!</comment> Neue tl_page-Einträge werden mit published=\'\' gepusht.');
        }

        if ($dryRun) {
            $io->note('Dry Run — keine Änderungen vorgenommen.');

            return Command::SUCCESS;
        }

        // ── Bestätigung ───────────────────────────────────────────────────────
        if (!$force && !$io->confirm(
            sprintf('%d Datensätze nach "%s" pushen?', $totalCount, $environment),
            false,
        )) {
            $io->writeln(' Abgebrochen.');

            return Command::SUCCESS;
        }

        // ── Remote DB-Zugangsdaten + Max-IDs ─────────────────────────────────
        $io->section('Push');

        try {
            $remoteDatabaseUrl = $this->dbSync->readRemoteDatabaseUrl($this->remote, $sshProfile, $remotePath);
            $db = $this->dbSync->parseDatabaseUrl($remoteDatabaseUrl);
        } catch (\RuntimeException $e) {
            $io->error(['Remote DATABASE_URL konnte nicht gelesen werden:', $e->getMessage()]);

            return Command::FAILURE;
        }

        $io->writeln(' Hole Remote-IDs ...');
        try {
            $remoteMaxIds = $this->contentPush->getRemoteMaxIds($sshProfile, $db);
        } catch (\RuntimeException $e) {
            $io->error(['Remote MAX(id)-Abfrage fehlgeschlagen:', $e->getMessage()]);

            return Command::FAILURE;
        }

        // ── ID-Map + Remapping ────────────────────────────────────────────────
        $idMap = $this->contentPush->buildIdMap($newRecords, $remoteMaxIds);
        $remappedRecords = $this->contentPush->remapRecords($newRecords, $idMap);

        // ── Push ──────────────────────────────────────────────────────────────
        try {
            $pushed = $this->contentPush->pushToRemote($sshProfile, $db, $remappedRecords);
            $io->writeln(sprintf(' <info>✓</info> %d Datensätze nach %s gepusht', $pushed, $environment));
        } catch (\RuntimeException $e) {
            $io->error(['Push fehlgeschlagen:', $e->getMessage()]);

            return Command::FAILURE;
        }

        // ── Remote Cache & Filesync ───────────────────────────────────────────
        $io->section('Remote Cache & Filesync');

        foreach ([
            [
                'Cache leeren ...',
                sprintf('cd %s && %s vendor/bin/contao-console cache:clear --no-interaction 2>&1', escapeshellarg($remotePath), $phpCli),
                'Cache geleert',
            ],
            [
                'Filesync ...',
                sprintf('cd %s && %s vendor/bin/contao-console contao:filesync --no-interaction 2>&1', escapeshellarg($remotePath), $phpCli),
                'Filesync abgeschlossen',
            ],
        ] as [$label, $cmd, $success]) {
            $io->writeln(' ' . $label);
            try {
                $this->remote->run($sshProfile, $cmd);
                $io->writeln(sprintf(' <info>✓</info> %s', $success));
            } catch (\RuntimeException $e) {
                $io->warning(sprintf('%s fehlgeschlagen (nicht kritisch): %s', $label, $e->getMessage()));
            }
        }

        $io->success(sprintf('Content Push nach "%s" abgeschlossen.', $environment));

        return Command::SUCCESS;
    }
}

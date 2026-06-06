<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Command;

use Revolte\DeployTools\Service\DeployConfigResolver;
use Revolte\DeployTools\Service\DeployRuleMatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'revolte:deploy:explain',
    description: 'Erklärt, warum ein Pfad in einem Deploy-Profil erlaubt oder ausgeschlossen ist',
)]
class DeployExplainCommand extends Command
{
    public function __construct(
        private readonly DeployConfigResolver $configResolver,
        private readonly DeployRuleMatcher $ruleMatcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('profile', InputArgument::REQUIRED, 'Deploy-Profil (z. B. code, full)')
            ->addArgument('path', InputArgument::REQUIRED, 'Zu prüfender Pfad (z. B. /templates/layout.html.twig)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $profileName = (string) $input->getArgument('profile');
        $path = (string) $input->getArgument('path');

        try {
            $profile = $this->configResolver->getProfile($profileName);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $result = $this->ruleMatcher->explain($path, $profile);

        $io->writeln(sprintf('<comment>Pfad:</comment>    %s', $path));
        $io->writeln(sprintf('<comment>Profil:</comment>  %s', $profileName));

        if ($result['allowed']) {
            $io->writeln('<comment>Ergebnis:</comment> <info>erlaubt</info>');
        } else {
            $io->writeln('<comment>Ergebnis:</comment> <error>ausgeschlossen</error>');
        }

        $io->newLine();
        $io->writeln('<comment>Regelauswertung:</comment>');

        foreach ($result['trace'] as $entry) {
            $indicator = $entry['matched'] ? ($entry['active'] ? '→' : '·') : ' ';
            $ruleText = $entry['rule'];

            if ($entry['active']) {
                $io->writeln(sprintf('  <info>%s</info> %s  <comment>← letzte passende Regel</comment>', $indicator, $ruleText));
            } elseif ($entry['matched']) {
                $io->writeln(sprintf('  %s %s', $indicator, $ruleText));
            } else {
                $io->writeln(sprintf('  <fg=gray>  %s</>', $ruleText));
            }
        }

        return Command::SUCCESS;
    }
}

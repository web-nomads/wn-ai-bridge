<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WebNomads\WnAiBridge\Agent\AgentSettings;
use WebNomads\WnAiBridge\Agent\VisitRepository;

/**
 * Deletes crawler and referral visits older than the retention period; schedule daily
 */
final class PurgeVisitsCommand extends Command
{
    public function __construct(
        private readonly VisitRepository $visits,
        private readonly AgentSettings $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Deletes the visits of AI crawlers and of visitors referred by AI platforms that are older than the retention period (extension configuration "agentRetentionDays", default 90 days, 0 keeps everything).')
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Retention in days, default from the extension configuration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = $input->getOption('days') !== null ? (int)$input->getOption('days') : $this->settings->retentionDays();
        if ($days <= 0) {
            $output->writeln('Retention is disabled, no visits purged.');

            return Command::SUCCESS;
        }
        $deleted = $this->visits->purgeOlderThan(time() - $days * 86400);
        $output->writeln(sprintf('Deleted %d visits older than %d days.', $deleted, $days));

        return Command::SUCCESS;
    }
}

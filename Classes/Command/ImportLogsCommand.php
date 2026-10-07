<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use WebNomads\WnAiBridge\Agent\AgentSettings;
use WebNomads\WnAiBridge\Agent\LogLineParser;
use WebNomads\WnAiBridge\Agent\VisitLogger;
use WebNomads\WnAiBridge\Agent\VisitRepository;

/**
 * Stores AI crawler and AI referral visits from web server access logs, like the logging middleware
 */
final class ImportLogsCommand extends Command
{
    public function __construct(
        private readonly LogLineParser $parser,
        private readonly VisitLogger $visitLogger,
        private readonly VisitRepository $visits,
        private readonly AgentSettings $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Reads web server access logs in the combined format (plain or .gz) and stores the visits of AI crawlers to llms.txt, the Markdown versions and pages, and of visitors referred by AI platforms, like the logging middleware does. Lines already imported are skipped. Without a virtual host in the log lines, --host names the website.')
            ->addArgument('files', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Log files')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host of the website, e.g. www.example.ch; with virtual hosts in the log: only this host')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Skip lines before this date (YYYY-MM-DD)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = strtolower(trim((string)$input->getOption('host')));
        $since = (string)$input->getOption('since');
        $sinceTime = $since !== '' ? (int)strtotime($since . ' 00:00:00') : 0;

        /** @var array<string, Site|null> $sites */
        $sites = [];
        foreach ((array)$input->getArgument('files') as $file) {
            $file = (string)$file;
            $gzip = str_ends_with($file, '.gz');
            $handle = $gzip ? @gzopen($file, 'rb') : @fopen($file, 'rb');
            if ($handle === false) {
                $output->writeln('<error>Cannot read ' . $file . '</error>');

                return Command::FAILURE;
            }
            $counts = ['lines' => 0, 'crawler' => 0, 'referral' => 0, 'known' => 0, 'skipped' => 0];
            while (($line = $gzip ? gzgets($handle) : fgets($handle)) !== false) {
                $counts['lines']++;
                $record = $this->parser->parse($line);
                $type = $record !== null ? VisitLogger::type($record['path'], null) : null;
                if ($record === null || $type === null || $record['time'] < $sinceTime || $record['method'] !== 'GET') {
                    $counts['skipped']++;
                    continue;
                }
                if ($record['host'] === '') {
                    $record['host'] = $host;
                } elseif ($host !== '' && $record['host'] !== $host) {
                    $counts['skipped']++;
                    continue;
                }
                if ($record['host'] === '') {
                    $output->writeln('<error>The log has no virtual host; name the website with --host</error>');

                    return Command::FAILURE;
                }
                $hash = LogLineParser::hash($record);
                if ($this->visits->hasLine($hash)) {
                    $counts['known']++;
                    continue;
                }
                // the settings of the site serving the host, as for live visits
                $site = array_key_exists($record['host'], $sites) ? $sites[$record['host']] : ($sites[$record['host']] = $this->settings->siteOfHost($record['host']));
                $kind = $this->visitLogger->log(
                    $site?->getIdentifier() ?? '',
                    $record['host'],
                    $record['path'],
                    $type,
                    $record['status'],
                    $record['userAgent'],
                    $record['referrer'],
                    $record['ip'],
                    $record['time'],
                    $site?->getConfiguration() ?? [],
                    VisitLogger::SOURCE_IMPORT,
                    $hash,
                );
                if ($kind !== '') {
                    $counts[$kind]++;
                }
            }
            $gzip ? gzclose($handle) : fclose($handle);
            $output->writeln(sprintf('%s: %d lines, %d crawler visits, %d referred visits, %d already imported, %d skipped', $file, $counts['lines'], $counts['crawler'], $counts['referral'], $counts['known'], $counts['skipped']));
        }

        return Command::SUCCESS;
    }
}

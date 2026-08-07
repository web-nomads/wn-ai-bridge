<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Command;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\Exception\InvalidPathException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\PathUtility;
use WebNomads\WnAiBridge\Repository\PageRepository;
use WebNomads\WnAiBridge\Service\MarkdownConverterService;
use WebNomads\WnAiBridge\Service\UrlGeneratorService;

/**
 * Bulk-exports the Markdown representation of every navigable page into files
 * on disk, one site at a time. Each page is fetched through its own ".md"
 * frontend URL so the export mirrors exactly what a visitor (or crawler) would
 * receive from the site.
 */
final class DownloadMarkdownCommand extends Command
{
    private const DEFAULT_OUTPUT_DIR = 'var/markdown-export';
    private const HTTP_TIMEOUT_SECONDS = 30;
    private const MAX_FILENAME_LENGTH = 100;

    /**
     * @var array<string, bool> Filenames already used within the current site,
     *                          used to resolve collisions with a numeric suffix.
     */
    private array $reservedFilenames = [];

    private readonly Client $httpClient;

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly PageRepository $pageRepository,
        private readonly MarkdownConverterService $markdownConverter,
        private readonly UrlGeneratorService $urlGenerator,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
        $this->httpClient = new Client([
            'timeout' => self::HTTP_TIMEOUT_SECONDS,
            'http_errors' => false,
        ]);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Download TYPO3 pages as Markdown files')
            ->addArgument(
                'siteIdentifier',
                InputArgument::OPTIONAL,
                'Site identifier to process (processes all sites if not specified)'
            )
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Base directory for downloads', self::DEFAULT_OUTPUT_DIR)
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of pages to process', 0)
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'File naming format: slug, uid, or title', 'slug')
            ->addOption('include-metadata', 'm', InputOption::VALUE_NONE, 'Include metadata as YAML frontmatter')
            ->addOption('flat', null, InputOption::VALUE_NONE, 'Save all files in single directory instead of organized by site')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be downloaded without actually doing it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $format = (string)$input->getOption('format');
        $dryRun = (bool)$input->getOption('dry-run');

        $io->title('Download TYPO3 Pages as Markdown');

        if ($dryRun) {
            $io->note('DRY RUN MODE - No files will be created');
        }

        if (!in_array($format, ['slug', 'uid', 'title'], true)) {
            $io->error('Invalid format option. Must be one of: slug, uid, title');
            return Command::FAILURE;
        }

        $outputDirOption = (string)$input->getOption('output-dir');
        $baseDir = $this->resolveBaseDirectory($outputDirOption);
        if ($baseDir === null) {
            $io->error(sprintf('Failed to create output directory: %s', $outputDirOption));
            return Command::FAILURE;
        }

        $io->writeln(sprintf('Output directory: <info>%s</info>', $baseDir));

        $totals = ['processed' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($this->resolveSites($input) as $site) {
            $io->section(sprintf('Processing site: %s', $site->getIdentifier()));

            try {
                $siteTotals = $this->exportSite($io, $site, $baseDir, [
                    'limit' => (int)$input->getOption('limit'),
                    'overwrite' => (bool)$input->getOption('overwrite'),
                    'format' => $format,
                    'includeMetadata' => (bool)$input->getOption('include-metadata'),
                    'flat' => (bool)$input->getOption('flat'),
                    'dryRun' => $dryRun,
                ]);

                foreach ($siteTotals as $key => $value) {
                    $totals[$key] += $value;
                }
            } catch (\Exception $e) {
                $io->error(sprintf('Error processing site: %s', $e->getMessage()));
                $this->logger->error('Error processing site', [
                    'site' => $site->getIdentifier(),
                    'exception' => $e,
                ]);
            }
        }

        $io->success(sprintf(
            'Processed %d pages successfully, %d skipped, %d errors',
            $totals['processed'],
            $totals['skipped'],
            $totals['errors']
        ));

        return Command::SUCCESS;
    }

    /**
     * @return iterable<Site>
     */
    private function resolveSites(InputInterface $input): iterable
    {
        $siteIdentifier = $input->getArgument('siteIdentifier');

        if ($siteIdentifier) {
            return [$this->siteFinder->getSiteByIdentifier((string)$siteIdentifier)];
        }

        return $this->siteFinder->getAllSites();
    }

    /**
     * Export a single site and return its processed/skipped/errors counters.
     *
     * @param array{limit: int, overwrite: bool, format: string, includeMetadata: bool, flat: bool, dryRun: bool} $options
     * @return array{processed: int, skipped: int, errors: int}
     */
    private function exportSite(SymfonyStyle $io, Site $site, string $baseDir, array $options): array
    {
        $counters = ['processed' => 0, 'skipped' => 0, 'errors' => 0];

        $pages = $this->pageRepository->findNavigationByParent($site->getRootPageId());
        if ($pages === []) {
            $io->warning('No visible pages found in this site');
            return $counters;
        }

        $io->writeln(sprintf('Found %d visible pages', count($pages)));

        $pages = $options['limit'] > 0 ? array_slice($pages, 0, $options['limit']) : $pages;

        $targetDir = $options['flat'] ? $baseDir : $baseDir . '/' . $site->getIdentifier();
        if (!$options['dryRun'] && !$options['flat']) {
            $this->createDirectory($targetDir);
        }

        // Filename collisions are tracked per site only.
        $this->reservedFilenames = [];

        $progressBar = $io->createProgressBar(count($pages));
        $progressBar->start();

        foreach ($pages as $page) {
            try {
                $filePath = $targetDir . '/' . $this->buildFilename($page, $options['format']) . '.md';

                if (!$options['overwrite'] && file_exists($filePath)) {
                    $counters['skipped']++;
                    continue;
                }

                if ($options['dryRun']) {
                    $counters['processed']++;
                    continue;
                }

                $markdown = $this->downloadMarkdown($site, $page);
                if ($markdown === null) {
                    $counters['skipped']++;
                    continue;
                }

                if ($options['includeMetadata']) {
                    $markdown = $this->prependFrontmatter($markdown, $site, $page);
                }

                file_put_contents($filePath, $markdown);
                $counters['processed']++;
            } catch (\Exception $e) {
                $io->newLine();
                $io->error(sprintf(
                    'Error processing page %d (%s): %s',
                    $page['uid'],
                    $page['title'],
                    $e->getMessage()
                ));

                $this->logger->error('Error processing page', [
                    'page_uid' => $page['uid'],
                    'page_title' => $page['title'],
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
                $counters['errors']++;
            } finally {
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $io->newLine(2);

        return $counters;
    }

    /**
     * Turn the (possibly relative) target path into an absolute, existing
     * directory. Returns null when the directory can neither be found nor
     * created.
     */
    private function resolveBaseDirectory(string $dir): ?string
    {
        if (!PathUtility::isAbsolutePath($dir)) {
            $dir = Environment::getProjectPath() . '/' . $dir;
        }

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        return $dir;
    }

    private function createDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new InvalidPathException(sprintf('Failed to create directory: %s', $path));
        }
    }

    /**
     * Retrieve the rendered Markdown of a page via its public ".md" URL.
     * Returns null for protected (403), failed or empty responses.
     *
     * @param array<string, mixed> $page
     */
    private function downloadMarkdown(Site $site, array $page): ?string
    {
        try {
            $uri = (string)$site->getRouter()->generateUri((int)$page['uid']);
            $markdownUrl = rtrim($uri, '/') . '.md';

            $response = $this->httpClient->request('GET', $markdownUrl);
            $statusCode = $response->getStatusCode();

            if ($statusCode === 403) {
                $this->logger->info('Skipping protected page (403 Forbidden)', [
                    'page_uid' => $page['uid'],
                    'title' => $page['title'],
                    'url' => $markdownUrl,
                ]);
                return null;
            }

            if ($statusCode !== 200) {
                $this->logger->warning('Failed to fetch page with status ' . $statusCode, [
                    'page_uid' => $page['uid'],
                    'title' => $page['title'],
                    'url' => $markdownUrl,
                    'status' => $statusCode,
                ]);
                return null;
            }

            $markdown = $response->getBody()->getContents();

            if (trim($markdown) === '') {
                $this->logger->warning('Empty markdown content for page', [
                    'page_uid' => $page['uid'],
                    'url' => $markdownUrl,
                ]);
                return null;
            }

            return $markdown;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fetch page content', [
                'page_uid' => $page['uid'],
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Build a unique filename (without extension) for a page and reserve it.
     *
     * @param array<string, mixed> $page
     */
    private function buildFilename(array $page, string $format): string
    {
        $base = $format === 'uid'
            ? 'page-' . $page['uid']
            : $this->slugify((string)$page['title']);

        $candidate = $base;
        $suffix = 2;
        while (isset($this->reservedFilenames[$candidate])) {
            $candidate = $base . '-' . $suffix++;
        }

        $this->reservedFilenames[$candidate] = true;

        return $candidate;
    }

    /**
     * Reduce an arbitrary string to a lowercase, hyphen-separated slug that is
     * safe to use as a filename.
     */
    private function slugify(string $value): string
    {
        $value = mb_strtolower($value);
        $value = (string)preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim($value, '-');

        if (mb_strlen($value) > self::MAX_FILENAME_LENGTH) {
            $value = mb_substr($value, 0, self::MAX_FILENAME_LENGTH);
        }

        return $value !== '' ? $value : 'page-' . time();
    }

    /**
     * Prepend a YAML frontmatter block describing the page to its Markdown.
     *
     * @param array<string, mixed> $page
     */
    private function prependFrontmatter(string $content, Site $site, array $page): string
    {
        $uri = $site->getRouter()->generateUri((int)$page['uid']);

        $lines = [
            '---',
            'uid: ' . $page['uid'],
            'title: "' . addslashes((string)$page['title']) . '"',
        ];

        if (!empty($page['description'])) {
            $lines[] = 'description: "' . addslashes((string)$page['description']) . '"';
        }

        $lines[] = 'url: "' . $uri . '"';
        $lines[] = 'site: "' . $site->getIdentifier() . '"';
        $lines[] = 'exported: "' . date('Y-m-d\TH:i:s\Z') . '"';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '';

        return implode("\n", $lines) . $content;
    }
}

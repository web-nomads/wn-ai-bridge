<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Service;

/**
 * Service for converting HTML to Markdown
 * Handles HTML to Markdown conversion with proper cleaning
 */
class MarkdownConverterService
{
    private readonly HtmlCleanerService $htmlCleanerService;
    private readonly ConfigurationService $configurationService;

    public function __construct(
        ?HtmlCleanerService $htmlCleanerService = null,
        ?ConfigurationService $configurationService = null
    ) {
        $this->htmlCleanerService = $htmlCleanerService ?? \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(HtmlCleanerService::class);
        $this->configurationService = $configurationService ?? \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ConfigurationService::class);
    }

    /**
     * Convert HTML content to clean Markdown
     * Strips images and media but preserves links for SEO
     */
    public function convertHtmlToMarkdown(string $html): string
    {
        if (empty($html)) {
            return '';
        }

        $siteUrl = $this->configurationService->getSiteUrl();

        // Use string literal for class name to avoid dependency issues during DI
        $converterClass = 'League\HTMLToMarkdown\HtmlConverter';

        if (!class_exists($converterClass)) {
            $autoloader = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath('wn_ai_bridge') . 'vendor/autoload.php';
            if (file_exists($autoloader)) {
                require_once $autoloader;
            }
        }

        if (!class_exists($converterClass)) {
            // Fallback: Basic manual conversion if library is missing
            $html = $this->htmlCleanerService->cleanTypo3Html($html);
            $markdown = $html;

            // Convert Headers with proper spacing and trimming
            $markdown = preg_replace_callback('/<h1[^>]*>(.*?)<\/h1>/is', fn($m) => "\n# " . preg_replace('/\s+/', ' ', trim(strip_tags($m[1]))) . "\n\n", $markdown);
            $markdown = preg_replace_callback('/<h2[^>]*>(.*?)<\/h2>/is', fn($m) => "\n## " . preg_replace('/\s+/', ' ', trim(strip_tags($m[1]))) . "\n\n", $markdown);
            $markdown = preg_replace_callback('/<h3[^>]*>(.*?)<\/h3>/is', fn($m) => "\n### " . preg_replace('/\s+/', ' ', trim(strip_tags($m[1]))) . "\n\n", $markdown);
            $markdown = preg_replace_callback('/<h4[^>]*>(.*?)<\/h4>/is', fn($m) => "\n#### " . preg_replace('/\s+/', ' ', trim(strip_tags($m[1]))) . "\n\n", $markdown);
            $markdown = preg_replace_callback('/<h5[^>]*>(.*?)<\/h5>/is', fn($m) => "\n##### " . preg_replace('/\s+/', ' ', trim(strip_tags($m[1]))) . "\n\n", $markdown);
            $markdown = preg_replace_callback('/<h6[^>]*>(.*?)<\/h6>/is', fn($m) => "\n###### " . preg_replace('/\s+/', ' ', trim(strip_tags($m[1]))) . "\n\n", $markdown);

            // Convert Links and ensure they are absolute and have .md extension
            $markdown = preg_replace_callback('/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', function ($m) use ($siteUrl) {
                $url = $this->processMarkdownUrl($m[1], $siteUrl);
                return '[' . strip_tags($m[2]) . '](' . $url . ')';
            }, $markdown);

            // Convert Paragraphs
            $markdown = preg_replace_callback('/<p[^>]*>(.*?)<\/p>/is', fn($m) => trim(strip_tags($m[1])) . "\n\n", $markdown);

            // Strip remaining tags
            $markdown = strip_tags($markdown);

            // Normalize multiple newlines
            $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);

            // Decode entities
            $markdown = html_entity_decode($markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return trim($markdown);
        }

        $html = $this->htmlCleanerService->cleanTypo3Html($html);

        $converter = new $converterClass([
            'strip_tags' => true,
            'remove_nodes' => 'img picture figure source video audio iframe script style footer aside',
            'preserve_comments' => false,
            'hard_break' => true,
            'strip_placeholder_links' => false,
            'use_autolinks' => false,
            'header_style' => 'atx', // Use ATX style headers (e.g., ## Header)
        ]);

        try {
            $markdown = $converter->convert($html);

            // Manually make links absolute and append .md in library output
            $markdown = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function ($m) use ($siteUrl) {
                $url = $this->processMarkdownUrl($m[2], $siteUrl);
                return '[' . $m[1] . '](' . $url . ')';
            }, $markdown);

            $markdown = html_entity_decode($markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // Post-process: Remove leading spaces from each line and normalize newlines
            $lines = explode("\n", $markdown);
            $processedLines = [];
            foreach ($lines as $line) {
                $trimmedLine = trim($line);
                if ($trimmedLine !== '') {
                    $processedLines[] = $trimmedLine;
                }
            }
            // Join with double newlines for a clear separation between blocks
            // as requested by the user ("je einen Leerschlag zwischen jeden Block")
            $markdown = implode("\n\n", $processedLines);

            // Final pass: ensure headers are clean (sometimes library adds trailing space)
            $markdown = preg_replace('/^(#+ .*?) +$/m', '$1', $markdown);

            return trim($markdown);
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Process internal links to be absolute and ensure they have .md extension
     * for seamless markdown navigation.
     */
    private function processMarkdownUrl(string $url, string $siteUrl): string
    {
        // Make absolute if relative
        if (str_starts_with($url, '/')) {
            $url = $siteUrl . $url;
        }

        // Only process internal links that don't already have an extension
        if (str_starts_with($url, $siteUrl)) {
            $parts = parse_url($url);
            $path = $parts['path'] ?? '';

            if ($path !== '' && $path !== '/') {
                $extension = pathinfo($path, PATHINFO_EXTENSION);
                if (empty($extension)) {
                    $newPath = rtrim($path, '/') . '.md';

                    $url = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
                    if (isset($parts['port'])) {
                        $url .= ':' . $parts['port'];
                    }
                    $url .= $newPath;
                    if (isset($parts['query'])) {
                        $url .= '?' . $parts['query'];
                    }
                    if (isset($parts['fragment'])) {
                        $url .= '#' . $parts['fragment'];
                    }
                }
            }
        }

        return $url;
    }
}

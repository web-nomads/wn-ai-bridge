<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Service;

use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WebNomads\WnAiBridge\Repository\PageRepository;

/**
 * Assembles the llms-full.txt document for a site: not a list of links like
 * llms.txt, but the readable content of every page in a single file.
 *
 * The layout is the one a full document is validated against: an H1 title, a
 * blockquote summary, then H2/H3 sections that follow the page tree. Each page
 * contributes one section whose body is the Markdown of its content elements,
 * with the headings inside it pushed below the section heading so the document
 * keeps exactly one H1 and never skips a level.
 */
class LlmsFullTxtGeneratorService
{
    /**
     * Upper bound on the pages that go into one document. A single request
     * renders every one of them, so a runaway page tree must not turn the
     * endpoint into a denial of service against its own site.
     */
    private const MAX_PAGES = 500;

    /**
     * The deepest heading level Markdown has.
     */
    private const MAX_HEADING_LEVEL = 6;

    private readonly ConfigurationService $configurationService;
    private readonly PageRepository $pageRepository;
    private readonly PageContentRenderer $pageContentRenderer;
    private readonly MarkdownConverterService $markdownConverter;
    private readonly UrlGeneratorService $urlGenerator;

    /**
     * Resolved on first use rather than in the constructor: SiteFinder is a
     * readonly class and cannot be stood in for, so resolveSite() is the seam.
     */
    private ?SiteFinder $siteFinder;

    /**
     * Language and page budget of the document currently being assembled. Set
     * once per generateLlmsFullTxt() call so the recursion does not have to
     * thread them through every frame.
     */
    private int $languageUid = 0;
    private int $pageBudget = self::MAX_PAGES;

    public function __construct(
        ?ConfigurationService $configurationService = null,
        ?PageRepository $pageRepository = null,
        ?PageContentRenderer $pageContentRenderer = null,
        ?MarkdownConverterService $markdownConverter = null,
        ?UrlGeneratorService $urlGenerator = null,
        ?SiteFinder $siteFinder = null
    ) {
        $this->configurationService = $configurationService ?? GeneralUtility::makeInstance(ConfigurationService::class);
        $this->pageRepository = $pageRepository ?? GeneralUtility::makeInstance(PageRepository::class);
        $this->pageContentRenderer = $pageContentRenderer ?? GeneralUtility::makeInstance(PageContentRenderer::class);
        $this->markdownConverter = $markdownConverter ?? GeneralUtility::makeInstance(MarkdownConverterService::class);
        $this->urlGenerator = $urlGenerator ?? GeneralUtility::makeInstance(UrlGeneratorService::class);
        $this->siteFinder = $siteFinder;
    }

    /**
     * The site a page belongs to.
     */
    protected function resolveSite(int $pageId): Site
    {
        $this->siteFinder ??= GeneralUtility::makeInstance(SiteFinder::class);

        return $this->siteFinder->getSiteByPageId($pageId);
    }

    /**
     * Build the complete llms-full.txt document for the given page context.
     */
    public function generateLlmsFullTxt(int $currentPageId, int $languageUid = 0): string
    {
        if (!$this->configurationService->isLlmsFullTxtEnabled()) {
            return "# llms-full.txt generation is disabled\n";
        }

        if (!$this->configurationService->isEnabled()) {
            return "# LLMS.TXT generation is disabled for this site\n";
        }

        $site = $this->resolveSite($currentPageId);
        $rootPageId = $site->getRootPageId();

        try {
            $siteLanguage = $site->getLanguageById($languageUid);
        } catch (\Exception $e) {
            $siteLanguage = $site->getDefaultLanguage();
        }

        $this->languageUid = $languageUid;
        $this->pageBudget = self::MAX_PAGES;

        $homePage = $this->pageRepository->findById($rootPageId, $languageUid);

        $lines = [];
        $this->appendHeader($lines, $homePage);
        $this->appendAbout($lines);
        $this->appendPage($lines, $homePage, 2);
        $this->appendChildren($lines, $rootPageId, $siteLanguage, $this->configurationService->getMaxDepth(), 2);
        $this->appendAdditionalInfo($lines);
        $this->appendTruncationNote($lines);

        return implode("\n", $lines) . "\n";
    }

    /**
     * Shift every ATX heading in a Markdown block so its shallowest heading sits
     * at $minLevel, keeping the relative structure and clamping at H6.
     *
     * Content elements start at whatever level their template uses, so a plain
     * "demote by one" would either collide with the section heading above them or
     * leave a gap in the outline.
     */
    public static function nestHeadings(string $markdown, int $minLevel): string
    {
        $shallowest = self::shallowestHeadingLevel($markdown);
        if ($shallowest === null) {
            return $markdown;
        }

        $shift = $minLevel - $shallowest;
        if ($shift === 0) {
            return $markdown;
        }

        $lines = [];
        $inFence = false;

        foreach (explode("\n", $markdown) as $line) {
            if (self::isFenceLine($line)) {
                $inFence = !$inFence;
                $lines[] = $line;
                continue;
            }

            if (!$inFence && preg_match('/^(#{1,6})(\s+)(.*)$/', $line, $matches) === 1) {
                $level = min(self::MAX_HEADING_LEVEL, max(1, strlen($matches[1]) + $shift));
                $line = str_repeat('#', $level) . $matches[2] . $matches[3];
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * The smallest ATX heading level used outside of code fences, or null when
     * the block carries no heading at all.
     */
    private static function shallowestHeadingLevel(string $markdown): ?int
    {
        $shallowest = null;
        $inFence = false;

        foreach (explode("\n", $markdown) as $line) {
            if (self::isFenceLine($line)) {
                $inFence = !$inFence;
                continue;
            }

            if (!$inFence && preg_match('/^(#{1,6})\s+\S/', $line, $matches) === 1) {
                $level = strlen($matches[1]);
                $shallowest = $shallowest === null ? $level : min($shallowest, $level);
            }
        }

        return $shallowest;
    }

    private static function isFenceLine(string $line): bool
    {
        return preg_match('/^\s{0,3}(```|~~~)/', $line) === 1;
    }

    /**
     * Append the "# Title" and "> Description" block the document opens with,
     * preferring the configured overrides over the home page's own metadata.
     *
     * @param list<string> $lines
     * @param array<string, mixed> $homePage
     */
    private function appendHeader(array &$lines, array $homePage): void
    {
        $title = $this->configurationService->getTitleOverride() ?: ($homePage['title'] ?? '');
        $title = trim((string)preg_replace('/\s+/', ' ', trim((string)$title)));
        $lines[] = '# ' . ($title !== '' ? $title : $this->configurationService->getSiteName());

        $description = $this->configurationService->getDescriptionOverride() ?: ($homePage['description'] ?? '');
        $description = trim((string)preg_replace('/\s+/', ' ', trim((string)$description)));

        $lines[] = '';
        // The summary is part of the expected layout, so it is written even when
        // the site configured none.
        $lines[] = '> ' . ($description !== ''
            ? $description
            : 'The full content of this site in one document, generated for language models.');
    }

    /**
     * Append the optional topics and contact metadata as the document's first
     * section, so the H1 is still followed directly by the summary.
     *
     * @param list<string> $lines
     */
    private function appendAbout(array &$lines): void
    {
        $keywords = $this->configurationService->getKeywords();
        $contactEmail = $this->configurationService->getContactEmail();

        if ($keywords === [] && empty($contactEmail)) {
            return;
        }

        $lines[] = '';
        $lines[] = '## About This Site';

        if ($keywords !== []) {
            $lines[] = '';
            $lines[] = '**Topics:** ' . implode(', ', $keywords);
        }

        if (!empty($contactEmail)) {
            $lines[] = '';
            $lines[] = '**Contact:** ' . $contactEmail;
        }
    }

    /**
     * Append one page as a section: its heading, its description, the URL it was
     * taken from and the Markdown of its content elements.
     *
     * @param list<string> $lines
     * @param array<string, mixed> $page
     */
    private function appendPage(array &$lines, array $page, int $level): void
    {
        if ($page === [] || $this->pageBudget <= 0) {
            return;
        }

        $title = trim((string)preg_replace(
            '/\s+/',
            ' ',
            trim((string)(($page['title'] ?? '') ?: ($page['nav_title'] ?? '')))
        ));
        if ($title === '') {
            return;
        }

        $this->pageBudget--;
        $level = min($level, self::MAX_HEADING_LEVEL);

        $lines[] = '';
        $lines[] = str_repeat('#', $level) . ' ' . $title;

        $description = trim((string)preg_replace(
            '/\s+/',
            ' ',
            trim((string)(($page['description'] ?? '') ?: ($page['abstract'] ?? '')))
        ));
        if ($description !== '') {
            $lines[] = '';
            $lines[] = '> ' . $description;
        }

        $url = $this->pageUrl($page);
        if ($url !== '') {
            $lines[] = '';
            $lines[] = 'Source: ' . $url;
        }

        $markdown = $this->renderPage((int)$page['uid'], $level);
        if ($markdown !== '') {
            $lines[] = '';
            $lines[] = $markdown;
        }
    }

    /**
     * Append the sub-pages of a page, one heading level deeper each time, until
     * the site's configured navigation depth is reached.
     *
     * @param list<string> $lines
     */
    private function appendChildren(
        array &$lines,
        int $parentUid,
        SiteLanguage $siteLanguage,
        int $maxDepth,
        int $level,
        int $depth = 1
    ): void {
        if ($depth > $maxDepth || $this->pageBudget <= 0) {
            return;
        }

        foreach ($this->pageRepository->findNavigationByParentWithFallback($parentUid, $siteLanguage) as $page) {
            if ($this->pageBudget <= 0) {
                return;
            }

            $this->appendPage($lines, $page, $level);
            $this->appendChildren($lines, (int)$page['uid'], $siteLanguage, $maxDepth, $level + 1, $depth + 1);
        }
    }

    /**
     * The Markdown of a page's content elements, nested below its section
     * heading. Empty when the page has no readable content.
     */
    private function renderPage(int $pageId, int $level): string
    {
        try {
            $html = $this->pageContentRenderer->render($pageId, $this->languageUid, false);
        } catch (\Throwable $e) {
            // One unrenderable page must not cost the whole document.
            return '';
        }

        if (trim($html) === '') {
            return '';
        }

        $markdown = trim($this->markdownConverter->convertHtmlToMarkdown($html));
        if ($markdown === '') {
            return '';
        }

        return self::nestHeadings($markdown, min($level + 1, self::MAX_HEADING_LEVEL));
    }

    /**
     * The HTML URL a section was taken from. Empty when the page cannot be
     * routed, so the section is written without a source line.
     *
     * @param array<string, mixed> $page
     */
    private function pageUrl(array $page): string
    {
        try {
            return $this->urlGenerator->generateHtmlUrl($page);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Append the editor's free-form additional information as a closing section.
     *
     * @param list<string> $lines
     */
    private function appendAdditionalInfo(array &$lines): void
    {
        $additionalInfo = trim((string)$this->configurationService->getAdditionalInfo());
        if ($additionalInfo === '') {
            return;
        }

        $lines[] = '';
        $lines[] = '## Additional Information';
        $lines[] = '';
        $lines[] = self::nestHeadings($additionalInfo, 3);
    }

    /**
     * Say so when the page budget cut the document short — a silently shortened
     * document reads as a complete one.
     *
     * @param list<string> $lines
     */
    private function appendTruncationNote(array &$lines): void
    {
        if ($this->pageBudget > 0) {
            return;
        }

        $lines[] = '';
        $lines[] = '## Note';
        $lines[] = '';
        $lines[] = 'This document was truncated after ' . self::MAX_PAGES
            . ' pages. The remaining pages are listed in llms.txt and are available individually as Markdown.';
    }
}

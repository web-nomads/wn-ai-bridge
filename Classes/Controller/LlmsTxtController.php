<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Controller;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Attribute\AsAllowedCallable;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use WebNomads\WnAiBridge\Repository\PageRepository;
use WebNomads\WnAiBridge\Service\ConfigurationService;
use WebNomads\WnAiBridge\Service\LlmsTxtGeneratorService;
use WebNomads\WnAiBridge\Service\MarkdownConverterService;
use WebNomads\WnAiBridge\Service\UrlGeneratorService;

/**
 * Controller for serving llms.txt content via TypoScript PAGE object
 *
 * This controller is kept thin - it only handles HTTP request/response
 * and delegates all business logic to dedicated service classes.
 */
class LlmsTxtController
{
    public ?ContentObjectRenderer $cObj = null;

    private readonly LlmsTxtGeneratorService $llmsTxtGenerator;
    private readonly ConfigurationService $configurationService;
    private readonly MarkdownConverterService $markdownConverter;
    private readonly PageRepository $pageRepository;
    private readonly UrlGeneratorService $urlGenerator;
    private readonly CacheManager $cacheManager;

    public function __construct(
        ?LlmsTxtGeneratorService $llmsTxtGenerator = null,
        ?ConfigurationService $configurationService = null,
        ?MarkdownConverterService $markdownConverter = null,
        ?PageRepository $pageRepository = null,
        ?UrlGeneratorService $urlGenerator = null,
        ?CacheManager $cacheManager = null
    ) {
        $this->llmsTxtGenerator = $llmsTxtGenerator ?? GeneralUtility::makeInstance(LlmsTxtGeneratorService::class);
        $this->configurationService = $configurationService ?? GeneralUtility::makeInstance(ConfigurationService::class);
        $this->markdownConverter = $markdownConverter ?? GeneralUtility::makeInstance(MarkdownConverterService::class);
        $this->pageRepository = $pageRepository ?? GeneralUtility::makeInstance(PageRepository::class);
        $this->urlGenerator = $urlGenerator ?? GeneralUtility::makeInstance(UrlGeneratorService::class);
        $this->cacheManager = $cacheManager ?? GeneralUtility::makeInstance(CacheManager::class);
    }

    /**
     * Generate llms.txt content for TypoScript USER object
     */
    #[AsAllowedCallable]
    public function generateAction(string $content, array $conf): string
    {
        try {
            $currentPageId = $this->configurationService->getCurrentPageId();
            $languageUid = $this->getLanguageUid();
            return $this->llmsTxtGenerator->generateLlmsTxt($currentPageId, $languageUid);
        } catch (\Exception $e) {
            // Return error message in llms.txt format
            return "llmstxt: 1.0\nsite: " . $this->configurationService->getSiteUrl() . "\nerror: Failed to generate content\n";
        }
    }

    /**
     * Render current page as Markdown by leveraging TYPO3's frontend rendering
     */
    #[AsAllowedCallable]
    public function renderPageAsMarkdown(string $content, array $conf): string
    {
        // If content is already present (e.g. from TypoScript), it might be a double execution
        if (!empty($content) && !str_starts_with(trim($content), '# Error')) {
            return $content;
        }

        $pageId = $this->configurationService->getCurrentPageId();
        $languageUid = $this->getLanguageUid();
        $isFallback = $this->configurationService->isFallbackHtmlEnabled();
        $cacheEnabled = $this->configurationService->isCacheEnabled();
        $isDebug = $this->configurationService->isDebugEnabled();

        // Clear local cache directory if caching is disabled
        if (!$cacheEnabled) {
            $cacheDir = GeneralUtility::getFileAbsFileName('EXT:wn_ai_bridge/var/cache');
            if (is_dir($cacheDir)) {
                GeneralUtility::rmdir($cacheDir, true);
            }
        }

        // Cache handling (only if debug is disabled to ensure fresh info during debugging)
        $cacheIdentifier = 'markdown_' . $pageId . '_' . $languageUid . '_' . ($isFallback ? 'fallback' : 'default');
        if ($cacheEnabled && !$isDebug) {
            $cache = $this->cacheManager->getCache('wn_ai_bridge_markdown');
            $cachedContent = $cache->get($cacheIdentifier);
            if ($cachedContent !== false) {
                return (string)$cachedContent;
            }
        }

        $startTime = microtime(true);
        try {
            if ($isFallback) {
                $pageHtml = $this->getRenderedPageHtmlFromUrl();
            } else {
                $pageHtml = $this->getRenderedPageContent();
            }

            if (empty($pageHtml)) {
                return "# Error\n\nNo page content could be rendered.\n";
            }

            $markdown = $this->markdownConverter->convertHtmlToMarkdown($pageHtml);

            if (empty(trim($markdown))) {
                return "# Error\n\nPage rendered but conversion to Markdown failed.\n";
            }

            // Add Webversion link at the bottom
            $page = $this->pageRepository->findById($pageId, $languageUid);

            if (!empty($page)) {
                $htmlUrl = $this->urlGenerator->generateHtmlUrl($page);
                $markdown .= "\n\n\n\nWebversion: " . $htmlUrl . "\n";
            }

            if ($isDebug) {
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                $renderMode = $isFallback ? 'HTML-Fallback' : 'Default';
                $now = date('Y-m-d H:i:s');
                
                $markdown .= "\n--- Debug Information ---\n";
                $markdown .= "Generated: " . $now . "\n";
                $markdown .= "Rendering Time: " . $duration . " ms\n";
                $markdown .= "Rendering Mode: " . $renderMode . "\n";
            }

            // Save to cache if enabled and not in debug mode
            if ($cacheEnabled && !$isDebug && !empty($markdown)) {
                $cache = $this->cacheManager->getCache('wn_ai_bridge_markdown');
                $cache->set($cacheIdentifier, $markdown, ['pageId_' . $pageId], 86400); // Cache for 24 hours
            }

            return $markdown;

        } catch (\Exception $e) {
            // The .md endpoint is public, so never expose internal exception
            // details unless the site is explicitly running in debug mode.
            if ($this->configurationService->isDebugEnabled()) {
                return "# Error\n\nFailed to render page: " . $e->getMessage() . "\n";
            }
            return "# Error\n\nThe page could not be rendered.\n";
        }
    }

    /**
     * Get the fully rendered page content from TYPO3's frontend rendering
     * This captures content elements from all column positions or only colPos 0
     * based on extension configuration.
     */
    protected function getRenderedPageContent(): string
    {
        // Always use a fresh ContentObjectRenderer to avoid state issues or double rendering
        $cObject = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequestInterface) {
            $cObject->setRequest($request);
        }
        $cObject->start([], 'pages');

        $pageId = $this->configurationService->getCurrentPageId();
        $languageUid = $this->getLanguageUid();

        $page = $this->pageRepository->findById($pageId, $languageUid);

        $html = '';

        // Use seo_title if available, otherwise fall back to title
        $displayTitle = ($page['seo_title'] ?? '') ?: ($page['title'] ?? '');
        if ($displayTitle !== '') {
            $html .= '<h1>' . htmlspecialchars((string)$displayTitle) . '</h1>';
        }

        if (!empty($page['description'])) {
            $html .= '<p class="page-description"> > ' . htmlspecialchars((string)$page['description']) . '</p>';
        }

        // Use native TYPO3 CONTENT object rendering
        // In TYPO3 12, the CONTENT object automatically handles language overlays 
        // and filtering if the request is properly set on the ContentObjectRenderer.
        $contentConfiguration = [
            'table' => 'tt_content',
            'select.' => [
                'pidInList' => (string)$pageId,
                'orderBy' => 'sorting',
                'where' => 'colPos=0',
            ],
        ];

        $renderedContent = $cObject->cObjGetSingle('CONTENT', $contentConfiguration);

        if (!empty($renderedContent)) {
            $html .= $renderedContent;
        }

        if ($this->configurationService->isDebugEnabled()) {
            $debugFile = GeneralUtility::getFileAbsFileName('EXT:wn_ai_bridge/var/log/debug_default_render_' . $pageId . '.html');
            GeneralUtility::mkdir_deep(dirname($debugFile));
            GeneralUtility::writeFile($debugFile, (string)$html);
        }

        return $html;
    }

    /**
     * Get the current language UID from TYPO3 frontend environment
     * Checks sys_language_uid first, falls back to page['sys_language_uid']
     */
    protected function getLanguageUid(): int
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequestInterface) {
            $language = $request->getAttribute('language');
            if ($language instanceof SiteLanguage) {
                return $language->getLanguageId();
            }
        }

        // Fallback for older versions or specific contexts
        if (isset($GLOBALS['TSFE']) && isset($GLOBALS['TSFE']->sys_language_uid)) {
            $langUid = (int)$GLOBALS['TSFE']->sys_language_uid;
            if ($langUid > 0) {
                return $langUid;
            }
        }

        // Fallback: get language from current page data
        if (isset($GLOBALS['TSFE']) && isset($GLOBALS['TSFE']->page)) {
            $page = $GLOBALS['TSFE']->page;
            if (is_array($page) && isset($page['sys_language_uid'])) {
                return (int)$page['sys_language_uid'];
            }
        }

        return 0;
    }

    /**
     * Fetches the rendered HTML of the current page from its URL.
     * Special handling for OnePager: isolates the specific page section via anchor.
     */
    protected function getRenderedPageHtmlFromUrl(): string
    {
        $pageId = $this->configurationService->getCurrentPageId();
        $languageUid = $this->getLanguageUid();
        $page = $this->pageRepository->findById($pageId, $languageUid);

        if (empty($page)) {
            return '';
        }

        $url = $this->urlGenerator->generateHtmlUrl($page);
        
        // Fetch HTML via GeneralUtility::getUrl
        $html = GeneralUtility::getUrl($url);

        if (empty($html)) {
            return '';
        }

        // Handle OnePager: If URL contains an anchor, we isolate that section
        if (str_contains($url, '#')) {
            // Restrict the anchor to characters valid in an HTML id/slug before
            // it is interpolated into the XPath expression below.
            $anchor = preg_replace('/[^A-Za-z0-9._-]/', '', explode('#', $url)[1]);

            $dom = new \DOMDocument();
            // Suppress errors due to malformed HTML
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
            libxml_clear_errors();
            
            $xpath = new \DOMXPath($dom);
            // Search for element with ID (more reliable than getElementById in some PHP versions without DTD)
            $nodes = $xpath->query('//*[@id="' . $anchor . '"]');
            
            if ($nodes->length > 0) {
                $element = $nodes->item(0);
                $html = '';
                foreach ($element->childNodes as $child) {
                    $html .= $dom->saveHTML($child);
                }
            }
        }

        if ($this->configurationService->isDebugEnabled() && !empty($html)) {
            // Write (potentially isolated) HTML to temporary file for debugging
            $debugFile = GeneralUtility::getFileAbsFileName('EXT:wn_ai_bridge/var/log/debug_page_render_' . $pageId . '.html');
            GeneralUtility::mkdir_deep(dirname($debugFile));
            GeneralUtility::writeFile($debugFile, (string)$html);
        }

        // Extract content from colPos 0 markers or common tags if we still have a full document
        if (preg_match('/<!--\s*CONTENT_COL_0_START\s*-->(.*?)<!--\s*CONTENT_COL_0_END\s*-->/is', $html, $matches)) {
            return $matches[1];
        }

        if (preg_match('/<main[^>]*>(.*?)<\/main>/is', $html, $matches)) {
            return $matches[1];
        }

        return $html;
    }
}

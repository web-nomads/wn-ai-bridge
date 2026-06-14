<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Controller;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Attribute\AsAllowedCallable;
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
    private readonly LlmsTxtGeneratorService $llmsTxtGenerator;
    private readonly ConfigurationService $configurationService;
    private readonly MarkdownConverterService $markdownConverter;
    private readonly PageRepository $pageRepository;
    private readonly UrlGeneratorService $urlGenerator;

    public function __construct(
        ?LlmsTxtGeneratorService $llmsTxtGenerator = null,
        ?ConfigurationService $configurationService = null,
        ?MarkdownConverterService $markdownConverter = null,
        ?PageRepository $pageRepository = null,
        ?UrlGeneratorService $urlGenerator = null
    ) {
        $this->llmsTxtGenerator = $llmsTxtGenerator ?? GeneralUtility::makeInstance(LlmsTxtGeneratorService::class);
        $this->configurationService = $configurationService ?? GeneralUtility::makeInstance(ConfigurationService::class);
        $this->markdownConverter = $markdownConverter ?? GeneralUtility::makeInstance(MarkdownConverterService::class);
        $this->pageRepository = $pageRepository ?? GeneralUtility::makeInstance(PageRepository::class);
        $this->urlGenerator = $urlGenerator ?? GeneralUtility::makeInstance(UrlGeneratorService::class);
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
     * This approach uses TYPO3's normal rendering pipeline to get ALL content
     * from all column positions (colPos 0, 1, 100+)
     */
    #[AsAllowedCallable]
    public function renderPageAsMarkdown(string $content, array $conf): string
    {
        try {
            $pageHtml = $this->getRenderedPageContent();

            if (empty($pageHtml)) {
                return "# Error\n\nNo page content could be rendered.\n";
            }

            $markdown = $this->markdownConverter->convertHtmlToMarkdown($pageHtml);

            if (empty(trim($markdown))) {
                return "# Error\n\nPage rendered but conversion to Markdown failed.\n";
            }

            // Add Webversion link at the bottom
            $pageId = $this->configurationService->getCurrentPageId();
            $languageUid = $this->getLanguageUid();
            $page = $this->pageRepository->findById($pageId, $languageUid);

            if (!empty($page)) {
                $htmlUrl = $this->urlGenerator->generateHtmlUrl($page);
                $markdown .= "\n\n\n\nWebversion: " . $htmlUrl . "\n";
            }

            return $markdown;

        } catch (\Exception $e) {
            return "# Error\n\nFailed to render page: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Get the fully rendered page content from TYPO3's frontend rendering
     * This captures ALL content elements from all column positions
     */
    protected function getRenderedPageContent(): string
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $cObject = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        if ($request instanceof ServerRequestInterface) {
            $cObject->setRequest($request);
        }
        $cObject->start([], 'pages');

        $pageId = $this->configurationService->getCurrentPageId();
        // Get current language UID for proper localization
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

        // Render ALL content elements from ALL column positions
        // This ensures we capture everything on the page even third-party extensions
        $contentConfiguration = [
            'table' => 'tt_content',
            'select.' => [
                'orderBy' => 'colPos, sorting',
                'where' => '{#deleted}=0 AND {#hidden}=0',
                'pidInList' => (string)$pageId,
            ],
        ];
        $renderedContent = $cObject->cObjGetSingle('CONTENT', $contentConfiguration);

        if (!empty($renderedContent)) {
            $html .= $renderedContent;
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
}

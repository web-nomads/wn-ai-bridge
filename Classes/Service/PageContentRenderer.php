<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use WebNomads\WnAiBridge\Repository\PageRepository;

/**
 * Renders the frontend HTML of a page: its heading, its description and the
 * content elements in colPos 0.
 *
 * The page is passed in rather than read from the request, because llms-full.txt
 * renders every page of a site within the single request made for the document,
 * while the ".md" endpoint renders only the one that was asked for.
 */
class PageContentRenderer
{
    private readonly PageRepository $pageRepository;
    private readonly ConfigurationService $configurationService;

    public function __construct(
        ?PageRepository $pageRepository = null,
        ?ConfigurationService $configurationService = null
    ) {
        $this->pageRepository = $pageRepository ?? GeneralUtility::makeInstance(PageRepository::class);
        $this->configurationService = $configurationService ?? GeneralUtility::makeInstance(ConfigurationService::class);
    }

    /**
     * @param bool $withHeader Whether the page title and description are put in
     *        front of the content. Off for llms-full.txt, which writes a section
     *        heading and a blockquote of its own.
     */
    public function render(int $pageId, int $languageUid = 0, bool $withHeader = true): string
    {
        // Always a fresh ContentObjectRenderer to avoid state issues or double rendering.
        $cObject = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequestInterface) {
            $cObject->setRequest($request);
        }
        $cObject->start([], 'pages');

        $html = '';

        if ($withHeader) {
            $page = $this->pageRepository->findById($pageId, $languageUid);

            // Use seo_title if available, otherwise fall back to title
            $displayTitle = ($page['seo_title'] ?? '') ?: ($page['title'] ?? '');
            if ($displayTitle !== '') {
                $html .= '<h1>' . htmlspecialchars((string)$displayTitle) . '</h1>';
            }

            if (!empty($page['description'])) {
                $html .= '<p class="page-description"> > ' . htmlspecialchars((string)$page['description']) . '</p>';
            }
        }

        // Use native TYPO3 CONTENT object rendering.
        // In TYPO3 12+, the CONTENT object automatically handles language overlays
        // and filtering if the request is properly set on the ContentObjectRenderer.
        $renderedContent = $cObject->cObjGetSingle('CONTENT', [
            'table' => 'tt_content',
            'select.' => [
                'pidInList' => (string)$pageId,
                'orderBy' => 'sorting',
                'where' => 'colPos=0',
            ],
        ]);

        if (!empty($renderedContent)) {
            $html .= $renderedContent;
        }

        if ($this->configurationService->isDebugEnabled()) {
            $debugFile = GeneralUtility::getFileAbsFileName('EXT:wn_ai_bridge/var/log/debug_default_render_' . $pageId . '.html');
            GeneralUtility::mkdir_deep(dirname($debugFile));
            GeneralUtility::writeFile($debugFile, $html);
        }

        return $html;
    }
}

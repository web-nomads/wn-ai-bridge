<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Builder;

use TYPO3\CMS\Core\Site\SiteFinder;
use WebNomads\WnAiBridge\Repository\PageRepository;
use WebNomads\WnAiBridge\Service\UrlGeneratorService;

/**
 * Builder for creating hierarchical navigation structures
 * Uses the Builder pattern to construct complex navigation data
 */
class NavigationBuilder
{
    private readonly SiteFinder $siteFinder;
    private readonly PageRepository $pageRepository;
    private readonly UrlGeneratorService $urlGenerator;

    public function __construct(
        ?SiteFinder $siteFinder = null,
        ?PageRepository $pageRepository = null,
        ?UrlGeneratorService $urlGenerator = null
    ) {
        $this->siteFinder = $siteFinder ?? \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(SiteFinder::class);
        $this->pageRepository = $pageRepository ?? \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(PageRepository::class);
        $this->urlGenerator = $urlGenerator ?? \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(UrlGeneratorService::class);
    }

    /**
     * Build hierarchical navigation structure
     */
    public function build(int $rootPageUid, int $maxDepth = 2, int $languageUid = 0): array
    {
        $site = $this->siteFinder->getSiteByPageId($rootPageUid);

        // Get the site language for proper fallback handling
        try {
            $siteLanguage = $site->getLanguageById($languageUid);
        } catch (\Exception $e) {
            $siteLanguage = $site->getDefaultLanguage();
        }

        return $this->buildRecursive($rootPageUid, $siteLanguage, $maxDepth);
    }

    /**
     * Recursive helper to build the structure
     */
    protected function buildRecursive(int $parentUid, \TYPO3\CMS\Core\Site\Entity\SiteLanguage $siteLanguage, int $maxDepth, int $currentDepth = 1): array
    {
        if ($currentDepth > $maxDepth) {
            return [];
        }

        $structure = [];
        $pages = $this->pageRepository->findNavigationByParentWithFallback($parentUid, $siteLanguage);

        foreach ($pages as $page) {
            $item = [
                'uid' => $page['uid'],
                'title' => preg_replace('/\s+/', ' ', trim($page['nav_title'] ?: $page['title'])),
                'description' => $page['description'] ?: $page['abstract'] ?: '',
                'url' => $this->urlGenerator->generatePageUrl($page),
                'language' => $this->getLanguageTitle($page),
                'pages' => $this->buildRecursive($page['uid'], $siteLanguage, $maxDepth, $currentDepth + 1),
            ];
            $structure[] = $item;
        }

        return $structure;
    }

    protected function getLanguageTitle(array $page): string
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($page['uid']);
            $language = $site->getLanguageById((int)($page['sys_language_uid'] ?? 0));

            return $language ? $language->getTitle() : 'default';
        } catch (\Exception $e) {
            // Fallback if language ID exists in DB but not in site configuration
            return 'default';
        }
    }

    /**
     * Format navigation structure as markdown lines
     */
    public function formatAsMarkdown(array $navigationStructure, int $currentLanguageUid = 0, int $level = 0): array
    {
        $lines = [];
        $indent = str_repeat('    ', $level);

        foreach ($navigationStructure as $item) {
            if (!empty($item['url'])) {
                $line = $indent . "- [{$item['title']}]({$item['url']})";
                if (!empty($item['description']) && $level > 0) {
                    $line .= ": {$item['description']}";
                }
                $lines[] = $line;
            }

            if (!empty($item['pages'])) {
                $childLines = $this->formatAsMarkdown($item['pages'], $currentLanguageUid, $level + 1);
                $lines = array_merge($lines, $childLines);
            }
        }

        return $lines;
    }
}

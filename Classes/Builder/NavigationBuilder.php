<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Builder;

use TYPO3\CMS\Core\Domain\Repository\PageRepository as CorePageRepository;
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
     * Pages hidden in menus but marked for llms.txt, as items of a flat list
     *
     * @return list<array<string, mixed>>
     */
    public function buildListed(int $rootPageUid, int $languageUid = 0): array
    {
        $site = $this->siteFinder->getSiteByPageId($rootPageUid);
        try {
            $siteLanguage = $site->getLanguageById($languageUid);
        } catch (\Exception $e) {
            $siteLanguage = $site->getDefaultLanguage();
        }

        $items = [];
        foreach ($this->pageRepository->findListedInLlmsTxtWithFallback($rootPageUid, $siteLanguage) as $page) {
            $items[] = [
                'uid' => $page['uid'],
                'title' => trim((string)preg_replace('/\s+/', ' ', trim((string)($page['nav_title'] ?: $page['title'])))),
                'description' => trim((string)preg_replace('/\s+/', ' ', trim((string)($page['description'] ?: $page['abstract'] ?: '')))),
                'url' => $this->urlGenerator->generatePageUrl($page),
                'language' => $this->getLanguageTitle($page),
                'pages' => [],
            ];
        }

        return $items;
    }

    /**
     * Recursive helper to build the structure
     *
     * Menu separators are asked for at the top level only. There they become the
     * H2 headings that split the link list into sections; deeper down an H2 would
     * cut the list in two and everything after it would read as a new section, so
     * they keep being skipped.
     */
    protected function buildRecursive(int $parentUid, \TYPO3\CMS\Core\Site\Entity\SiteLanguage $siteLanguage, int $maxDepth, int $currentDepth = 1): array
    {
        if ($currentDepth > $maxDepth) {
            return [];
        }

        $topLevel = $currentDepth === 1;

        $structure = [];
        $pages = $this->pageRepository->findNavigationByParentWithFallback($parentUid, $siteLanguage, $topLevel);

        foreach ($pages as $page) {
            $title = trim((string)preg_replace('/\s+/', ' ', trim((string)($page['nav_title'] ?: $page['title']))));

            if ($topLevel && (int)$page['doktype'] === CorePageRepository::DOKTYPE_SPACER) {
                if (self::isSectionTitle($title)) {
                    $structure[] = [
                        'uid' => $page['uid'],
                        'title' => $title,
                        'section' => true,
                    ];
                }
                continue;
            }

            $item = [
                'uid' => $page['uid'],
                'title' => $title,
                // Collapsed onto one line: a break inside a list item ends it.
                'description' => trim((string)preg_replace(
                    '/\s+/',
                    ' ',
                    trim((string)($page['description'] ?: $page['abstract'] ?: ''))
                )),
                'url' => $this->urlGenerator->generatePageUrl($page),
                'language' => $this->getLanguageTitle($page),
                'pages' => $this->buildRecursive($page['uid'], $siteLanguage, $maxDepth, $currentDepth + 1),
            ];
            $structure[] = $item;
        }

        return $structure;
    }

    /**
     * Drop section headings that nothing follows.
     *
     * Under a strict language every page of a section can be untranslated while
     * the separator itself survives, and a heading with no list under it says
     * nothing — it only suggests something went missing.
     *
     * @param array<int, array<string, mixed>> $structure
     * @return array<int, array<string, mixed>>
     */
    private static function withoutEmptySections(array $structure): array
    {
        $kept = [];

        foreach ($structure as $index => $item) {
            if (!empty($item['section']) && !self::sectionHasPages($structure, $index)) {
                continue;
            }

            $kept[] = $item;
        }

        return $kept;
    }

    /**
     * Whether any linkable page follows a section heading before the next one.
     *
     * @param array<int, array<string, mixed>> $structure
     */
    private static function sectionHasPages(array $structure, int $sectionIndex): bool
    {
        $following = array_slice($structure, $sectionIndex + 1);

        foreach ($following as $item) {
            if (!empty($item['section'])) {
                return false;
            }

            if (!empty($item['url'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a separator's title reads as a heading rather than as decoration.
     *
     * Separators are widely used purely visually, titled "---" or "•" or nothing
     * at all. Those carry no meaning to put in a document, so they keep being
     * skipped; a title with a letter or a digit in it is meant to be read.
     */
    public static function isSectionTitle(string $title): bool
    {
        return preg_match('/[\p{L}\p{N}]/u', $title) === 1;
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
     *
     * Every entry carries its description, at every level: the notes after the
     * colon are what an agent reads to decide which link is worth fetching, and
     * the top-level pages are the ones it looks at first.
     */
    public function formatAsMarkdown(array $navigationStructure, int $currentLanguageUid = 0, int $level = 0): array
    {
        if ($level === 0) {
            $navigationStructure = self::withoutEmptySections($navigationStructure);
        }

        $lines = [];
        $indent = str_repeat('    ', $level);

        foreach ($navigationStructure as $item) {
            if (!empty($item['section'])) {
                // A blank line above the heading, none below it: the list that
                // follows belongs to the heading and is written against it.
                if ($lines !== []) {
                    $lines[] = '';
                }
                $lines[] = '## ' . $item['title'];
                continue;
            }

            if (!empty($item['url'])) {
                $line = $indent . "- [{$item['title']}]({$item['url']})";
                if (!empty($item['description'])) {
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

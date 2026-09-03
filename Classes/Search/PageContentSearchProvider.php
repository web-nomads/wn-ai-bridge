<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Search;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WebNomads\WnAiBridge\Dto\SearchResultItem;
use WebNomads\WnAiBridge\Service\UrlGeneratorService;

/**
 * Dependency-free fallback search that queries the pages and tt_content tables
 * directly. It always works — even on a site that has neither ke_search nor
 * indexed_search installed or indexed yet — so the assistant is never a dead
 * end. Frontend restrictions (hidden, start/endtime, fe_group) are applied so
 * only publicly visible content is ever surfaced.
 */
final class PageContentSearchProvider implements SearchProviderInterface
{
    /**
     * Bounds for walking the subtree the search is restricted to.
     *
     * Generous, because this is no longer only the optional "search below this
     * page" narrowing: on an installation with several sites the site's own root
     * page is the restriction, and a whole website has to fit inside it. A tree
     * deeper or larger than this loses its tail from the results — which is why
     * these are far above what a site realistically has, rather than snug.
     */
    private const MAX_SUBTREE_DEPTH = 15;
    private const MAX_SUBTREE_PAGES = 10000;

    private readonly ConnectionPool $connectionPool;
    private readonly UrlGeneratorService $urlGenerator;

    public function __construct(
        ?ConnectionPool $connectionPool = null,
        ?UrlGeneratorService $urlGenerator = null,
    ) {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->urlGenerator = $urlGenerator ?? GeneralUtility::makeInstance(UrlGeneratorService::class);
    }

    public function getKey(): string
    {
        return 'pages';
    }

    public function isAvailable(): bool
    {
        // pages/tt_content are always present in a TYPO3 installation.
        return true;
    }

    public function search(string $query, int $limit, int $languageId, int $rootPageId = 0): array
    {
        $terms = SearchQuery::terms($query);
        if ($terms === []) {
            return [];
        }

        $restrictToPages = $rootPageId > 0 ? $this->collectSubtreePageIds($rootPageId) : [];

        // Score is accumulated per page across page metadata and content elements.
        // $contentText keeps the best-matching content passage per page so the
        // snippet can show the relevant text (not just the page description).
        $scores = [];
        $contentText = [];
        $this->collectFromPages($terms, $languageId, $restrictToPages, $scores);
        $this->collectFromContent($terms, $languageId, $restrictToPages, $scores, $contentText);

        if ($scores === []) {
            return [];
        }

        arsort($scores);
        $scores = array_slice($scores, 0, $limit, true);

        $items = [];
        foreach ($scores as $pageId => $score) {
            $page = $this->fetchPage((int)$pageId, $languageId);
            if ($page === null) {
                continue;
            }
            $url = $this->urlGenerator->generateUrlForPageId((int)$pageId, $languageId);
            if ($url === '') {
                continue;
            }

            $title = ($page['seo_title'] ?? '') ?: ($page['title'] ?? '');
            // Prefer a keyword-centred excerpt from the matching content, then
            // the page description/abstract.
            $snippetSource = ($contentText[(int)$pageId]['text'] ?? '')
                ?: (($page['description'] ?? '') ?: ($page['abstract'] ?? ''));
            $snippet = SearchQuery::snippet((string)$snippetSource, $terms);
            $items[] = SearchResultItem::create(
                (string)$title,
                $url,
                $snippet,
                (float)$score,
                $this->getKey(),
                (int)$pageId,
            );
        }

        return $items;
    }

    /**
     * @param list<string>  $terms
     * @param list<int>     $restrictToPages
     * @param array<int, float> $scores
     */
    private function collectFromPages(array $terms, int $languageId, array $restrictToPages, array &$scores): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        $searchFields = ['title', 'nav_title', 'subtitle', 'keywords', 'description', 'abstract'];
        $queryBuilder
            ->select('uid', 'l10n_parent', 'title', 'nav_title', 'subtitle', 'keywords', 'description', 'abstract')
            ->from('pages')
            ->where($this->buildLikeConstraint($queryBuilder, $terms, $searchFields))
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)
                )
            )
            ->setMaxResults(200);

        $this->applyPageRestriction($queryBuilder, 'uid', $restrictToPages, $languageId);

        try {
            $rows = $queryBuilder->executeQuery()->fetchAllAssociative();
        } catch (\Throwable $e) {
            return;
        }

        foreach ($rows as $row) {
            // Translated rows are scored against their default-language page id so
            // hits from both languages accumulate on one result.
            $pageId = (int)($row['l10n_parent'] ?: $row['uid']);
            $weights = [
                'title' => 6.0, 'nav_title' => 4.0, 'subtitle' => 3.0,
                'keywords' => 3.0, 'description' => 3.0, 'abstract' => 2.0,
            ];
            foreach ($weights as $field => $weight) {
                $scores[$pageId] = ($scores[$pageId] ?? 0.0)
                    + $this->fieldScore($terms, (string)($row[$field] ?? '')) * $weight;
            }
        }
    }

    /**
     * @param list<string>  $terms
     * @param list<int>     $restrictToPages
     * @param array<int, float> $scores
     * @param array<int, array{score: float, text: string}> $contentText Best matching content passage per page (by ref)
     */
    private function collectFromContent(array $terms, int $languageId, array $restrictToPages, array &$scores, array &$contentText = []): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        $searchFields = ['header', 'subheader', 'bodytext'];
        $queryBuilder
            ->select('pid', 'header', 'subheader', 'bodytext')
            ->from('tt_content')
            ->where($this->buildLikeConstraint($queryBuilder, $terms, $searchFields))
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)
                )
            )
            ->setMaxResults(500);

        $this->applyPageRestriction($queryBuilder, 'pid', $restrictToPages, $languageId);

        try {
            $rows = $queryBuilder->executeQuery()->fetchAllAssociative();
        } catch (\Throwable $e) {
            return;
        }

        foreach ($rows as $row) {
            $pageId = (int)$row['pid'];
            $bodyText = strip_tags((string)($row['bodytext'] ?? ''));
            $rowScore = $this->fieldScore($terms, (string)($row['header'] ?? '')) * 4.0
                + $this->fieldScore($terms, (string)($row['subheader'] ?? '')) * 2.0
                + $this->fieldScore($terms, $bodyText) * 1.0;

            $scores[$pageId] = ($scores[$pageId] ?? 0.0) + $rowScore;

            // Remember the highest-scoring matching passage for the snippet.
            if ($rowScore > 0 && $rowScore >= ($contentText[$pageId]['score'] ?? 0.0)) {
                $contentText[$pageId] = [
                    'score' => $rowScore,
                    'text' => trim(((string)($row['header'] ?? '')) . ' ' . $bodyText),
                ];
            }
        }
    }

    /**
     * @param list<string> $terms
     * @param list<string> $fields
     */
    private function buildLikeConstraint($queryBuilder, array $terms, array $fields): string
    {
        $orConditions = [];
        foreach ($terms as $term) {
            $like = '%' . $queryBuilder->escapeLikeWildcards($term) . '%';
            $param = $queryBuilder->createNamedParameter($like);
            foreach ($fields as $field) {
                $orConditions[] = $queryBuilder->expr()->like($field, $param);
            }
        }
        return (string)$queryBuilder->expr()->or(...$orConditions);
    }

    /**
     * @param list<int> $restrictToPages
     */
    private function applyPageRestriction($queryBuilder, string $column, array $restrictToPages, int $languageId): void
    {
        if ($restrictToPages !== []) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->in(
                    $column,
                    $queryBuilder->createNamedParameter($restrictToPages, Connection::PARAM_INT_ARRAY)
                )
            );
        }
    }

    /**
     * @param list<string> $terms
     */
    private function fieldScore(array $terms, string $value): float
    {
        if ($value === '') {
            return 0.0;
        }
        $value = mb_strtolower($value);
        $score = 0.0;
        foreach ($terms as $term) {
            $score += min(substr_count($value, $term), 5);
        }
        return $score;
    }

    /**
     * @return array{uid:int, title:string, seo_title:string, description:string, abstract:string}|null
     */
    private function fetchPage(int $pageId, int $languageId): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        $row = $queryBuilder
            ->select('uid', 'title', 'seo_title', 'description', 'abstract')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
    }

    /**
     * Collect the ids of a page subtree (bounded) for the optional search-root
     * restriction. Uses a simple breadth-first walk over the pages table.
     *
     * @return list<int>
     */
    private function collectSubtreePageIds(int $rootPageId): array
    {
        $collected = [$rootPageId => true];
        $current = [$rootPageId];

        for ($depth = 0; $depth < self::MAX_SUBTREE_DEPTH && $current !== []; $depth++) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()->removeAll()
                ->add(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

            try {
                $children = $queryBuilder
                    ->select('uid')
                    ->from('pages')
                    ->where(
                        $queryBuilder->expr()->in(
                            'pid',
                            $queryBuilder->createNamedParameter($current, Connection::PARAM_INT_ARRAY)
                        ),
                        $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
                    )
                    ->executeQuery()
                    ->fetchFirstColumn();
            } catch (\Throwable $e) {
                break;
            }

            $current = [];
            foreach ($children as $childId) {
                $childId = (int)$childId;
                if (!isset($collected[$childId])) {
                    $collected[$childId] = true;
                    $current[] = $childId;
                }
                if (count($collected) >= self::MAX_SUBTREE_PAGES) {
                    return array_keys($collected);
                }
            }
        }

        return array_keys($collected);
    }
}

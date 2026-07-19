<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Search;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WebNomads\WnAiBridge\Dto\SearchResultItem;
use WebNomads\WnAiBridge\Service\UrlGeneratorService;

/**
 * Search provider backed by TYPO3's core indexed_search tables.
 *
 * index_fulltext has no MySQL FULLTEXT index, so we match with LIKE across the
 * page title, description and full text and compute a weighted relevance score
 * in PHP. This keeps the provider portable across DB platforms.
 */
final class IndexedSearchProvider implements SearchProviderInterface
{
    private const TABLE_PHASH = 'index_phash';
    private const TABLE_FULLTEXT = 'index_fulltext';

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
        return 'indexed';
    }

    public function isAvailable(): bool
    {
        if (!ExtensionManagementUtility::isLoaded('indexed_search')) {
            return false;
        }
        try {
            $schemaManager = $this->connectionPool
                ->getConnectionForTable(self::TABLE_PHASH)
                ->createSchemaManager();
            return $schemaManager->tablesExist([self::TABLE_PHASH, self::TABLE_FULLTEXT]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function search(string $query, int $limit, int $languageId, int $rootPageId = 0): array
    {
        $terms = SearchQuery::terms($query);
        if ($terms === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_PHASH);
        $queryBuilder->getRestrictions()->removeAll();

        $queryBuilder
            ->select(
                'p.data_page_id',
                'p.data_page_type',
                'p.data_page_mp',
                'p.static_page_arguments',
                'p.item_title',
                'p.item_description'
            )
            ->addSelect('f.fulltextdata')
            ->from(self::TABLE_PHASH, 'p')
            ->leftJoin('p', self::TABLE_FULLTEXT, 'f', 'f.phash = p.phash')
            ->where(
                $queryBuilder->expr()->gt('p.data_page_id', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('p.externalUrl', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq(
                    'p.sys_language_uid',
                    $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)
                )
            )
            // Over-fetch candidates, then rank in PHP and cut to $limit.
            ->setMaxResults(max($limit * 5, 25));

        // Require at least one term to appear somewhere (title, description or body).
        $orConditions = [];
        foreach ($terms as $term) {
            $like = '%' . $queryBuilder->escapeLikeWildcards($term) . '%';
            $param = $queryBuilder->createNamedParameter($like);
            $orConditions[] = $queryBuilder->expr()->like('p.item_title', $param);
            $orConditions[] = $queryBuilder->expr()->like('p.item_description', $param);
            $orConditions[] = $queryBuilder->expr()->like('f.fulltextdata', $param);
        }
        $queryBuilder->andWhere($queryBuilder->expr()->or(...$orConditions));

        try {
            $rows = $queryBuilder->executeQuery()->fetchAllAssociative();
        } catch (\Throwable $e) {
            return [];
        }

        $items = [];
        $seenUrls = [];
        foreach ($rows as $row) {
            $pageId = (int)$row['data_page_id'];

            $title = (string)$row['item_title'];
            $description = (string)$row['item_description'];
            $body = (string)($row['fulltextdata'] ?? '');

            $score = $this->score($terms, $title, $description, $body);
            if ($score <= 0.0) {
                continue;
            }

            // Reconstruct the record parameters indexed_search stored for this
            // hit so the link targets the exact record (e.g. a news detail view)
            // instead of the plain page hosting the plugin.
            $arguments = $this->buildRouteArguments($row);
            $url = $this->urlGenerator->generateUrlForPageId($pageId, $languageId, $arguments);
            if ($url === '' || isset($seenUrls[$url])) {
                continue;
            }

            // De-duplicate by the resolved URL, not by page id: several distinct
            // records can share the same data_page_id but link to different URLs.
            $seenUrls[$url] = true;
            // Centre the snippet on the matched keyword across the meta
            // description and the full text, so it shows the relevant passage
            // instead of the page's (navigational) start.
            $snippet = SearchQuery::snippet(trim($description . ' ' . $body), $terms);
            $items[] = SearchResultItem::create($title, $url, $snippet, $score, $this->getKey(), $pageId);
        }

        usort($items, static fn(SearchResultItem $a, SearchResultItem $b): int => $b->score <=> $a->score);

        return array_slice($items, 0, $limit);
    }

    /**
     * Reconstruct the route arguments indexed_search persisted for a hit, so the
     * generated link points at the exact record instead of the hosting page.
     * Mirrors \TYPO3\CMS\IndexedSearch\Controller\SearchController::linkPage():
     * static_page_arguments carries the record GET parameters, data_page_mp the
     * mount point and data_page_type the page type.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function buildRouteArguments(array $row): array
    {
        $arguments = [];

        $raw = $row['static_page_arguments'] ?? null;
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $arguments = $decoded;
                }
            } catch (\JsonException $e) {
                // Malformed data — fall back to a plain page link.
            }
        }

        $mountPoint = (string)($row['data_page_mp'] ?? '');
        if ($mountPoint !== '') {
            $arguments['MP'] = $mountPoint;
        }

        $pageType = (int)($row['data_page_type'] ?? 0);
        if ($pageType > 0) {
            $arguments['type'] = $pageType;
        }

        return $arguments;
    }

    /**
     * Weighted term-frequency score: title matches count most, body least.
     *
     * @param list<string> $terms
     */
    private function score(array $terms, string $title, string $description, string $body): float
    {
        $title = mb_strtolower($title);
        $description = mb_strtolower($description);
        $body = mb_strtolower($body);

        $score = 0.0;
        foreach ($terms as $term) {
            $score += substr_count($title, $term) * 5.0;
            $score += substr_count($description, $term) * 2.0;
            $score += min(substr_count($body, $term), 5) * 1.0;
        }
        return $score;
    }
}

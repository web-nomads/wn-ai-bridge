<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Search;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WebNomads\WnAiBridge\Dto\SearchResultItem;
use WebNomads\WnAiBridge\Service\UrlGeneratorService;

/**
 * Search provider backed by the ke_search index table (tx_kesearch_index).
 *
 * It queries the fulltext index directly via the Doctrine query builder rather
 * than coupling to ke_search's internal plugin classes, so it keeps working
 * across ke_search major versions and never needs a plugin context.
 */
final class KeSearchProvider implements SearchProviderInterface
{
    private const TABLE = 'tx_kesearch_index';

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
        return 'kesearch';
    }

    public function isAvailable(): bool
    {
        try {
            $schemaManager = $this->connectionPool
                ->getConnectionForTable(self::TABLE)
                ->createSchemaManager();
            return $schemaManager->tablesExist([self::TABLE]);
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

        $now = $GLOBALS['SIM_ACCESS_TIME'] ?? time();

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        // MATCH ... AGAINST in boolean mode gives us a real relevance score and
        // supports the "+term*" prefix wildcards we build for partial matches.
        $booleanExpression = SearchQuery::booleanMode($terms);
        $matchParam = $queryBuilder->createNamedParameter($booleanExpression);
        $scoreExpression = 'MATCH(title, content) AGAINST (' . $matchParam . ' IN BOOLEAN MODE)';

        $queryBuilder
            ->select('uid', 'title', 'content', 'abstract', 'targetpid', 'orig_uid', 'type')
            ->addSelectLiteral($scoreExpression . ' AS score')
            ->from(self::TABLE)
            ->where($scoreExpression)
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'language',
                    $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->lte(
                    'starttime',
                    $queryBuilder->createNamedParameter($now, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('endtime', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->gt('endtime', $queryBuilder->createNamedParameter($now, Connection::PARAM_INT))
                )
            )
            ->orderBy('score', 'DESC')
            ->setMaxResults($limit);

        try {
            $rows = $queryBuilder->executeQuery()->fetchAllAssociative();
        } catch (\Throwable $e) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            $pageId = (int)($row['targetpid'] ?: $row['orig_uid']);
            $url = $this->urlGenerator->generateUrlForPageId($pageId, $languageId);
            if ($url === '') {
                continue;
            }

            $snippet = (string)($row['abstract'] ?: $row['content']);
            $items[] = SearchResultItem::create(
                (string)$row['title'],
                $url,
                $snippet,
                (float)$row['score'],
                $this->getKey(),
                $pageId,
            );
        }

        return $items;
    }
}

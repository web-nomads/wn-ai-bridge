<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WebNomads\WnAiBridge\Domain\Model\BotAccessEntry;
use WebNomads\WnAiBridge\Domain\Model\BotAccessFilter;

/**
 * Storage and retrieval for the bot access log. Written from a frontend
 * middleware, read by a backend module.
 */
final class BotAccessRepository
{
    public const TABLE = 'tx_wnaibridge_bot_access';

    private readonly ConnectionPool $connectionPool;

    public function __construct(?ConnectionPool $connectionPool = null)
    {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function add(array $data): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, $data);
    }

    /**
     * @return list<BotAccessEntry>
     */
    public function findByFilter(BotAccessFilter $filter): array
    {
        $queryBuilder = $this->createFilteredQuery($filter);

        $rows = $queryBuilder
            ->select('*')
            ->orderBy('crdate', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults($filter->perPage)
            ->setFirstResult($filter->getOffset())
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): BotAccessEntry => BotAccessEntry::fromRow($row), $rows);
    }

    public function countByFilter(BotAccessFilter $filter): int
    {
        return (int)$this->createFilteredQuery($filter)
            ->count('uid')
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Aggregate counts per request type for the current filter.
     *
     * @return array{total: int, llmstxt: int, markdown: int, page: int, ai: int}
     */
    public function getStatistics(BotAccessFilter $filter): array
    {
        $typeQuery = $this->createFilteredQuery($filter);
        $typeRows = $typeQuery
            ->select('request_type')
            ->addSelectLiteral('COUNT(uid) AS cnt')
            ->groupBy('request_type')
            ->executeQuery()
            ->fetchAllAssociative();

        $byType = [];
        $total = 0;
        foreach ($typeRows as $row) {
            $byType[(string)$row['request_type']] = (int)$row['cnt'];
            $total += (int)$row['cnt'];
        }

        $aiQuery = $this->createFilteredQuery($filter);
        $ai = (int)$aiQuery
            ->count('uid')
            ->andWhere($aiQuery->expr()->eq('is_ai_bot', $aiQuery->createNamedParameter(1, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        return [
            'total' => $total,
            'llmstxt' => $byType['llmstxt'] ?? 0,
            'markdown' => $byType['markdown'] ?? 0,
            'page' => $byType['page'] ?? 0,
            'ai' => $ai,
        ];
    }

    /**
     * Distinct bot names present in the log, for the filter dropdown.
     *
     * @return list<string>
     */
    public function findDistinctBotNames(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('bot_name')
            ->distinct()
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->neq('bot_name', $queryBuilder->createNamedParameter('')))
            ->orderBy('bot_name', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map('strval', $rows);
    }

    public function deleteAll(): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $count = (int)$connection->count('uid', self::TABLE, []);
        $connection->truncate(self::TABLE);
        return $count;
    }

    private function createFilteredQuery(BotAccessFilter $filter): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->from(self::TABLE);

        $conditions = [];
        if ($filter->dateFrom !== null) {
            $conditions[] = $queryBuilder->expr()->gte('crdate', $queryBuilder->createNamedParameter($filter->dateFrom, Connection::PARAM_INT));
        }
        if ($filter->dateTo !== null) {
            $conditions[] = $queryBuilder->expr()->lte('crdate', $queryBuilder->createNamedParameter($filter->dateTo, Connection::PARAM_INT));
        }
        if ($filter->requestType !== '') {
            $conditions[] = $queryBuilder->expr()->eq('request_type', $queryBuilder->createNamedParameter($filter->requestType));
        }
        if ($filter->botName !== '') {
            $conditions[] = $queryBuilder->expr()->eq('bot_name', $queryBuilder->createNamedParameter($filter->botName));
        }
        if ($filter->ip !== '') {
            $conditions[] = $queryBuilder->expr()->like('ip_address', $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($filter->ip) . '%'));
        }
        if ($filter->aiOnly) {
            $conditions[] = $queryBuilder->expr()->eq('is_ai_bot', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT));
        }
        if ($filter->search !== '') {
            $like = $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($filter->search) . '%');
            $conditions[] = $queryBuilder->expr()->or(
                $queryBuilder->expr()->like('path', $like),
                $queryBuilder->expr()->like('user_agent', $like),
            );
        }

        if ($conditions !== []) {
            $queryBuilder->where(...$conditions);
        }

        return $queryBuilder;
    }
}

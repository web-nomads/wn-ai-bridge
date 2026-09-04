<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WebNomads\WnAiBridge\Domain\Model\AssistantLogEntry;
use WebNomads\WnAiBridge\Domain\Model\LogFilter;

/**
 * Storage and retrieval for the assistant question/answer log.
 *
 * Uses the Doctrine query builder directly (no Extbase persistence) because the
 * log is written from the frontend request path and read by a lightweight,
 * custom backend module.
 */
final class AssistantLogRepository
{
    public const TABLE = 'tx_wnaibridge_assistant_log';

    private readonly ConnectionPool $connectionPool;

    public function __construct(?ConnectionPool $connectionPool = null)
    {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
    }

    /**
     * Persist one log entry.
     *
     * @param array<string, mixed> $data
     */
    public function add(array $data): void
    {
        $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->insert(self::TABLE, $data);
    }

    /**
     * @return list<AssistantLogEntry>
     */
    public function findByFilter(LogFilter $filter): array
    {
        $queryBuilder = $this->createFilteredQuery($filter);

        $rows = $queryBuilder
            ->select('*')
            ->orderBy('crdate', 'DESC')
            ->setMaxResults($filter->perPage)
            ->setFirstResult($filter->getOffset())
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn(array $row): AssistantLogEntry => AssistantLogEntry::fromRow($row),
            $rows
        );
    }

    /**
     * All turns of one conversation, oldest first.
     *
     * @return list<AssistantLogEntry>
     */
    public function findByConversation(string $conversationId): array
    {
        if ($conversationId === '') {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'conversation_id',
                    $queryBuilder->createNamedParameter($conversationId)
                )
            )
            ->orderBy('crdate', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn(array $row): AssistantLogEntry => AssistantLogEntry::fromRow($row),
            $rows
        );
    }

    /**
     * Number of distinct conversation threads matching the filter.
     */
    public function countThreads(LogFilter $filter): int
    {
        $queryBuilder = $this->createFilteredQuery($filter);
        $row = $queryBuilder
            ->addSelectLiteral('COUNT(DISTINCT conversation_id) AS c')
            ->executeQuery()
            ->fetchAssociative() ?: [];

        return (int)($row['c'] ?? 0);
    }

    /**
     * Conversation ids of the threads on the current page, most recently active
     * first. A thread matches when at least one of its turns matches the filter.
     *
     * @return list<string>
     */
    public function findThreadConversationIds(LogFilter $filter): array
    {
        $queryBuilder = $this->createFilteredQuery($filter);
        $rows = $queryBuilder
            ->select('conversation_id')
            ->addSelectLiteral('MAX(crdate) AS last_activity')
            ->groupBy('conversation_id')
            ->orderBy('last_activity', 'DESC')
            ->setMaxResults($filter->perPage)
            ->setFirstResult($filter->getOffset())
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): string => (string)$row['conversation_id'], $rows);
    }

    /**
     * All turns for the given conversations (unfiltered), oldest first, so a
     * whole thread can be displayed once it has been selected for the page.
     *
     * @param list<string> $conversationIds
     * @return list<AssistantLogEntry>
     */
    public function findByConversations(array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->in(
                    'conversation_id',
                    $queryBuilder->createNamedParameter($conversationIds, Connection::PARAM_STR_ARRAY)
                )
            )
            ->orderBy('crdate', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn(array $row): AssistantLogEntry => AssistantLogEntry::fromRow($row),
            $rows
        );
    }

    public function findByUid(int $uid): ?AssistantLogEntry
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? AssistantLogEntry::fromRow($row) : null;
    }

    public function countByFilter(LogFilter $filter): int
    {
        $queryBuilder = $this->createFilteredQuery($filter);

        return (int)$queryBuilder
            ->count('uid')
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Aggregated statistics for the current filter: totals, mode split, token
     * usage and a per-provider breakdown.
     *
     * @return array{
     *     total: int,
     *     llm: int,
     *     search: int,
     *     inputTokens: int,
     *     outputTokens: int,
     *     totalTokens: int,
     *     providers: list<array{provider: string, count: int, totalTokens: int}>
     * }
     */
    public function getStatistics(LogFilter $filter): array
    {
        $queryBuilder = $this->createFilteredQuery($filter);
        $totals = $queryBuilder
            ->addSelectLiteral(
                'COUNT(uid) AS total',
                'COALESCE(SUM(input_tokens), 0) AS input_tokens',
                'COALESCE(SUM(output_tokens), 0) AS output_tokens',
                'COALESCE(SUM(total_tokens), 0) AS total_tokens'
            )
            ->executeQuery()
            ->fetchAssociative() ?: [];

        // Mode split (llm vs. search).
        $modeQuery = $this->createFilteredQuery($filter);
        $modeRows = $modeQuery
            ->select('mode')
            ->addSelectLiteral('COUNT(uid) AS cnt')
            ->groupBy('mode')
            ->executeQuery()
            ->fetchAllAssociative();

        $modeCounts = [];
        foreach ($modeRows as $row) {
            $modeCounts[(string)$row['mode']] = (int)$row['cnt'];
        }

        // Per-provider breakdown.
        $providerQuery = $this->createFilteredQuery($filter);
        $providerRows = $providerQuery
            ->select('provider')
            ->addSelectLiteral('COUNT(uid) AS cnt', 'COALESCE(SUM(total_tokens), 0) AS tokens')
            ->andWhere(
                $providerQuery->expr()->neq('provider', $providerQuery->createNamedParameter(''))
            )
            ->groupBy('provider')
            ->orderBy('cnt', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        $providers = array_map(
            static fn(array $row): array => [
                'provider' => (string)$row['provider'],
                'count' => (int)$row['cnt'],
                'totalTokens' => (int)$row['tokens'],
            ],
            $providerRows
        );

        return [
            'total' => (int)($totals['total'] ?? 0),
            'llm' => $modeCounts['llm'] ?? 0,
            'search' => $modeCounts['search'] ?? 0,
            'inputTokens' => (int)($totals['input_tokens'] ?? 0),
            'outputTokens' => (int)($totals['output_tokens'] ?? 0),
            'totalTokens' => (int)($totals['total_tokens'] ?? 0),
            'providers' => $providers,
        ];
    }

    /**
     * Token totals grouped by model, for estimating total cost.
     *
     * @return list<array{model: string, inputTokens: int, outputTokens: int}>
     */
    public function getModelTokenTotals(LogFilter $filter): array
    {
        $queryBuilder = $this->createFilteredQuery($filter);
        $rows = $queryBuilder
            ->select('model')
            ->addSelectLiteral(
                'COALESCE(SUM(input_tokens), 0) AS in_tokens',
                'COALESCE(SUM(output_tokens), 0) AS out_tokens'
            )
            ->groupBy('model')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn(array $row): array => [
                'model' => (string)$row['model'],
                'inputTokens' => (int)$row['in_tokens'],
                'outputTokens' => (int)$row['out_tokens'],
            ],
            $rows
        );
    }

    /**
     * Distinct providers present in the log, for the filter dropdown.
     *
     * @return list<string>
     */
    public function findDistinctProviders(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('provider')
            ->distinct()
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->neq('provider', $queryBuilder->createNamedParameter('')))
            ->orderBy('provider', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map('strval', $rows);
    }

    /**
     * Delete one conversation, all of its turns. Returns the number of removed
     * rows.
     *
     * A thread is what the module shows and what an editor recognises, so it is
     * also what they get to remove — a single wrong question asked by a visitor
     * should not have to be dealt with by emptying the whole log.
     */
    public function deleteByConversation(string $conversationId): int
    {
        if ($conversationId === '') {
            return 0;
        }

        return $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->delete(self::TABLE, ['conversation_id' => $conversationId]);
    }

    /**
     * Delete all log entries. Returns the number of removed rows.
     */
    public function deleteAll(): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $count = (int)$connection->count('uid', self::TABLE, []);
        $connection->truncate(self::TABLE);
        return $count;
    }

    private function createFilteredQuery(LogFilter $filter): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->from(self::TABLE);

        $conditions = $this->filterConditions($filter, $queryBuilder);
        if ($conditions !== []) {
            $queryBuilder->where(...$conditions);
        }

        return $queryBuilder;
    }

    /**
     * @return list<string>
     */
    private function filterConditions(LogFilter $filter, QueryBuilder $queryBuilder): array
    {
        $conditions = [];

        if ($filter->dateFrom !== null) {
            $conditions[] = $queryBuilder->expr()->gte(
                'crdate',
                $queryBuilder->createNamedParameter($filter->dateFrom, Connection::PARAM_INT)
            );
        }
        if ($filter->dateTo !== null) {
            $conditions[] = $queryBuilder->expr()->lte(
                'crdate',
                $queryBuilder->createNamedParameter($filter->dateTo, Connection::PARAM_INT)
            );
        }
        if ($filter->ip !== '') {
            $conditions[] = $queryBuilder->expr()->like(
                'ip_address',
                $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($filter->ip) . '%')
            );
        }
        if ($filter->provider !== '') {
            $conditions[] = $queryBuilder->expr()->eq(
                'provider',
                $queryBuilder->createNamedParameter($filter->provider)
            );
        }
        if ($filter->mode !== '') {
            $conditions[] = $queryBuilder->expr()->eq(
                'mode',
                $queryBuilder->createNamedParameter($filter->mode)
            );
        }
        if ($filter->site !== '') {
            $conditions[] = $queryBuilder->expr()->eq(
                'site_identifier',
                $queryBuilder->createNamedParameter($filter->site)
            );
        }
        if ($filter->search !== '') {
            $like = $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($filter->search) . '%');
            $conditions[] = $queryBuilder->expr()->or(
                $queryBuilder->expr()->like('question', $like),
                $queryBuilder->expr()->like('answer', $like)
            );
        }

        return $conditions;
    }
}

<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Agent;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Visits of AI crawlers and of visitors referred by AI platforms
 */
final class VisitRepository
{
    public const TABLE = 'tx_wnaibridge_agent_visit';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array{time: int, site: string, host: string, path: string, type: string, status: int, kind: string, agent: string, purpose: string, ipHash: string, verified: int, source: string, lineHash?: string} $visit
     */
    public function add(array $visit): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'crdate' => $visit['time'],
            'site_identifier' => mb_substr($visit['site'], 0, 128),
            'host' => mb_substr(strtolower($visit['host']), 0, 255),
            'path' => mb_substr($visit['path'], 0, 1000),
            'request_type' => $visit['type'],
            'status' => $visit['status'],
            'kind' => $visit['kind'],
            'agent' => mb_substr($visit['agent'], 0, 60),
            'purpose' => $visit['purpose'],
            'ip_hash' => $visit['ipHash'],
            'verified' => $visit['verified'],
            'source' => $visit['source'],
            'line_hash' => $visit['lineHash'] ?? '',
        ]);
    }

    public function hasLine(string $lineHash): bool
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE)->count('uid', self::TABLE, ['line_hash' => $lineHash]) > 0;
    }

    /**
     * Verification stored for an IP hash since the given time, null when none
     */
    public function recentVerification(string $ipHash, string $agent, int $since): ?int
    {
        if ($ipHash === '') {
            return null;
        }
        $queryBuilder = $this->queryBuilder();
        $value = $queryBuilder->select('verified')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('ip_hash', $queryBuilder->createNamedParameter($ipHash)),
                $queryBuilder->expr()->eq('agent', $queryBuilder->createNamedParameter($agent)),
                $queryBuilder->expr()->gte('crdate', $queryBuilder->createNamedParameter($since, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('verified', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $value === false ? null : (int)$value;
    }

    /**
     * Visits in a time window, oldest first; empty site = all sites
     *
     * @return list<array{time: int, site: string, host: string, path: string, type: string, status: int, kind: string, agent: string, purpose: string, verified: int}>
     */
    public function findInPeriod(int $from, int $to, string $site = ''): array
    {
        $queryBuilder = $this->queryBuilder();
        $queryBuilder->select('crdate', 'site_identifier', 'host', 'path', 'request_type', 'status', 'kind', 'agent', 'purpose', 'verified')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->gte('crdate', $queryBuilder->createNamedParameter($from, Connection::PARAM_INT)),
                $queryBuilder->expr()->lte('crdate', $queryBuilder->createNamedParameter($to, Connection::PARAM_INT))
            )
            ->orderBy('crdate')
            ->addOrderBy('uid');
        if ($site !== '') {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($site)));
        }

        return array_map(static fn(array $row): array => [
            'time' => (int)$row['crdate'],
            'site' => (string)$row['site_identifier'],
            'host' => (string)$row['host'],
            'path' => (string)$row['path'],
            'type' => (string)$row['request_type'],
            'status' => (int)$row['status'],
            'kind' => (string)$row['kind'],
            'agent' => (string)$row['agent'],
            'purpose' => (string)$row['purpose'],
            'verified' => (int)$row['verified'],
        ], $queryBuilder->executeQuery()->fetchAllAssociative());
    }

    public function purgeOlderThan(int $timestamp): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return $queryBuilder->delete(self::TABLE)
            ->where($queryBuilder->expr()->lt('crdate', $queryBuilder->createNamedParameter($timestamp, Connection::PARAM_INT)))
            ->executeStatement();
    }

    public function deleteAll(): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->truncate(self::TABLE);
    }

    private function queryBuilder(): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder;
    }
}

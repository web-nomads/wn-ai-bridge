<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WebNomads\WnAiBridge\Domain\Model\AssistantLearning;

/**
 * Storage and retrieval for the local learning source: visitor corrections
 * captured from the frontend and question/answer pairs maintained in the backend
 * module. Only approved entries are ever read back into an answer.
 *
 * Relevance ranking deliberately lives in {@see \WebNomads\WnAiBridge\Service\LearningService};
 * this class only bounds the candidate set in SQL so the matching stays portable
 * across database platforms.
 */
final class AssistantLearningRepository
{
    public const TABLE = 'tx_wnaibridge_assistant_learning';

    /**
     * Upper bound for the approved entries considered for one question. Large
     * enough for any hand-maintained knowledge base, small enough to stay cheap.
     */
    private const CANDIDATE_LIMIT = 300;

    private readonly ConnectionPool $connectionPool;

    public function __construct(?ConnectionPool $connectionPool = null)
    {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
    }

    /**
     * @param array<string, mixed> $data
     * @return int uid of the created entry
     */
    public function add(array $data): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, $data);

        return (int)$connection->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $uid, array $data): void
    {
        $data['tstamp'] = time();
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(self::TABLE, $data, ['uid' => $uid]);
    }

    public function setStatus(int $uid, string $status): void
    {
        $this->update($uid, ['status' => $status]);
    }

    public function delete(int $uid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->delete(self::TABLE, ['uid' => $uid]);
    }

    public function findByUid(int $uid): ?AssistantLearning
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? AssistantLearning::fromRow($row) : null;
    }

    /**
     * @param string $siteIdentifier Limit to one site; '' for all of them.
     * @return list<AssistantLearning>
     */
    public function findByStatus(string $status, int $limit = 100, string $siteIdentifier = ''): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter($status)))
            ->orderBy('crdate', 'DESC')
            ->setMaxResults($limit);

        if ($siteIdentifier !== '') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'site_identifier',
                    $queryBuilder->createNamedParameter($siteIdentifier)
                )
            );
        }

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        return array_map(static fn(array $row): AssistantLearning => AssistantLearning::fromRow($row), $rows);
    }

    public function countByStatus(string $status): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter($status)))
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * All approved entries of a site/language, newest first, as the candidate set
     * for relevance matching.
     *
     * @return list<AssistantLearning>
     */
    public function findApproved(string $siteIdentifier, int $languageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'status',
                    $queryBuilder->createNamedParameter(AssistantLearning::STATUS_APPROVED)
                ),
                $queryBuilder->expr()->eq(
                    'site_identifier',
                    $queryBuilder->createNamedParameter($siteIdentifier)
                ),
                $queryBuilder->expr()->eq(
                    'language_uid',
                    $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)
                ),
            )
            ->orderBy('crdate', 'DESC')
            ->setMaxResults(self::CANDIDATE_LIMIT)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): AssistantLearning => AssistantLearning::fromRow($row), $rows);
    }

    /**
     * Distinct site identifiers that already have entries — used to pre-fill the
     * site selector in the backend module.
     *
     * @return list<string>
     */
    public function findDistinctSiteIdentifiers(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->selectLiteral('DISTINCT site_identifier')
            ->from(self::TABLE)
            ->orderBy('site_identifier', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_filter(array_map(strval(...), $rows), static fn(string $v): bool => $v !== ''));
    }
}

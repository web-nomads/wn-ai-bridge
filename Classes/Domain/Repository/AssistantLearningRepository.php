<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WebNomads\WnAiBridge\Domain\Model\AssistantLearning;

/**
 * Storage and retrieval for owner-moderated learning entries (visitor
 * corrections). Written from the frontend request path, moderated in a backend
 * module, and read back (approved only) to enrich future answers.
 */
final class AssistantLearningRepository
{
    public const TABLE = 'tx_wnaibridge_assistant_learning';

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

    public function setStatus(int $uid, string $status): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            ['status' => $status, 'tstamp' => time()],
            ['uid' => $uid],
        );
    }

    public function delete(int $uid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->delete(self::TABLE, ['uid' => $uid]);
    }

    /**
     * @return list<AssistantLearning>
     */
    public function findByStatus(string $status, int $limit = 100): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter($status)))
            ->orderBy('crdate', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

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
     * Approved corrections for a site/language whose stored keywords overlap the
     * given query terms. Matching is a simple keyword-overlap done in PHP so it
     * stays portable; the candidate set is bounded first in SQL.
     *
     * @param list<string> $terms
     * @return list<AssistantLearning>
     */
    public function findApprovedMatching(array $terms, string $siteIdentifier, int $languageUid, int $limit = 3): array
    {
        if ($terms === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter(AssistantLearning::STATUS_APPROVED)),
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                $queryBuilder->expr()->eq('language_uid', $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)),
            )
            ->orderBy('crdate', 'DESC')
            ->setMaxResults(200)
            ->executeQuery()
            ->fetchAllAssociative();

        $scored = [];
        foreach ($rows as $row) {
            $keywords = array_filter(explode(' ', mb_strtolower((string)($row['keywords'] ?? ''))));
            if ($keywords === []) {
                continue;
            }
            $overlap = 0;
            foreach ($terms as $term) {
                if (in_array(mb_strtolower($term), $keywords, true)) {
                    $overlap++;
                }
            }
            if ($overlap > 0) {
                $scored[] = ['overlap' => $overlap, 'entry' => AssistantLearning::fromRow($row)];
            }
        }

        usort($scored, static fn(array $a, array $b): int => $b['overlap'] <=> $a['overlap']);

        return array_map(
            static fn(array $item): AssistantLearning => $item['entry'],
            array_slice($scored, 0, $limit),
        );
    }
}

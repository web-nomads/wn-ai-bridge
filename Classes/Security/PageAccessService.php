<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Security;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Decides whether a page may be shown, the way the frontend decides it.
 *
 * The search indexes are not the authority on this: ke_search and
 * indexed_search keep a record of a page long after it was disabled or put
 * behind a login, and both are queried without the frontend's own restrictions.
 * Every page id an index hands back is therefore checked against the pages
 * table here before it reaches a visitor.
 *
 * Two questions are asked, and they are not the same one:
 *
 * - isAccessible() is about the current request, so a member page surfaces for
 *   the member who is logged in and for nobody else.
 * - isPublic() is about a request without a login, which is what llms.txt, the
 *   full document and every crawler are.
 */
class PageAccessService
{
    /**
     * The frontend groups a request without a login carries: "everybody" and
     * "hide at login".
     *
     * @var list<int>
     */
    public const ANONYMOUS_GROUPS = [0, -1];

    /**
     * Guards against a cycle in a corrupted page tree.
     */
    private const MAX_ROOTLINE_DEPTH = 50;

    /**
     * @var list<string>
     */
    private const ROW_FIELDS = [
        'uid',
        'pid',
        'deleted',
        'hidden',
        'starttime',
        'endtime',
        'fe_group',
        'extendToSubpages',
    ];

    private readonly ConnectionPool $connectionPool;
    private readonly Context $context;

    /**
     * Verdicts already reached in this request, keyed by page id and group list.
     *
     * @var array<string, bool>
     */
    private array $verdicts = [];

    /**
     * Page rows already read in this request.
     *
     * @var array<int, array<string, mixed>|null>
     */
    private array $rows = [];

    public function __construct(
        ?ConnectionPool $connectionPool = null,
        ?Context $context = null
    ) {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->context = $context ?? GeneralUtility::makeInstance(Context::class);
    }

    /**
     * Whether the page may be shown to whoever is making the current request.
     */
    public function isAccessible(int $pageId): bool
    {
        return $this->check($pageId, $this->currentGroupIds());
    }

    /**
     * Whether the page is visible without logging in.
     */
    public function isPublic(int $pageId): bool
    {
        return $this->check($pageId, self::ANONYMOUS_GROUPS);
    }

    /**
     * Whether one page row passes the checks that decide visibility: not
     * deleted, not disabled, inside its publication window, and restricted at
     * most to a group the request carries.
     *
     * Static so the page tree walk in the repository can apply the same rules to
     * rows it has already read, without asking the database twice.
     *
     * @param array<string, mixed> $row
     * @param list<int> $groupIds
     */
    public static function isVisibleRow(array $row, array $groupIds, int $now): bool
    {
        if ((int)($row['deleted'] ?? 0) === 1 || (int)($row['hidden'] ?? 0) === 1) {
            return false;
        }

        $startTime = (int)($row['starttime'] ?? 0);
        if ($startTime > 0 && $startTime > $now) {
            return false;
        }

        $endTime = (int)($row['endtime'] ?? 0);
        if ($endTime > 0 && $endTime <= $now) {
            return false;
        }

        return self::groupAccessGranted((string)($row['fe_group'] ?? ''), $groupIds);
    }

    /**
     * The moment visibility is judged against.
     */
    public function now(): int
    {
        try {
            $timestamp = (int)$this->context->getPropertyFromAspect('date', 'timestamp', 0);
        } catch (\Throwable $e) {
            $timestamp = 0;
        }

        return $timestamp > 0 ? $timestamp : time();
    }

    /**
     * The frontend groups of the current request, falling back to the anonymous
     * ones wherever no frontend user has been resolved.
     *
     * @return list<int>
     */
    public function currentGroupIds(): array
    {
        try {
            $groupIds = $this->context->getPropertyFromAspect('frontend.user', 'groupIds', self::ANONYMOUS_GROUPS);
        } catch (\Throwable $e) {
            return self::ANONYMOUS_GROUPS;
        }

        if (!is_array($groupIds) || $groupIds === []) {
            return self::ANONYMOUS_GROUPS;
        }

        return array_values(array_map('intval', $groupIds));
    }

    /**
     * @param list<int> $groupIds
     */
    private function check(int $pageId, array $groupIds): bool
    {
        if ($pageId <= 0) {
            return false;
        }

        $key = $pageId . ':' . implode(',', $groupIds);
        if (isset($this->verdicts[$key])) {
            return $this->verdicts[$key];
        }

        return $this->verdicts[$key] = $this->resolve($pageId, $groupIds);
    }

    /**
     * @param list<int> $groupIds
     */
    private function resolve(int $pageId, array $groupIds): bool
    {
        $now = $this->now();

        $page = $this->fetchRow($pageId);
        if ($page === null || !self::isVisibleRow($page, $groupIds, $now)) {
            return false;
        }

        // A restriction set on an ancestor only reaches down when it is marked
        // to — that is what "extendToSubpages" means, and it is how the frontend
        // itself resolves the rootline. A deleted ancestor always cuts the
        // branch off, marked or not.
        $parentId = (int)$page['pid'];
        for ($depth = 0; $depth < self::MAX_ROOTLINE_DEPTH && $parentId > 0; $depth++) {
            $parent = $this->fetchRow($parentId);
            if ($parent === null || (int)($parent['deleted'] ?? 0) === 1) {
                return false;
            }

            if ((int)($parent['extendToSubpages'] ?? 0) === 1
                && !self::isVisibleRow($parent, $groupIds, $now)
            ) {
                return false;
            }

            $parentId = (int)$parent['pid'];
        }

        return true;
    }

    /**
     * @param list<int> $groupIds
     */
    private static function groupAccessGranted(string $feGroup, array $groupIds): bool
    {
        $feGroup = trim($feGroup);
        if ($feGroup === '' || $feGroup === '0') {
            return true;
        }

        $required = GeneralUtility::intExplode(',', $feGroup, true);
        foreach ($groupIds as $groupId) {
            if (in_array($groupId, $required, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The raw page row, read without any restriction — the checks above are the
     * ones that decide, and they must see a disabled page rather than no page.
     *
     * @return array<string, mixed>|null
     */
    private function fetchRow(int $pageId): ?array
    {
        if (array_key_exists($pageId, $this->rows)) {
            return $this->rows[$pageId];
        }

        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()->removeAll();

            $row = $queryBuilder
                ->select(...self::ROW_FIELDS)
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT))
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Throwable $e) {
            return $this->rows[$pageId] = null;
        }

        return $this->rows[$pageId] = $row !== false ? $row : null;
    }
}

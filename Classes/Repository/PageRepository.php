<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Repository;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspectFactory;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Domain\Repository\PageRepository as CorePageRepository;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WebNomads\WnAiBridge\Security\PageAccessService;

/**
 * Repository for fetching page data
 * Handles all database queries related to pages using the Repository pattern
 * Uses Core's PageRepository for proper language fallback handling
 *
 * What it hands out is what a visitor without a login would see: the navigation
 * lookups drop disabled pages, pages outside their publication window and pages
 * behind a frontend group, together with everything below them. The documents
 * built from it — llms.txt, llms-full.txt, the Markdown export — are public and
 * cached for everyone, so they must not depend on who happened to request them.
 */
class PageRepository
{
    /**
     * Maximum recursion depth to prevent infinite loops from corrupted data
     */
    private const MAX_RECURSION_DEPTH = 50;

    /**
     * Maximum pages to load in a single batch query (performance limit)
     */
    private const MAX_BATCH_SIZE = 1000;

    /**
     * Doktypes that should be excluded but their children should still be fetched
     */
    private const EXCLUDED_DOKTYPES = [
        CorePageRepository::DOKTYPE_SYSFOLDER,
        CorePageRepository::DOKTYPE_SPACER,
        CorePageRepository::DOKTYPE_SHORTCUT,
    ];

    /**
     * Fields to select for all page queries
     */
    private const PAGE_FIELDS = [
        'uid',
        'pid',
        'title',
        'subtitle',
        'seo_title',
        'description',
        'abstract',
        'nav_title',
        'doktype',
        'sys_language_uid',
        'l10n_parent',
        'slug',
        'l10n_diffsource',
        'hidden',
        'starttime',
        'endtime',
        'fe_group',
        'extendToSubpages',
    ];

    private readonly ConnectionPool $connectionPool;
    private readonly Context $context;

    public function __construct(
        ?ConnectionPool $connectionPool = null,
        ?Context $context = null
    ) {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->context = $context ?? GeneralUtility::makeInstance(Context::class);
    }

    /**
     * Find a page by its UID with optional language support
     *
     * @return array{uid: int, title: string, subtitle: string, seo_title: string, description: string, abstract: string}|array{}
     */
    public function findById(int $pageId, int $languageUid = 0): array
    {
        // First try to find the page by UID to see if it already has the correct language
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        $result = $queryBuilder
            ->select(...self::PAGE_FIELDS)
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT))
            )
            ->executeQuery();

        $page = $result->fetchAssociative();

        if ($page) {
            $currentLanguageUid = (int)$page['sys_language_uid'];

            // Case: We want default language (0) but have a translation
            if ($languageUid === 0 && $currentLanguageUid !== 0 && (int)$page['l10n_parent'] > 0) {
                $defaultPage = $this->fetchRawById((int)$page['l10n_parent']);
                if (!empty($defaultPage)) {
                    return $this->mapRowToPageArray($defaultPage);
                }
            }

            // Case: Language matches exactly
            if ($languageUid === $currentLanguageUid) {
                return $this->mapRowToPageArray($page);
            }

            // Case: We want a specific translation (languageUid > 0)
            if ($languageUid > 0) {
                $originalId = (int)$page['l10n_parent'] > 0 ? (int)$page['l10n_parent'] : (int)$page['uid'];
                $localizedPage = $this->getLocalizedPage($originalId, $languageUid);
                if (!empty($localizedPage)) {
                    return $this->mapRowToPageArray($localizedPage);
                }
            }

            // Final fallback: return the page we found first
            return $this->mapRowToPageArray($page);
        }

        return [];
    }

    /**
     * Fetch raw page row by UID without restriction mapping
     */
    private function fetchRawById(int $uid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        return $queryBuilder
            ->select(...self::PAGE_FIELDS)
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAssociative() ?: [];
    }

    /**
     * Fetch localized page by l10n_parent and sys_language_uid
     * Falls back to unrestricted query if FrontendRestrictionContainer filters result
     *
     * @return array<string, mixed>
     */
    private function getLocalizedPage(int $pageId, int $languageUid): array
    {
        // First try with FrontendRestrictionContainer
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        $result = $queryBuilder
            ->select(...self::PAGE_FIELDS)
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('l10n_parent', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT))
            )
            ->executeQuery();

        $localizedPage = $result->fetchAssociative();
        if ($localizedPage) {
            return $localizedPage;
        }

        // Fallback: query without FrontendRestrictionContainer
        $queryBuilder2 = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder2->getRestrictions()->removeAll();

        $result2 = $queryBuilder2
            ->select(...self::PAGE_FIELDS)
            ->from('pages')
            ->where(
                $queryBuilder2->expr()->eq('l10n_parent', $queryBuilder2->createNamedParameter($pageId, Connection::PARAM_INT)),
                $queryBuilder2->expr()->eq('sys_language_uid', $queryBuilder2->createNamedParameter($languageUid, Connection::PARAM_INT))
            )
            ->executeQuery();

        return $result2->fetchAssociative() ?: [];
    }

    /**
     * Find navigation pages by parent UID (all languages)
     *
     * Note: For language-aware fetching with proper fallback handling,
     * use findNavigationByParentWithFallback() instead.
     *
     * @param int $parentUid Parent page UID
     * @return array<int, array{uid: int, pid: int, title: string, description: string, abstract: string, doktype: int, sys_language_uid: int, l10n_parent: int}>
     */
    public function findNavigationByParent(int $parentUid): array
    {
        return $this->findNavigationPages($parentUid, null, []);
    }

    /**
     * Find navigation pages by parent UID filtered by language
     *
     * @param int $parentUid Parent page UID
     * @param int $languageUid Language UID to filter by
     * @return array<int, array{uid: int, pid: int, title: string, description: string, abstract: string, doktype: int, sys_language_uid: int, l10n_parent: int}>
     *
     * @deprecated since 0.3.0, will be removed in 1.0.0. Use findNavigationByParentWithFallback() with SiteLanguage for proper language fallback handling.
     */
    public function findNavigationByParentAndLanguage(int $parentUid, int $languageUid = 0): array
    {
        return $this->findNavigationPages($parentUid, $languageUid, []);
    }

    /**
     * Internal method to find navigation pages with recursion protection
     *
     * @param int $parentUid Parent page UID
     * @param int|null $languageUid Language UID to filter by (null = all languages)
     * @param array<int, bool> $visitedUids UIDs already visited to prevent infinite recursion
     * @param int $currentDepth Current recursion depth
     * @return array<int, array{uid: int, pid: int, title: string, description: string, abstract: string, doktype: int, sys_language_uid: int, l10n_parent: int}>
     */
    protected function findNavigationPages(
        int $parentUid,
        ?int $languageUid,
        array $visitedUids,
        int $currentDepth = 0
    ): array {
        // Prevent infinite recursion
        if ($currentDepth >= self::MAX_RECURSION_DEPTH) {
            return [];
        }

        if (isset($visitedUids[$parentUid])) {
            return [];
        }
        $visitedUids[$parentUid] = true;

        $queryBuilder = $this->createNavigationQueryBuilder($parentUid, $languageUid);
        $result = $queryBuilder->executeQuery();

        $now = $this->now();

        $pages = [];
        while ($row = $result->fetchAssociative()) {
            // Disabled, expired and access-restricted pages are left out with
            // their whole subtree — nothing behind a page a visitor cannot reach
            // belongs in a public document either.
            if (!PageAccessService::isVisibleRow($row, PageAccessService::ANONYMOUS_GROUPS, $now)) {
                continue;
            }

            // Skip folders, spacers, and shortcuts but fetch their subpages
            if (in_array((int)$row['doktype'], self::EXCLUDED_DOKTYPES, true)) {
                // Recursively get children of skipped pages
                $childPages = $this->findNavigationPages(
                    (int)$row['uid'],
                    $languageUid ?? (int)$row['sys_language_uid'],
                    $visitedUids,
                    $currentDepth + 1
                );
                foreach ($childPages as $childPage) {
                    $pages[] = $childPage;
                }
                continue;
            }

            $pages[] = $this->mapRowToPageArray($row);
        }

        return $pages;
    }

    /**
     * Create a query builder for navigation queries
     */
    protected function createNavigationQueryBuilder(int $parentUid, ?int $languageUid): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');

        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        $queryBuilder
            ->select(...self::PAGE_FIELDS)
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($parentUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('nav_hide', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('sorting');

        // Add language filter if specified
        if ($languageUid !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT))
            );
        }

        return $queryBuilder;
    }

    /**
     * Map a database row to a page array
     *
     * @return array<string, mixed>
     */
    protected function mapRowToPageArray(array $row): array
    {
        return [
            'uid' => (int)$row['uid'],
            'pid' => (int)$row['pid'],
            'title' => $row['title'] ?? '',
            'nav_title' => $row['nav_title'] ?? '',
            'subtitle' => $row['subtitle'] ?? '',
            'seo_title' => $row['seo_title'] ?? '',
            'description' => $row['description'] ?? '',
            'abstract' => $row['abstract'] ?? '',
            'doktype' => (int)$row['doktype'],
            'sys_language_uid' => (int)$row['sys_language_uid'],
            'l10n_parent' => (int)$row['l10n_parent'],
            'slug' => $row['slug'] ?? '',
            'l10n_diffsource' => $row['l10n_diffsource'] ?? '',
            'hidden' => (int)($row['hidden'] ?? 0),
            'starttime' => (int)($row['starttime'] ?? 0),
            'endtime' => (int)($row['endtime'] ?? 0),
            'fe_group' => (string)($row['fe_group'] ?? ''),
            'extendToSubpages' => (int)($row['extendToSubpages'] ?? 0),
        ];
    }

    /**
     * Batch fetch all navigation pages for multiple parent UIDs in a single query
     * This significantly reduces database queries for large sites (solves N+1 problem)
     *
     * @param array<int> $parentUids Array of parent page UIDs
     * @param int|null $languageUid Language UID to filter by (null = all languages)
     * @return array<int, array<int, array>> Pages grouped by parent UID
     */
    public function findNavigationByParentsBatch(array $parentUids, ?int $languageUid = null): array
    {
        if (empty($parentUids)) {
            return [];
        }

        // Limit batch size to prevent memory issues
        $parentUids = array_slice($parentUids, 0, self::MAX_BATCH_SIZE);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        $queryBuilder
            ->select(...self::PAGE_FIELDS)
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('nav_hide', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('pid')
            ->addOrderBy('sorting');

        if ($languageUid !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT))
            );
        }

        $result = $queryBuilder->executeQuery();

        // Group results by parent UID
        $now = $this->now();
        $grouped = [];
        while ($row = $result->fetchAssociative()) {
            if (!PageAccessService::isVisibleRow($row, PageAccessService::ANONYMOUS_GROUPS, $now)) {
                continue;
            }

            $pid = (int)$row['pid'];
            if (!isset($grouped[$pid])) {
                $grouped[$pid] = [];
            }
            $grouped[$pid][] = $this->mapRowToPageArray($row);
        }

        return $grouped;
    }

    /**
     * Find navigation pages with proper language fallback handling
     * Uses Core's PageRepository for overlay logic
     *
     * @param int $parentUid Parent page UID
     * @param SiteLanguage $siteLanguage The site language to fetch pages for
     * @return array<int, array{uid: int, pid: int, title: string, description: string, abstract: string, doktype: int, sys_language_uid: int, l10n_parent: int}>
     */
    public function findNavigationByParentWithFallback(int $parentUid, SiteLanguage $siteLanguage): array
    {
        // Fetch default language pages first
        $defaultLanguagePages = $this->findNavigationPages($parentUid, 0, []);

        if (empty($defaultLanguagePages)) {
            return [];
        }

        // If default language requested, return as-is
        if ($siteLanguage->getLanguageId() === 0) {
            return $defaultLanguagePages;
        }

        // Create Core PageRepository with proper language context for overlays
        $corePageRepository = $this->createCorePageRepository($siteLanguage);

        // Apply language overlays using Core's method
        $overlaidPages = $corePageRepository->getPagesOverlay($defaultLanguagePages);

        // Filter pages based on language visibility
        $languageAspect = LanguageAspectFactory::createFromSiteLanguage($siteLanguage);
        $now = $this->now();
        $result = [];

        foreach ($overlaidPages as $page) {
            // Checked again after the overlay: a translation carries its own
            // "disable" flag and publication window, and only the overlaid row
            // shows them.
            if (!PageAccessService::isVisibleRow($page, PageAccessService::ANONYMOUS_GROUPS, $now)) {
                continue;
            }

            if ($corePageRepository->isPageSuitableForLanguage($page, $languageAspect)) {
                $result[] = $this->mapRowToPageArray($page);
            }
        }

        return $result;
    }

    /**
     * The moment page visibility is judged against.
     */
    protected function now(): int
    {
        $timestamp = (int)$this->context->getPropertyFromAspect('date', 'timestamp', 0);

        return $timestamp > 0 ? $timestamp : time();
    }

    /**
     * Batch fetch pages with language fallback for multiple parents
     *
     * @param array<int> $parentUids Parent page UIDs
     * @param SiteLanguage $siteLanguage The site language
     * @return array<int, array<int, array>> Pages grouped by parent UID with overlays applied
     */
    public function findNavigationByParentsBatchWithFallback(array $parentUids, SiteLanguage $siteLanguage): array
    {
        if (empty($parentUids)) {
            return [];
        }

        // Limit batch size
        $parentUids = array_slice($parentUids, 0, self::MAX_BATCH_SIZE);

        // Fetch default language pages
        $defaultLanguagePages = $this->findNavigationByParentsBatch($parentUids, 0);

        if (empty($defaultLanguagePages)) {
            return [];
        }

        // If default language requested, return as-is
        if ($siteLanguage->getLanguageId() === 0) {
            return $defaultLanguagePages;
        }

        // Flatten pages for overlay processing
        $allPages = [];
        $pageToParentMap = [];
        foreach ($defaultLanguagePages as $pid => $pages) {
            foreach ($pages as $page) {
                $allPages[] = $page;
                $pageToParentMap[$page['uid']] = $pid;
            }
        }

        if (empty($allPages)) {
            return [];
        }

        // Create Core PageRepository with proper language context
        $corePageRepository = $this->createCorePageRepository($siteLanguage);

        // Apply overlays
        $overlaidPages = $corePageRepository->getPagesOverlay($allPages);

        // Filter and regroup by parent
        $languageAspect = LanguageAspectFactory::createFromSiteLanguage($siteLanguage);
        $result = [];

        foreach ($overlaidPages as $page) {
            if (!$corePageRepository->isPageSuitableForLanguage($page, $languageAspect)) {
                continue;
            }

            // Get original UID for parent mapping (before overlay)
            $originalUid = $page['_TRANSLATION_SOURCE']->uid ?? $page['uid'];
            $pid = $pageToParentMap[$originalUid] ?? (int)$page['pid'];

            if (!isset($result[$pid])) {
                $result[$pid] = [];
            }

            $result[$pid][] = $this->mapRowToPageArray($page);
        }

        return $result;
    }

    /**
     * Create a Core PageRepository instance configured for the given site language
     * This handles language overlays with proper fallback chain
     */
    protected function createCorePageRepository(SiteLanguage $siteLanguage): CorePageRepository
    {
        // Clone context to avoid modifying global state
        $context = clone $this->context;
        $context->setAspect(
            'language',
            LanguageAspectFactory::createFromSiteLanguage($siteLanguage)
        );

        return GeneralUtility::makeInstance(CorePageRepository::class, $context);
    }
}

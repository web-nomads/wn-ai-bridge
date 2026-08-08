<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;

class UrlGeneratorService
{
    private readonly SiteFinder $siteFinder;
    private readonly ConfigurationService $configurationService;
    private readonly ConnectionPool $connectionPool;

    public function __construct(
        ?SiteFinder $siteFinder = null,
        ?ConfigurationService $configurationService = null,
        ?ConnectionPool $connectionPool = null
    ) {
        $this->siteFinder = $siteFinder ?? \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(SiteFinder::class);
        $this->configurationService = $configurationService ?? \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ConfigurationService::class);
        $this->connectionPool = $connectionPool ?? \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ConnectionPool::class);
    }

    /**
     * Generate absolute URL for a markdown page.
     * This ALWAYS returns a path-based URL with .md extension, never an anchor.
     */
    public function generatePageUrl(array $page): string
    {
        $site = $this->siteFinder->getSiteByPageId($page['uid']);
        $languageId = (int)($page['sys_language_uid'] ?? 0);

        try {
            $siteLanguage = $site->getLanguageById($languageId);
        } catch (\Exception $e) {
            $siteLanguage = $site->getDefaultLanguage();
        }

        $uri = (string)$site->getRouter()->generateUri(
            $page['uid'],
            ['_language' => $siteLanguage],
            '',
            \TYPO3\CMS\Core\Routing\RouterInterface::ABSOLUTE_URL
        );

        $uri = $this->toAbsoluteUrl($uri);

        // Ensure we don't have anchors in markdown URLs
        if (str_contains($uri, '#')) {
            $uri = explode('#', $uri)[0];
        }

        return rtrim($uri, '/') . '.md';
    }

    /**
     * Generate absolute URL for the HTML version of a page.
     *
     * On a OnePager the direct children of the site root are sections of the
     * home page, so they link to "site/#section". Everywhere else they are real
     * pages and must keep their own URL — which is why this is a per-site
     * setting and not a guess from the page tree.
     *
     * @param array<string, mixed> $page
     * @param bool|null $onePager Overrides the site setting. Null asks the site.
     */
    public function generateHtmlUrl(array $page, ?bool $onePager = null): string
    {
        $onePager ??= $this->configurationService->isLlmsTxtOnePagerEnabled();
        $site = $this->siteFinder->getSiteByPageId($page['uid']);
        $rootPageId = $site->getRootPageId();
        $languageId = (int)($page['sys_language_uid'] ?? 0);

        try {
            $siteLanguage = $site->getLanguageById($languageId);
        } catch (\Exception $e) {
            $siteLanguage = $site->getDefaultLanguage();
        }

        $uri = (string)$site->getRouter()->generateUri(
            $page['uid'],
            ['_language' => $siteLanguage],
            '',
            \TYPO3\CMS\Core\Routing\RouterInterface::ABSOLUTE_URL
        );

        $uri = $this->toAbsoluteUrl($uri);

        // OnePager only: a direct child of the root page is a section of the home
        // page, so link to its anchor instead of its own URL.
        if ($onePager && (int)$page['pid'] === (int)$rootPageId && (int)$page['uid'] !== (int)$rootPageId) {
            $rootUri = (string)$site->getRouter()->generateUri(
                $rootPageId,
                ['_language' => $siteLanguage],
                '',
                \TYPO3\CMS\Core\Routing\RouterInterface::ABSOLUTE_URL
            );

            $rootUri = $this->toAbsoluteUrl($rootUri);

            // Extract the slug from the generated URI by removing the root URI part.
            // This ensures we get the localized slug segment without language prefixes.
            $rootPath = parse_url($rootUri, PHP_URL_PATH) ?: '/';
            $pagePath = parse_url($uri, PHP_URL_PATH) ?: '/';

            $anchor = trim(str_replace($rootPath, '', $pagePath), '/');

            if ($anchor !== '') {
                return rtrim($rootUri, '/') . '/#' . $anchor;
            }
        }

        return $uri;
    }

    /**
     * Generate an absolute HTML URL for a page id, used for search result links.
     *
     * On OnePager sites (enabled per site via aiAssistantOnePager) the sections
     * are direct children of the root page and are rendered as anchors on the
     * homepage, so a hit on such a page must link to "https://site/#section"
     * instead of "https://site/section". On normal multipage sites the flag
     * stays off and real page URLs are produced.
     *
     * Returns an empty string when the page cannot be routed (e.g. deleted or
     * outside any site) so callers can skip the hit.
     *
     * @param array<string, mixed> $arguments Optional route arguments (e.g. the
     *        record parameters indexed_search stored for a hit), so the link can
     *        point at the exact record/detail view instead of the plain page
     *        that hosts the plugin. When given, OnePager anchor rewriting is
     *        skipped and, if the record link cannot be built, generation falls
     *        back to the plain page URL.
     */
    public function generateUrlForPageId(int $pageId, int $languageId = 0, array $arguments = []): string
    {
        if ($pageId <= 0) {
            return '';
        }

        try {
            // Direct record link (indexed_search detail view etc.): keep the
            // record parameters and never collapse them into a OnePager anchor.
            if ($arguments !== []) {
                $url = $this->generatePlainUrlForPageId($pageId, $languageId, $arguments);
                if ($url !== '') {
                    return $url;
                }
                // Record link could not be built — fall through to a page link.
            }

            // The assistant has its own OnePager switch; pass the verdict on so
            // the llms.txt setting does not decide for the chat widget.
            if ($this->configurationService->isAssistantOnePagerEnabled()) {
                $pid = $this->fetchPid($pageId);
                if ($pid !== null) {
                    return $this->generateHtmlUrl([
                        'uid' => $pageId,
                        'pid' => $pid,
                        'sys_language_uid' => $languageId,
                    ], true);
                }
            }

            return $this->generatePlainUrlForPageId($pageId, $languageId);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Plain absolute page URL without any OnePager anchor handling.
     *
     * @param array<string, mixed> $arguments Additional route arguments merged
     *        into the generated URI (record parameters, MP, page type). The
     *        PageRouter applies the site's route enhancers and appends a cHash
     *        where required, producing a working direct link.
     */
    private function generatePlainUrlForPageId(int $pageId, int $languageId, array $arguments = []): string
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
        } catch (\Exception $e) {
            return '';
        }

        try {
            $siteLanguage = $site->getLanguageById($languageId);
        } catch (\Exception $e) {
            $siteLanguage = $site->getDefaultLanguage();
        }

        $routeArguments = $arguments;
        $routeArguments['_language'] = $siteLanguage;

        try {
            $uri = (string)$site->getRouter()->generateUri(
                $pageId,
                $routeArguments,
                '',
                \TYPO3\CMS\Core\Routing\RouterInterface::ABSOLUTE_URL
            );
        } catch (\Exception $e) {
            return '';
        }

        return $this->toAbsoluteUrl($uri);
    }

    /**
     * Make a router result absolute.
     *
     * The router returns a path whenever the site base carries no host (an entry
     * point like "/camino/"). That path already contains the entry point, so only
     * scheme and host may be prefixed — prefixing the site URL would repeat it
     * and produce "/camino/camino/faqs".
     */
    private function toAbsoluteUrl(string $uri): string
    {
        if (!str_starts_with($uri, '/')) {
            return $uri;
        }

        return $this->configurationService->getSiteOrigin() . $uri;
    }

    /**
     * Fetch the parent page id (pid) for a page, needed to detect OnePager
     * sections (direct children of the site root). Returns null when unknown.
     */
    private function fetchPid(int $pageId): ?int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        try {
            $pid = $queryBuilder
                ->select('pid')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT))
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
        } catch (\Throwable $e) {
            return null;
        }

        return $pid !== false ? (int)$pid : null;
    }

}

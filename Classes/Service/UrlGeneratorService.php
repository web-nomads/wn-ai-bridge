<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Service;

use TYPO3\CMS\Core\Site\SiteFinder;

class UrlGeneratorService
{
    private readonly SiteFinder $siteFinder;
    private readonly ConfigurationService $configurationService;

    public function __construct(
        ?SiteFinder $siteFinder = null,
        ?ConfigurationService $configurationService = null
    ) {
        $this->siteFinder = $siteFinder ?? \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(SiteFinder::class);
        $this->configurationService = $configurationService ?? \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ConfigurationService::class);
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

        // Prepend site URL if result is still relative
        if (str_starts_with($uri, '/')) {
            $uri = $this->configurationService->getSiteUrl() . $uri;
        }

        // Ensure we don't have anchors in markdown URLs
        if (str_contains($uri, '#')) {
            $uri = explode('#', $uri)[0];
        }

        return rtrim($uri, '/') . '.md';
    }

    /**
     * Generate absolute URL for the HTML version of a page.
     * Handles OnePage sites by using anchors for direct subpages of the root.
     */
    public function generateHtmlUrl(array $page): string
    {
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

        // Prepend site URL if result is still relative
        if (str_starts_with($uri, '/')) {
            $uri = $this->configurationService->getSiteUrl() . $uri;
        }

        // Handle OnePage anchors: if it's a direct child of the root page
        // we use the slug from the generated URI as an anchor on the root page.
        if ((int)$page['pid'] === (int)$rootPageId && (int)$page['uid'] !== (int)$rootPageId) {
            $rootUri = (string)$site->getRouter()->generateUri(
                $rootPageId,
                ['_language' => $siteLanguage],
                '',
                \TYPO3\CMS\Core\Routing\RouterInterface::ABSOLUTE_URL
            );
            
            if (str_starts_with($rootUri, '/')) {
                $rootUri = $this->configurationService->getSiteUrl() . $rootUri;
            }

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

}

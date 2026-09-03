<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Service;

use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The sites of this installation, as the backend modules need to offer them.
 *
 * Kept in one place because both modules that filter by site have to agree on
 * two things: what a site is called in a dropdown, and when offering the choice
 * at all is worth the space. On an installation with a single website there is
 * nothing to pick between, and a filter with one entry is only in the way.
 */
final class SiteListService
{
    private readonly SiteFinder $siteFinder;

    public function __construct(?SiteFinder $siteFinder = null)
    {
        $this->siteFinder = $siteFinder ?? GeneralUtility::makeInstance(SiteFinder::class);
    }

    /**
     * Identifier => label for every site, or an empty list when there is only
     * one. The modules render their site filter exactly when this has entries,
     * so a single-site installation never sees it.
     *
     * @return array<string, string>
     */
    public function getFilterOptions(): array
    {
        $sites = $this->allSites();
        if (count($sites) < 2) {
            return [];
        }

        $options = [];
        foreach ($sites as $site) {
            $options[$site->getIdentifier()] = self::label($site);
        }

        asort($options);

        return $options;
    }

    /**
     * Whether the given identifier belongs to a site of this installation.
     * Everything else is refused rather than searched for, so a hand-written URL
     * cannot turn the filter into a way of probing the log.
     */
    public function isKnownIdentifier(string $identifier): bool
    {
        if ($identifier === '') {
            return false;
        }

        foreach ($this->allSites() as $site) {
            if ($site->getIdentifier() === $identifier) {
                return true;
            }
        }

        return false;
    }

    /**
     * The host a site answers on, with its identifier — the identifier alone is
     * a technical name that says nothing to whoever has to pick from the list.
     */
    private static function label(Site $site): string
    {
        $host = $site->getBase()->getHost();

        return $host !== '' ? sprintf('%s (%s)', $host, $site->getIdentifier()) : $site->getIdentifier();
    }

    /**
     * @return list<Site>
     */
    private function allSites(): array
    {
        try {
            return array_values($this->siteFinder->getAllSites());
        } catch (\Throwable $e) {
            // A broken site configuration must not take a backend module down;
            // without a list there is simply nothing to filter by.
            return [];
        }
    }
}

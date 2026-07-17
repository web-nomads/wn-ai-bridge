<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Search;

use WebNomads\WnAiBridge\Dto\SearchResultItem;

/**
 * Contract for a single search backend (ke_search, indexed_search, plain page
 * content, …). Providers are queried by the {@see SearchService} and degrade
 * gracefully: a provider whose backend is not installed simply reports
 * isAvailable() === false and is skipped.
 */
interface SearchProviderInterface
{
    /**
     * Stable identifier used in configuration and result attribution.
     */
    public function getKey(): string;

    /**
     * Whether the underlying search backend is installed and usable.
     */
    public function isAvailable(): bool;

    /**
     * Execute a search.
     *
     * @param string $query        The visitor's raw query.
     * @param int    $limit        Maximum number of hits to return.
     * @param int    $languageId   Current frontend language.
     * @param int    $rootPageId   Optional subtree restriction (0 = no restriction).
     * @return list<SearchResultItem>
     */
    public function search(string $query, int $limit, int $languageId, int $rootPageId = 0): array;
}

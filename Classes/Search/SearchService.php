<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Search;

use WebNomads\WnAiBridge\Dto\SearchResultItem;
use WebNomads\WnAiBridge\Service\ConfigurationService;

/**
 * Aggregates the available search providers into a single, ranked result set.
 *
 * Because each backend produces scores on its own scale, results are merged by
 * rank rather than raw score: within one provider the top hit is worth the most,
 * and a URL that surfaces in several backends accumulates their rank weights, so
 * consensus hits bubble to the top. Duplicate URLs are collapsed.
 */
final class SearchService
{
    /**
     * @var list<SearchProviderInterface>
     */
    private readonly array $providers;

    private readonly ConfigurationService $configurationService;

    /**
     * @param iterable<SearchProviderInterface> $providers Ordered by priority
     *        (dedicated indexes first, plain content search last). Injected via
     *        the "wn_ai_bridge.search_provider" DI tag, so third parties can add
     *        their own search backends.
     */
    public function __construct(
        iterable $providers,
        ConfigurationService $configurationService,
    ) {
        $this->providers = $providers instanceof \Traversable
            ? iterator_to_array($providers, false)
            : array_values($providers);
        $this->configurationService = $configurationService;
    }

    /**
     * @return list<SearchResultItem>
     */
    public function search(string $query, int $limit, int $languageId, int $rootPageId = 0): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $activeProviders = $this->resolveProviders();
        if ($activeProviders === []) {
            return [];
        }

        $terms = SearchQuery::terms($query);

        /** @var array<string, array{item: SearchResultItem, weight: float}> $merged */
        $merged = [];

        foreach ($activeProviders as $provider) {
            $results = $provider->search($query, $limit, $languageId, $rootPageId);
            $count = count($results);
            foreach ($results as $index => $item) {
                // Rank-based weight: normalises across providers with different scales.
                $weight = ($count - $index) / max($count, 1);
                $key = $this->normaliseUrl($item->url);

                if (isset($merged[$key])) {
                    $merged[$key]['weight'] += $weight;
                    // Keep the most useful snippet: one that actually contains the
                    // query terms beats a generic (e.g. navigational) excerpt.
                    if ($this->snippetScore($item->snippet, $terms) > $this->snippetScore($merged[$key]['item']->snippet, $terms)) {
                        $merged[$key]['item'] = $item;
                    }
                } else {
                    $merged[$key] = ['item' => $item, 'weight' => $weight];
                }
            }
        }

        uasort($merged, static fn(array $a, array $b): int => $b['weight'] <=> $a['weight']);

        $items = array_map(static fn(array $entry): SearchResultItem => $entry['item'], array_values($merged));

        return array_slice($items, 0, $limit);
    }

    /**
     * Select the providers to query based on the configured source and each
     * provider's availability. The plain page/content provider is always kept
     * as a safety net in "auto" mode so the assistant never returns nothing
     * just because an index has not been built yet.
     *
     * @return list<SearchProviderInterface>
     */
    private function resolveProviders(): array
    {
        $configured = $this->configurationService->getAssistantSearchSources();

        $active = [];
        foreach ($this->providers as $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }

            $key = $provider->getKey();
            $include = match ($configured) {
                'kesearch' => $key === 'kesearch',
                'indexed' => $key === 'indexed',
                'pages' => $key === 'pages',
                default => true, // auto
            };

            if ($include) {
                $active[] = $provider;
            }
        }

        // In auto mode, if a dedicated index answered we still keep the content
        // fallback available; if an explicit source was chosen but is missing,
        // fall back to the always-available page provider.
        if ($active === []) {
            foreach ($this->providers as $provider) {
                if ($provider->getKey() === 'pages' && $provider->isAvailable()) {
                    $active[] = $provider;
                }
            }
        }

        return $active;
    }

    /**
     * Normalise a URL for de-duplication. The anchor is kept as part of the key
     * because on OnePager sites the sections ("/#about", "/#services") are
     * distinct destinations and must not collapse into each other — or into the
     * anchor-less homepage.
     */
    private function normaliseUrl(string $url): string
    {
        $anchor = '';
        $hashPos = strpos($url, '#');
        if ($hashPos !== false) {
            $anchor = substr($url, $hashPos);
            $url = substr($url, 0, $hashPos);
        }

        return rtrim($url, '/') . $anchor;
    }

    /**
     * Relevance of a snippet for de-dup tie-breaking: how many query terms it
     * contains. An empty snippet ranks below any non-empty one.
     *
     * @param list<string> $terms
     */
    private function snippetScore(string $snippet, array $terms): int
    {
        if (trim($snippet) === '') {
            return -1;
        }

        $lower = mb_strtolower($snippet);
        $score = 0;
        foreach ($terms as $term) {
            if (mb_strpos($lower, mb_strtolower($term)) !== false) {
                $score++;
            }
        }

        return $score;
    }
}

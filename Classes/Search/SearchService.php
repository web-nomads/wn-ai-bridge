<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Search;

use TYPO3\CMS\Core\Site\SiteFinder;
use WebNomads\WnAiBridge\Dto\SearchResultItem;
use WebNomads\WnAiBridge\Security\PageAccessService;
use WebNomads\WnAiBridge\Service\ConfigurationService;

/**
 * Aggregates the available search providers into a single, ranked result set.
 *
 * Because each backend produces scores on its own scale, results are merged by
 * rank rather than raw score: within one provider the top hit is worth the most,
 * and a URL that surfaces in several backends accumulates their rank weights, so
 * consensus hits bubble to the top. Duplicate URLs are collapsed.
 *
 * Every hit passes two checks before it is merged. A search index is a snapshot
 * and outlives the page it describes: ke_search and indexed_search keep rows for
 * pages that have since been disabled or put behind a login, and both are
 * queried without the frontend's restrictions — so each hit is checked against
 * what the asking visitor may actually open. And on an installation serving more
 * than one site, each hit is checked against the site it was asked on: the index
 * tables know nothing about sites, so a question put to one website used to be
 * answered with pages from the other. Both checks are made here, once, rather
 * than in each provider, so a provider added later cannot forget them.
 */
final class SearchService
{
    /**
     * How many more hits are requested per provider than are finally shown, on
     * an installation with several sites. The providers cannot all restrict
     * themselves to one site, so their results are filtered afterwards — without
     * the head start, a busy neighbouring site would crowd out the answers.
     */
    private const MULTI_SITE_OVERFETCH = 4;

    /**
     * @var list<SearchProviderInterface>
     */
    private readonly array $providers;

    private readonly ConfigurationService $configurationService;
    private readonly PageAccessService $pageAccessService;
    private readonly ?SiteFinder $siteFinder;

    /**
     * Site identifier per page id, resolved at most once per request.
     *
     * @var array<int, string>
     */
    private array $siteOfPage = [];

    /**
     * @param iterable<SearchProviderInterface> $providers Ordered by priority
     *        (dedicated indexes first, plain content search last). Injected via
     *        the "wn_ai_bridge.search_provider" DI tag, so third parties can add
     *        their own search backends.
     * @param SiteFinder|null $siteFinder Absent only in tests that do not
     *        exercise the site boundary; without it the boundary is not applied.
     */
    public function __construct(
        iterable $providers,
        ConfigurationService $configurationService,
        PageAccessService $pageAccessService,
        ?SiteFinder $siteFinder = null,
    ) {
        $this->providers = $providers instanceof \Traversable
            ? iterator_to_array($providers, false)
            : array_values($providers);
        $this->configurationService = $configurationService;
        $this->pageAccessService = $pageAccessService;
        $this->siteFinder = $siteFinder;
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

        // Empty on a single-site installation: there is no other site to keep
        // out, and nothing about the search changes.
        $boundary = $this->resolveSiteBoundary();
        $fetchLimit = $boundary === '' ? $limit : $limit * self::MULTI_SITE_OVERFETCH;

        /** @var array<string, array{item: SearchResultItem, weight: float}> $merged */
        $merged = [];

        foreach ($activeProviders as $provider) {
            $results = $provider->search($query, $fetchLimit, $languageId, $rootPageId);
            $count = count($results);
            foreach ($results as $index => $item) {
                if (!$this->mayBeShown($item) || !$this->belongsToSite($item, $boundary)) {
                    continue;
                }

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
     * Whether a hit may reach the visitor who asked: the page behind it has to
     * be one they could open themselves. A member page therefore surfaces for
     * the member who is logged in and stays out of everyone else's results.
     *
     * A hit without a page id cannot be checked and is kept — the providers all
     * set one, so this only covers a third-party provider that does not.
     */
    private function mayBeShown(SearchResultItem $item): bool
    {
        return $item->pageId <= 0 || $this->pageAccessService->isAccessible($item->pageId);
    }

    /**
     * The site every hit has to belong to, or '' when there is nothing to keep
     * apart — a single-site installation, or a context with no resolvable site.
     *
     * Asking the SiteFinder how many sites there are is what keeps the ordinary
     * case untouched: one site means one page tree, and no hit can come from
     * anywhere else.
     */
    private function resolveSiteBoundary(): string
    {
        if ($this->siteFinder === null) {
            return '';
        }

        try {
            if (count($this->siteFinder->getAllSites()) < 2) {
                return '';
            }
        } catch (\Throwable $e) {
            return '';
        }

        return $this->configurationService->getCurrentSiteIdentifier();
    }

    /**
     * Whether a hit belongs to the site it was asked on.
     *
     * A hit whose site cannot be established is dropped rather than kept. That
     * is the opposite of the access check above, and deliberately so: "I cannot
     * tell which website this page belongs to" must not end in showing it to the
     * visitors of both. It only affects a hit without a page id, which none of
     * the bundled providers produce.
     */
    private function belongsToSite(SearchResultItem $item, string $boundary): bool
    {
        if ($boundary === '') {
            return true;
        }

        return $this->siteOfPage($item->pageId) === $boundary;
    }

    private function siteOfPage(int $pageId): string
    {
        if ($pageId <= 0 || $this->siteFinder === null) {
            return '';
        }

        if (!array_key_exists($pageId, $this->siteOfPage)) {
            try {
                $this->siteOfPage[$pageId] = $this->siteFinder->getSiteByPageId($pageId)->getIdentifier();
            } catch (\Throwable $e) {
                // A page outside every site (or already gone) belongs to none.
                $this->siteOfPage[$pageId] = '';
            }
        }

        return $this->siteOfPage[$pageId];
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

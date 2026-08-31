<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Search;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Dto\SearchResultItem;
use WebNomads\WnAiBridge\Search\SearchProviderInterface;
use WebNomads\WnAiBridge\Search\SearchService;
use WebNomads\WnAiBridge\Security\PageAccessService;
use WebNomads\WnAiBridge\Service\ConfigurationService;

class SearchServiceTest extends TestCase
{
    /**
     * @param list<SearchResultItem> $results
     */
    private function provider(string $key, array $results, bool $available = true): SearchProviderInterface
    {
        return new class ($key, $results, $available) implements SearchProviderInterface {
            /** @param list<SearchResultItem> $results */
            public function __construct(
                private readonly string $key,
                private readonly array $results,
                private readonly bool $available,
            ) {}

            public function getKey(): string
            {
                return $this->key;
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function search(string $query, int $limit, int $languageId, int $rootPageId = 0): array
            {
                return array_slice($this->results, 0, $limit);
            }
        };
    }

    private function configuration(string $sources = 'auto'): ConfigurationService
    {
        $configuration = $this->createMock(ConfigurationService::class);
        $configuration->method('getAssistantSearchSources')->willReturn($sources);
        return $configuration;
    }

    /**
     * @param list<int> $inaccessiblePageIds Pages the current visitor may not open
     */
    private function pageAccess(array $inaccessiblePageIds = []): PageAccessService
    {
        return new class ($inaccessiblePageIds) extends PageAccessService {
            /** @param list<int> $inaccessiblePageIds */
            public function __construct(private readonly array $inaccessiblePageIds) {}

            public function isAccessible(int $pageId): bool
            {
                return !in_array($pageId, $this->inaccessiblePageIds, true);
            }
        };
    }

    #[Test]
    public function returnsEmptyForBlankQuery(): void
    {
        $service = new SearchService([$this->provider('pages', [])], $this->configuration(), $this->pageAccess());
        self::assertSame([], $service->search('   ', 5, 0));
    }

    #[Test]
    public function mergesProvidersAndDeduplicatesByUrl(): void
    {
        $shared = SearchResultItem::create('Shared', 'https://example.com/a', 'snippet a', 5.0, 'kesearch', 1);
        $onlyKe = SearchResultItem::create('KeOnly', 'https://example.com/b', 'snippet b', 4.0, 'kesearch', 2);
        $onlyPages = SearchResultItem::create('PagesOnly', 'https://example.com/c', 'snippet c', 3.0, 'pages', 3);

        $keProvider = $this->provider('kesearch', [$shared, $onlyKe]);
        // Same URL as $shared (with a trailing slash) — must collapse to one hit.
        $sharedDuplicate = SearchResultItem::create('SharedDup', 'https://example.com/a/', 'snippet a2', 9.0, 'pages', 1);
        $pagesProvider = $this->provider('pages', [$sharedDuplicate, $onlyPages]);

        $service = new SearchService([$keProvider, $pagesProvider], $this->configuration(), $this->pageAccess());
        $results = $service->search('anything', 10, 0);

        $urls = array_map(static fn(SearchResultItem $item): string => $item->url, $results);
        // Three unique URLs (the shared one is de-duplicated).
        self::assertCount(3, $results);
        self::assertContains('https://example.com/a', $urls);
        self::assertContains('https://example.com/b', $urls);
        self::assertContains('https://example.com/c', $urls);

        // The consensus hit (found by both providers) ranks first.
        self::assertSame('https://example.com/a', $results[0]->url);
    }

    #[Test]
    public function keepsDistinctOnePagerSectionAnchorsSeparate(): void
    {
        // On a OnePager the sections share the same base URL but differ only by
        // their anchor. They must stay distinct results (and distinct from the
        // anchor-less homepage), not collapse into one.
        $home = SearchResultItem::create('Home', 'https://example.com/', 'home', 5.0, 'indexed', 1);
        $about = SearchResultItem::create('Über mich', 'https://example.com/#about', '', 4.0, 'pages', 2);
        $services = SearchResultItem::create('Dienstleistungen', 'https://example.com/#services', '', 3.0, 'pages', 3);

        $service = new SearchService(
            [$this->provider('indexed', [$home]), $this->provider('pages', [$about, $services])],
            $this->configuration(),
            $this->pageAccess(),
        );

        $urls = array_map(
            static fn(SearchResultItem $item): string => $item->url,
            $service->search('anything', 10, 0),
        );

        self::assertCount(3, $urls);
        self::assertContains('https://example.com/', $urls);
        self::assertContains('https://example.com/#about', $urls);
        self::assertContains('https://example.com/#services', $urls);
    }

    #[Test]
    public function honoursExplicitSourceSelection(): void
    {
        $keItem = SearchResultItem::create('Ke', 'https://example.com/ke', '', 5.0, 'kesearch', 1);
        $pagesItem = SearchResultItem::create('Pages', 'https://example.com/pages', '', 5.0, 'pages', 2);

        $service = new SearchService(
            [$this->provider('kesearch', [$keItem]), $this->provider('pages', [$pagesItem])],
            $this->configuration('kesearch'),
            $this->pageAccess(),
        );

        $results = $service->search('anything', 10, 0);
        self::assertCount(1, $results);
        self::assertSame('https://example.com/ke', $results[0]->url);
    }

    #[Test]
    public function fallsBackToPageProviderWhenChosenSourceUnavailable(): void
    {
        $pagesItem = SearchResultItem::create('Pages', 'https://example.com/pages', '', 5.0, 'pages', 2);

        $service = new SearchService(
            [
                $this->provider('kesearch', [], available: false),
                $this->provider('pages', [$pagesItem]),
            ],
            $this->configuration('kesearch'),
            $this->pageAccess(),
        );

        // ke_search was requested but is unavailable — the always-on page
        // provider keeps the assistant working.
        $results = $service->search('anything', 10, 0);
        self::assertCount(1, $results);
        self::assertSame('https://example.com/pages', $results[0]->url);
    }

    #[Test]
    public function dropsHitsOnPagesTheVisitorMayNotOpen(): void
    {
        // An index keeps a row long after the page was disabled or put behind a
        // login, so a hit is only as good as the page behind it still is.
        $open = SearchResultItem::create('Open', 'https://example.com/open', '', 5.0, 'kesearch', 1);
        $closed = SearchResultItem::create('Members only', 'https://example.com/members', '', 9.0, 'kesearch', 2);

        $service = new SearchService(
            [$this->provider('kesearch', [$open, $closed])],
            $this->configuration(),
            $this->pageAccess([2]),
        );

        $results = $service->search('anything', 10, 0);

        self::assertCount(1, $results);
        self::assertSame('https://example.com/open', $results[0]->url);
    }

    #[Test]
    public function aHiddenPageIsDroppedEvenWhenAnotherProviderAlsoFoundIt(): void
    {
        // Two providers agreeing on a hit would normally make it rank first —
        // it must not sneak back in through the second one.
        $item = SearchResultItem::create('Gone', 'https://example.com/gone', '', 5.0, 'kesearch', 7);
        $same = SearchResultItem::create('Gone', 'https://example.com/gone', '', 5.0, 'indexed', 7);

        $service = new SearchService(
            [$this->provider('kesearch', [$item]), $this->provider('indexed', [$same])],
            $this->configuration(),
            $this->pageAccess([7]),
        );

        self::assertSame([], $service->search('anything', 10, 0));
    }

    #[Test]
    public function respectsResultLimit(): void
    {
        $items = [];
        for ($i = 0; $i < 10; $i++) {
            $items[] = SearchResultItem::create('T' . $i, 'https://example.com/' . $i, '', 10.0 - $i, 'pages', $i);
        }

        $service = new SearchService([$this->provider('pages', $items)], $this->configuration(), $this->pageAccess());
        self::assertCount(3, $service->search('anything', 3, 0));
    }
}

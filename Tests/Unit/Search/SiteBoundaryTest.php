<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Search;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use WebNomads\WnAiBridge\Dto\SearchResultItem;
use WebNomads\WnAiBridge\Search\SearchProviderInterface;
use WebNomads\WnAiBridge\Search\SearchService;
use WebNomads\WnAiBridge\Security\PageAccessService;
use WebNomads\WnAiBridge\Service\ConfigurationService;

/**
 * A question put to one website may only be answered with that website's pages.
 *
 * On a TYPO3 serving several sites this was not the case: ke_search and
 * indexed_search index every page of the installation into one table and know
 * nothing about sites, and the page/content fallback searched the whole tree
 * whenever no search root was configured — which was the default. A visitor on
 * one site was answered with, and linked to, the other one.
 *
 * The boundary is drawn here rather than in each provider, so a provider added
 * later cannot forget it.
 */
final class SiteBoundaryTest extends TestCase
{
    #[Test]
    public function hitsFromTheOtherSiteAreDropped(): void
    {
        $own = SearchResultItem::create('Ours', 'https://a.example/1', '', 5.0, 'kesearch', 11);
        $foreign = SearchResultItem::create('Theirs', 'https://b.example/9', '', 9.0, 'kesearch', 22);

        $results = $this->search([$own, $foreign], twoSites: true);

        self::assertCount(1, $results);
        self::assertSame('https://a.example/1', $results[0]->url);
    }

    /**
     * The foreign hit scored higher and was found by two providers, which is
     * what normally puts a result first. It must not get back in that way.
     */
    #[Test]
    public function aForeignHitDoesNotReturnThroughASecondProvider(): void
    {
        $foreign = SearchResultItem::create('Theirs', 'https://b.example/9', '', 9.0, 'kesearch', 22);
        $foreignAgain = SearchResultItem::create('Theirs', 'https://b.example/9', '', 9.0, 'indexed', 22);
        $own = SearchResultItem::create('Ours', 'https://a.example/1', '', 1.0, 'pages', 11);

        $service = $this->service(
            [
                $this->provider('kesearch', [$foreign]),
                $this->provider('indexed', [$foreignAgain]),
                $this->provider('pages', [$own]),
            ],
            twoSites: true,
        );

        $urls = array_map(
            static fn(SearchResultItem $item): string => $item->url,
            $service->search('anything', 10, 0),
        );

        self::assertSame(['https://a.example/1'], $urls);
    }

    /**
     * On a single-site installation nothing is filtered and no site is even
     * looked up — there is no second site a hit could come from.
     */
    #[Test]
    public function aSingleSiteInstallationIsUntouched(): void
    {
        $one = SearchResultItem::create('One', 'https://a.example/1', '', 5.0, 'pages', 11);
        // A page id from nowhere in particular. With one site it stays in.
        $orphan = SearchResultItem::create('Orphan', 'https://a.example/2', '', 4.0, 'pages', 999);

        $results = $this->search([$one, $orphan], twoSites: false);

        self::assertCount(2, $results);
    }

    /**
     * A hit whose site cannot be established is dropped. That is the opposite of
     * the access check, and deliberately so: "I cannot tell whose page this is"
     * must not end in showing it to the visitors of both sites.
     */
    #[Test]
    public function aHitThatBelongsToNoSiteIsDropped(): void
    {
        $own = SearchResultItem::create('Ours', 'https://a.example/1', '', 5.0, 'pages', 11);
        $orphan = SearchResultItem::create('Orphan', 'https://a.example/2', '', 9.0, 'pages', 999);

        $results = $this->search([$own, $orphan], twoSites: true);

        self::assertCount(1, $results);
        self::assertSame('https://a.example/1', $results[0]->url);
    }

    /**
     * Without the head start, a busy neighbouring site would fill the providers'
     * result set and leave nothing to show after filtering.
     */
    #[Test]
    public function providersAreAskedForMoreThanIsShownSoTheOwnSiteSurvivesTheFilter(): void
    {
        $items = [];
        // Nine hits from the other site, then one from ours — with a limit of 3
        // and no over-fetching, only the foreign ones would ever be looked at.
        for ($i = 0; $i < 9; $i++) {
            $items[] = SearchResultItem::create('T' . $i, 'https://b.example/' . $i, '', 10.0 - $i, 'kesearch', 22);
        }
        $items[] = SearchResultItem::create('Ours', 'https://a.example/1', '', 0.5, 'kesearch', 11);

        $results = $this->search($items, twoSites: true, limit: 3);

        self::assertCount(1, $results);
        self::assertSame('https://a.example/1', $results[0]->url);
    }

    /**
     * @param list<SearchResultItem> $items
     * @return list<SearchResultItem>
     */
    private function search(array $items, bool $twoSites, int $limit = 10): array
    {
        return $this->service([$this->provider('kesearch', $items)], $twoSites)
            ->search('anything', $limit, 0);
    }

    /**
     * @param list<SearchProviderInterface> $providers
     */
    private function service(array $providers, bool $twoSites): SearchService
    {
        $ours = new Site('ours', 1, ['base' => 'https://a.example/']);
        $theirs = new Site('theirs', 2, ['base' => 'https://b.example/']);

        $sites = $twoSites ? ['ours' => $ours, 'theirs' => $theirs] : ['ours' => $ours];

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn($sites);
        $siteFinder->method('getSiteByPageId')->willReturnCallback(
            static function (int $pageId) use ($ours, $theirs): Site {
                return match ($pageId) {
                    11 => $ours,
                    22 => $theirs,
                    default => throw new SiteNotFoundException('No site for page ' . $pageId, 1770300001),
                };
            }
        );

        $configuration = $this->createMock(ConfigurationService::class);
        $configuration->method('getAssistantSearchSources')->willReturn('auto');
        $configuration->method('getCurrentSiteIdentifier')->willReturn('ours');

        return new SearchService($providers, $configuration, $this->pageAccess(), $siteFinder);
    }

    /**
     * @param list<SearchResultItem> $results
     */
    private function provider(string $key, array $results): SearchProviderInterface
    {
        return new class ($key, $results) implements SearchProviderInterface {
            /** @param list<SearchResultItem> $results */
            public function __construct(
                private readonly string $key,
                private readonly array $results,
            ) {}

            public function getKey(): string
            {
                return $this->key;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function search(string $query, int $limit, int $languageId, int $rootPageId = 0): array
            {
                return array_slice($this->results, 0, $limit);
            }
        };
    }

    private function pageAccess(): PageAccessService
    {
        return new class () extends PageAccessService {
            public function __construct() {}

            public function isAccessible(int $pageId): bool
            {
                return true;
            }
        };
    }
}

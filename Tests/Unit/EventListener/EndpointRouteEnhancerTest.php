<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\EventListener;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use WebNomads\WnAiBridge\EventListener\EndpointRouteEnhancer;

/**
 * Endpoints a site does not map yet are added to its PageType enhancer.
 *
 * The shipped enhancer file is meant to be imported by reference, but copying
 * its content into the site configuration is just as common — and that copy is a
 * snapshot. llms-full.txt arrived in 1.27.0, and every site holding such a copy
 * answered it with a 404 and nothing to explain why.
 */
final class EndpointRouteEnhancerTest extends TestCase
{
    private const SHIPPED = [
        'llms.txt' => 1699,
        '.well-known/llms.txt' => 1699,
        'llms-full.txt' => 1702,
        '.well-known/llms-full.txt' => 1702,
        '.md' => 1701,
    ];

    /**
     * A copy made before 1.27.0, as it is found in the wild.
     *
     * @return array<string, mixed>
     */
    private static function siteWithOutdatedCopy(): array
    {
        return [
            'rootPageId' => 1,
            'routeEnhancers' => [
                'PageTypeSuffix' => [
                    'type' => 'PageType',
                    'map' => [
                        '/' => 0,
                        '.md' => 1701,
                        'llms.txt' => 1699,
                        '.well-known/llms.txt' => 1699,
                        'sitemap.xml' => 1533906435,
                    ],
                ],
            ],
        ];
    }

    #[Test]
    public function theMissingEndpointIsAdded(): void
    {
        $result = EndpointRouteEnhancer::withMissingEndpoints(self::siteWithOutdatedCopy(), self::SHIPPED);
        $map = $result['routeEnhancers']['PageTypeSuffix']['map'];

        self::assertSame(1702, $map['llms-full.txt']);
        self::assertSame(1702, $map['.well-known/llms-full.txt']);
    }

    #[Test]
    public function everythingElseIsLeftExactlyAsItWas(): void
    {
        $before = self::siteWithOutdatedCopy();
        $result = EndpointRouteEnhancer::withMissingEndpoints($before, self::SHIPPED);
        $map = $result['routeEnhancers']['PageTypeSuffix']['map'];

        self::assertSame(1533906435, $map['sitemap.xml']);
        self::assertSame(0, $map['/']);
        self::assertSame(1, $result['rootPageId']);
        self::assertSame('PageType', $result['routeEnhancers']['PageTypeSuffix']['type']);
    }

    #[Test]
    public function aSuffixTheSiteAlreadyMapsIsNeverOverwritten(): void
    {
        // Whatever it points at, someone decided that.
        $site = self::siteWithOutdatedCopy();
        $site['routeEnhancers']['PageTypeSuffix']['map']['.md'] = 4711;

        $result = EndpointRouteEnhancer::withMissingEndpoints($site, self::SHIPPED);

        self::assertSame(4711, $result['routeEnhancers']['PageTypeSuffix']['map']['.md']);
    }

    #[Test]
    public function aSiteThatImportedTheFileIsUntouched(): void
    {
        $site = [
            'routeEnhancers' => [
                'PageTypeSuffix' => ['type' => 'PageType', 'map' => self::SHIPPED],
            ],
        ];

        self::assertSame($site, EndpointRouteEnhancer::withMissingEndpoints($site, self::SHIPPED));
    }

    #[Test]
    public function theEnhancerIsFoundByItsTypeNotByItsName(): void
    {
        $site = [
            'routeEnhancers' => [
                'SomeOtherName' => ['type' => 'PageType', 'map' => ['/' => 0]],
            ],
        ];

        $result = EndpointRouteEnhancer::withMissingEndpoints($site, self::SHIPPED);

        self::assertSame(1702, $result['routeEnhancers']['SomeOtherName']['map']['llms-full.txt']);
    }

    #[Test]
    public function aSiteWithoutAPageTypeEnhancerIsLeftAlone(): void
    {
        // Introducing one changes how every URL on the site is built.
        $withoutAny = ['rootPageId' => 1];
        self::assertSame($withoutAny, EndpointRouteEnhancer::withMissingEndpoints($withoutAny, self::SHIPPED));

        $withOtherKind = [
            'routeEnhancers' => [
                'News' => ['type' => 'Extbase', 'extension' => 'News', 'plugin' => 'Pi1'],
            ],
        ];
        self::assertSame($withOtherKind, EndpointRouteEnhancer::withMissingEndpoints($withOtherKind, self::SHIPPED));
    }

    #[Test]
    public function anUnreadableShippedMapChangesNothing(): void
    {
        $site = self::siteWithOutdatedCopy();

        self::assertSame($site, EndpointRouteEnhancer::withMissingEndpoints($site, []));
    }

    #[Test]
    public function theShippedFileMapsEveryEndpointTheExtensionServes(): void
    {
        // The file sites are asked to import is the single source of the map, so
        // it is what must not fall behind.
        $file = dirname(__DIR__, 3) . '/Configuration/Routes/RouterEnhancer.yaml';
        $shipped = Yaml::parseFile($file);

        self::assertSame(self::SHIPPED, $shipped['routeEnhancers']['PageTypeSuffix']['map']);
        self::assertSame('PageType', $shipped['routeEnhancers']['PageTypeSuffix']['type']);
    }
}

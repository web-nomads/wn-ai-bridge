<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\RateLimiter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use WebNomads\WnAiBridge\RateLimiter\RateLimiterService;
use WebNomads\WnAiBridge\Service\ConfigurationService;

class RateLimiterServiceTest extends TestCase
{
    /**
     * A minimal in-memory cache stand-in so the fixed-window counter can be
     * exercised without the TYPO3 caching framework. Backed by a PHPUnit mock
     * so the strict FrontendInterface signatures do not need to be reimplemented.
     */
    private function createArrayCache(): FrontendInterface
    {
        $store = [];

        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('set')->willReturnCallback(
            function ($entryIdentifier, $data) use (&$store): void {
                $store[$entryIdentifier] = $data;
            }
        );
        $cache->method('get')->willReturnCallback(
            function ($entryIdentifier) use (&$store) {
                return $store[$entryIdentifier] ?? false;
            }
        );
        $cache->method('has')->willReturnCallback(
            function ($entryIdentifier) use (&$store) {
                return isset($store[$entryIdentifier]);
            }
        );

        return $cache;
    }

    private function createSubject(FrontendInterface $cache, int $ipLimit, int $keyLimit): RateLimiterService
    {
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($cache);

        $configuration = $this->createMock(ConfigurationService::class);
        $configuration->method('isRateLimiterEnabled')->willReturn(true);
        $configuration->method('getRateLimiterRequestsPerMinute')->willReturn($ipLimit);
        $configuration->method('getRateLimiterPerKeyRequestsPerMinute')->willReturn($keyLimit);

        return new RateLimiterService($cacheManager, $configuration);
    }

    #[Test]
    public function requestsWithinLimitAreAllowed(): void
    {
        $subject = $this->createSubject($this->createArrayCache(), 3, 3);

        self::assertTrue($subject->consumeForIp('1.2.3.4')->isAllowed());
        self::assertTrue($subject->consumeForIp('1.2.3.4')->isAllowed());
        $third = $subject->consumeForIp('1.2.3.4');

        self::assertTrue($third->isAllowed());
        self::assertSame(0, $third->remaining);
    }

    #[Test]
    public function requestsBeyondLimitAreBlocked(): void
    {
        $subject = $this->createSubject($this->createArrayCache(), 2, 2);

        $subject->consumeForIp('9.9.9.9');
        $subject->consumeForIp('9.9.9.9');
        $blocked = $subject->consumeForIp('9.9.9.9');

        self::assertFalse($blocked->isAllowed());
        self::assertGreaterThan(0, $blocked->retryAfter);
    }

    #[Test]
    public function zeroLimitMeansUnlimited(): void
    {
        $subject = $this->createSubject($this->createArrayCache(), 0, 0);

        for ($i = 0; $i < 100; $i++) {
            self::assertTrue($subject->consumeForIp('7.7.7.7')->isAllowed());
        }
    }

    #[Test]
    public function differentIpsAreCountedIndependently(): void
    {
        $subject = $this->createSubject($this->createArrayCache(), 1, 1);

        self::assertTrue($subject->consumeForIp('10.0.0.1')->isAllowed());
        self::assertTrue($subject->consumeForIp('10.0.0.2')->isAllowed());
        self::assertFalse($subject->consumeForIp('10.0.0.1')->isAllowed());
    }
}

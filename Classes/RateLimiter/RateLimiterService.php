<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\RateLimiter;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WebNomads\WnAiBridge\Service\ConfigurationService;

/**
 * Fixed-window rate limiter for AI-Bridge requests.
 *
 * Counters are kept in the "wn_ai_bridge_ratelimit" cache and bucketed into
 * one-minute windows. Each check increments the counter for the current window
 * and reports whether the configured limit has been exceeded.
 *
 * The limiter distinguishes two independent scopes:
 *  - per client IP    (rateLimiterRequestsPerMinute)
 *  - per API key / bot (rateLimiterPerKeyRequestsPerMinute)
 */
class RateLimiterService
{
    /**
     * Length of a single rate limit window in seconds.
     */
    private const WINDOW_SECONDS = 60;

    private readonly FrontendInterface $cache;

    private readonly ConfigurationService $configurationService;

    public function __construct(
        ?CacheManager $cacheManager = null,
        ?ConfigurationService $configurationService = null,
    ) {
        $cacheManager ??= GeneralUtility::makeInstance(CacheManager::class);
        $this->cache = $cacheManager->getCache('wn_ai_bridge_ratelimit');
        $this->configurationService = $configurationService
            ?? GeneralUtility::makeInstance(ConfigurationService::class);
    }

    public function isEnabled(): bool
    {
        return $this->configurationService->isRateLimiterEnabled();
    }

    /**
     * Consume one request against the per-IP limit.
     */
    public function consumeForIp(string $clientIp): RateLimitStatus
    {
        return $this->consume(
            'ip:' . $clientIp,
            $this->configurationService->getRateLimiterRequestsPerMinute()
        );
    }

    /**
     * Consume one request against the per-key / per-bot limit.
     */
    public function consumeForKey(string $key): RateLimitStatus
    {
        return $this->consume(
            'key:' . $key,
            $this->configurationService->getRateLimiterPerKeyRequestsPerMinute()
        );
    }

    /**
     * Increment the counter for the given identifier in the current window and
     * evaluate it against the limit. A limit of 0 (or lower) disables the check.
     */
    private function consume(string $identifier, int $limit): RateLimitStatus
    {
        $retryAfter = self::WINDOW_SECONDS - (time() % self::WINDOW_SECONDS);

        // A non-positive limit means "unlimited" for this scope.
        if ($limit <= 0) {
            return new RateLimitStatus(true, 0, 0, $retryAfter);
        }

        $window = (int)floor(time() / self::WINDOW_SECONDS);
        $cacheKey = 'rl_' . md5($identifier) . '_' . $window;

        $count = (int)$this->cache->get($cacheKey);
        $count++;

        // Keep the counter around for two windows so stale entries expire on
        // their own without needing an explicit garbage collection run.
        $this->cache->set($cacheKey, $count, ['wn_ai_bridge_ratelimit'], self::WINDOW_SECONDS * 2);

        $allowed = $count <= $limit;
        $remaining = max(0, $limit - $count);

        return new RateLimitStatus($allowed, $limit, $remaining, $retryAfter);
    }
}

<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Subscription;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Asks the issuing server once a day whether a key is still valid, so a revoked
 * subscription stops working without waiting for its expiry date.
 *
 * The rules that keep this from ever taking a site down:
 *
 * - Only an explicitly signed "revoked" disables anything. An unreachable
 *   server, a malformed answer or a bad signature all mean "unknown", which
 *   changes nothing — the offline check in the key stays authoritative.
 * - The HTTP call is never made from a frontend request. It runs in the backend
 *   and on the command line (see the "ai-bridge:check-subscription" command);
 *   the frontend only reads the cached verdict.
 * - A failed attempt is cached briefly, so an outage does not turn into one
 *   request per page view.
 */
final class SubscriptionOnlineCheck implements SingletonInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const CACHE_IDENTIFIER = 'wn_ai_bridge_subscription';

    /** How long a verified verdict is trusted — the "once a day" of the check. */
    public const VERDICT_LIFETIME = 86400;

    /** How long a failed attempt is remembered before retrying. */
    public const FAILURE_LIFETIME = 3600;

    private const HTTP_TIMEOUT = 5;

    private ?FrontendInterface $cache = null;

    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    /**
     * The current verdict for a token, refreshing it when it is due and the
     * context allows an outgoing request.
     *
     * @param bool $allowFrontendRefresh Permit the request even in a visitor
     *        request. Passed only while the key is at the end of its validity,
     *        where the alternative is switching the subscription off although it
     *        was renewed. Still at most one call per day thanks to the cache.
     */
    public function verdict(
        SubscriptionToken $token,
        string $publicKeyHex,
        string $host,
        bool $allowFrontendRefresh = false,
    ): OnlineCheckResult {
        if ($token->id === '' || !$this->isEnabled()) {
            return OnlineCheckResult::unknown();
        }

        $baseUrl = $this->resolveBaseUrl($token);
        if ($baseUrl === '') {
            return OnlineCheckResult::unknown();
        }

        $cached = $this->readCache($token->id);
        if ($cached !== null) {
            return $cached;
        }

        if (!$this->mayRefresh($allowFrontendRefresh)) {
            // Frontend request with nothing cached: stay out of the way.
            return OnlineCheckResult::unknown();
        }

        return $this->refresh($token, $publicKeyHex, $host, $baseUrl);
    }

    /**
     * Force a refresh regardless of the cache — used by the CLI command.
     */
    public function refreshNow(SubscriptionToken $token, string $publicKeyHex, string $host): OnlineCheckResult
    {
        $baseUrl = $this->resolveBaseUrl($token);
        if ($token->id === '' || $baseUrl === '') {
            return OnlineCheckResult::unknown();
        }

        return $this->refresh($token, $publicKeyHex, $host, $baseUrl);
    }

    private function refresh(SubscriptionToken $token, string $publicKeyHex, string $host, string $baseUrl): OnlineCheckResult
    {
        $nonce = OnlineCheckProtocol::createNonce();
        $url = OnlineCheckProtocol::buildUrl($baseUrl, $token->id, $host, $nonce);

        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'timeout' => self::HTTP_TIMEOUT,
                'connect_timeout' => self::HTTP_TIMEOUT,
                'http_errors' => false,
                'headers' => ['Accept' => 'application/json'],
            ]);

            $result = $response->getStatusCode() === 200
                ? OnlineCheckProtocol::parseResponse((string)$response->getBody(), $token->id, $nonce, $publicKeyHex)
                : null;
        } catch (\Throwable $e) {
            $this->logger?->info('AI Bridge subscription check could not reach the issuing server.', [
                'exception' => mb_substr($e->getMessage(), 0, 200),
            ]);
            $result = null;
        }

        if ($result === null) {
            // Remember the failure briefly so an outage is not retried per request.
            $this->writeCache($token->id, OnlineCheckResult::unknown(), self::FAILURE_LIFETIME);

            return OnlineCheckResult::unknown();
        }

        $this->writeCache($token->id, $result, self::VERDICT_LIFETIME);

        return $result;
    }

    /**
     * Whether an outgoing HTTP request is acceptable here. Frontend requests are
     * excluded so a visitor never waits for the licence server — except while the
     * key is running out, where staying silent would switch off a subscription
     * that has in fact been renewed.
     */
    private function mayRefresh(bool $allowFrontendRefresh): bool
    {
        if (Environment::isCli()) {
            return true;
        }

        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request === null) {
            return false;
        }

        try {
            return ApplicationType::fromRequest($request)->isBackend() || $allowFrontendRefresh;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function isEnabled(): bool
    {
        $extensionConfiguration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];

        return (bool)($extensionConfiguration['subscriptionOnlineCheck'] ?? true);
    }

    /**
     * The server to ask: primarily the URL baked into the key, overridable by
     * configuration for staging setups.
     */
    private function resolveBaseUrl(SubscriptionToken $token): string
    {
        $extensionConfiguration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        $configured = trim((string)($extensionConfiguration['subscriptionServerUrl'] ?? ''));

        $url = $configured !== '' ? $configured : $token->checkUrl;

        return preg_match('#^https?://#i', $url) === 1 ? $url : '';
    }

    private function readCache(string $subscriptionId): ?OnlineCheckResult
    {
        try {
            $entry = $this->getCache()?->get($this->cacheKey($subscriptionId));
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($entry) ? OnlineCheckResult::fromArray($entry) : null;
    }

    private function writeCache(string $subscriptionId, OnlineCheckResult $result, int $lifetime): void
    {
        try {
            $this->getCache()?->set($this->cacheKey($subscriptionId), $result->toArray(), [], $lifetime);
        } catch (\Throwable $e) {
            // A missing cache must not break the check.
        }
    }

    private function cacheKey(string $subscriptionId): string
    {
        return 'verdict-' . sha1($subscriptionId);
    }

    private function getCache(): ?FrontendInterface
    {
        if ($this->cache === null) {
            try {
                $this->cache = GeneralUtility::makeInstance(CacheManager::class)->getCache(self::CACHE_IDENTIFIER);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return $this->cache;
    }
}

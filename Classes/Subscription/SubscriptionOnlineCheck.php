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
 * The check has no off switch. It is what carries a renewal to the installation
 * and what makes a revocation take effect, so disabling it only ever produced a
 * subscription that silently stopped following its own server.
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

    /** Issuing server used when neither the configuration nor the key names one. */
    public const DEFAULT_SERVER_URL = 'https://www.marcelmarty.ch';

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
        if ($token->id === '') {
            return OnlineCheckResult::unknown();
        }

        $baseUrl = $this->resolveBaseUrl($token);
        if ($baseUrl === '') {
            return OnlineCheckResult::unknown();
        }

        $cached = $this->readCache($token->id, $baseUrl);
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

        $failure = OnlineCheckResult::FAILURE_NONE;

        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'timeout' => self::HTTP_TIMEOUT,
                'connect_timeout' => self::HTTP_TIMEOUT,
                'http_errors' => false,
                'headers' => ['Accept' => 'application/json'],
            ]);

            if ($response->getStatusCode() !== 200) {
                $result = null;
                $failure = OnlineCheckResult::FAILURE_HTTP;
                $this->logger?->warning('AI Bridge subscription check: the issuing server answered with an error.', [
                    'status' => $response->getStatusCode(),
                    'server' => $baseUrl,
                ]);
            } else {
                $result = OnlineCheckProtocol::parseResponse((string)$response->getBody(), $token->id, $nonce, $publicKeyHex);
                if ($result === null) {
                    $failure = OnlineCheckResult::FAILURE_INVALID;
                    $this->logger?->warning('AI Bridge subscription check: the answer of the issuing server could not be verified.', [
                        'server' => $baseUrl,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('AI Bridge subscription check: the issuing server could not be reached.', [
                'server' => $baseUrl,
                'exception' => mb_substr($e->getMessage(), 0, 200),
            ]);
            $result = null;
            $failure = OnlineCheckResult::FAILURE_UNREACHABLE;
        }

        if ($result === null) {
            // Remember the failure briefly so an outage is not retried per request.
            $unknown = OnlineCheckResult::unknown(null, $failure, $baseUrl);
            $this->writeCache($token->id, $baseUrl, $unknown, self::FAILURE_LIFETIME);

            return $unknown;
        }

        $this->writeCache($token->id, $baseUrl, $result, self::VERDICT_LIFETIME);

        return $result;
    }

    /**
     * Whether a check could be made right now — asked by the service to decide
     * whether a verdict resolved earlier in the request is worth re-fetching.
     */
    public function mayRefreshNow(): bool
    {
        return $this->mayRefresh(false);
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

    /**
     * The server to ask, in falling order of precedence: the configured
     * override, the URL baked into the key, and finally the issuing server this
     * extension ships with.
     *
     * The default is what keeps a key working that carries no server address of
     * its own — without it there was no one to ask, and the check silently did
     * nothing.
     */
    public static function resolveServerUrl(string $configured, string $fromKey = ''): string
    {
        foreach ([trim($configured), trim($fromKey), self::DEFAULT_SERVER_URL] as $candidate) {
            if (preg_match('#^https?://#i', $candidate) === 1) {
                return $candidate;
            }
        }

        return '';
    }

    private function resolveBaseUrl(SubscriptionToken $token): string
    {
        $extensionConfiguration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];

        return self::resolveServerUrl(
            (string)($extensionConfiguration['subscriptionServerUrl'] ?? ''),
            $token->checkUrl,
        );
    }

    private function readCache(string $subscriptionId, string $baseUrl): ?OnlineCheckResult
    {
        try {
            $entry = $this->getCache()?->get($this->cacheKey($subscriptionId, $baseUrl));
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($entry) ? OnlineCheckResult::fromArray($entry) : null;
    }

    private function writeCache(string $subscriptionId, string $baseUrl, OnlineCheckResult $result, int $lifetime): void
    {
        try {
            $this->getCache()?->set($this->cacheKey($subscriptionId, $baseUrl), $result->toArray(), [], $lifetime);
        } catch (\Throwable $e) {
            // A missing cache must not break the check.
        }
    }

    /**
     * The server address is part of the key on purpose: changing it in the
     * extension configuration has to take effect now, not once a day-old verdict
     * for the previous address expires.
     */
    private function cacheKey(string $subscriptionId, string $baseUrl): string
    {
        return 'verdict-' . sha1($subscriptionId . '|' . $baseUrl);
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

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
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tells the issuing server when this installation looks manipulated.
 *
 * What is sent: the subscription id from the key, the host, the finding, and the
 * two version numbers that make it actionable. Nothing about the site, its
 * content or its visitors. The same finding is reported at most once a day.
 *
 * Same rules as the status check: never from a visitor request, never blocking,
 * never throwing. And the same limit — an installation reporting on itself can
 * be silenced by editing this class, which is why the detection that matters
 * runs on the issuing server.
 */
final class TamperReporter implements SingletonInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const ENDPOINT_PATH = '/wn-ai-bridge-server/report';

    /** How long the same finding stays quiet after it was sent. */
    public const REPORT_INTERVAL = 86400;

    private const HTTP_TIMEOUT = 5;

    private ?FrontendInterface $cache = null;

    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    /**
     * Report a finding, unless it was already reported today.
     */
    public function report(string $reason, string $subscriptionId, string $host, string $baseUrl): void
    {
        if ($reason === '' || $baseUrl === '' || !$this->mayReport()) {
            return;
        }

        $cacheKey = self::throttleKey($reason, $subscriptionId, $host);
        if ($this->wasReported($cacheKey)) {
            return;
        }

        // Remembered before sending: a server that is down must not turn into one
        // outgoing request per backend page view.
        $this->remember($cacheKey);

        try {
            $this->requestFactory->request(
                rtrim($baseUrl, '/') . self::ENDPOINT_PATH,
                'POST',
                [
                    'timeout' => self::HTTP_TIMEOUT,
                    'connect_timeout' => self::HTTP_TIMEOUT,
                    'http_errors' => false,
                    'form_params' => self::buildPayload($reason, $subscriptionId, $host),
                ],
            );
        } catch (\Throwable $e) {
            $this->logger?->info('A licence finding could not be reported.', [
                'reason' => $reason,
                'exception' => mb_substr($e->getMessage(), 0, 200),
            ]);
        }
    }

    /**
     * Everything that leaves this installation. Pure, so what is sent can be
     * read off in a test rather than taken on trust.
     *
     * @return array<string, string>
     */
    public static function buildPayload(string $reason, string $subscriptionId, string $host): array
    {
        return [
            'reason' => $reason,
            'id' => $subscriptionId,
            'host' => $host,
            'extensionVersion' => self::extensionVersion(),
            'typo3Version' => self::typo3Version(),
        ];
    }

    public static function throttleKey(string $reason, string $subscriptionId, string $host): string
    {
        return 'report-' . sha1($reason . '|' . $subscriptionId . '|' . $host);
    }

    /**
     * Never from a visitor request: a finding is not worth delaying a page for,
     * and the backend or the scheduled check will carry it soon enough.
     */
    private function mayReport(): bool
    {
        if (Environment::isCli()) {
            return true;
        }

        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request === null) {
            return false;
        }

        try {
            return ApplicationType::fromRequest($request)->isBackend();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function wasReported(string $cacheKey): bool
    {
        try {
            return $this->getCache()?->has($cacheKey) === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function remember(string $cacheKey): void
    {
        try {
            $this->getCache()?->set($cacheKey, true, [], self::REPORT_INTERVAL);
        } catch (\Throwable $e) {
            // Without a cache the throttle is lost, not the reporting.
        }
    }

    private function getCache(): ?FrontendInterface
    {
        if ($this->cache === null) {
            try {
                $this->cache = GeneralUtility::makeInstance(CacheManager::class)
                    ->getCache(SubscriptionOnlineCheck::CACHE_IDENTIFIER);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return $this->cache;
    }

    private static function extensionVersion(): string
    {
        try {
            return (string)(\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::getExtensionVersion('wn_ai_bridge') ?: '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function typo3Version(): string
    {
        try {
            return (new Typo3Version())->getVersion();
        } catch (\Throwable $e) {
            return '';
        }
    }
}

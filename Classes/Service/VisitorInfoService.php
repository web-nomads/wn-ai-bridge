<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Service;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves display information for a visitor IP (reverse-DNS hostname and,
 * optionally, the country) for the assistant log backend module.
 *
 * Results are cached (30 days) so a page of the log only ever triggers one
 * lookup per unique IP. The country lookup uses an external service and is
 * therefore opt-in via extension configuration; the hostname lookup only uses
 * standard reverse DNS. Everything is best-effort and never throws.
 */
final class VisitorInfoService
{
    private const CACHE_LIFETIME = 2592000; // 30 days
    private const GEO_ENDPOINT = 'http://ip-api.com/json/';

    private readonly FrontendInterface $cache;
    private readonly RequestFactory $requestFactory;
    private readonly ConfigurationService $configurationService;

    public function __construct(
        ?CacheManager $cacheManager = null,
        ?RequestFactory $requestFactory = null,
        ?ConfigurationService $configurationService = null,
    ) {
        $cacheManager ??= GeneralUtility::makeInstance(CacheManager::class);
        $this->cache = $cacheManager->getCache('wn_ai_bridge_visitorinfo');
        $this->requestFactory = $requestFactory ?? GeneralUtility::makeInstance(RequestFactory::class);
        $this->configurationService = $configurationService ?? GeneralUtility::makeInstance(ConfigurationService::class);
    }

    /**
     * @return array{ip: string, hostname: string, country: string, countryCode: string, private: bool}
     */
    public function resolve(string $ip): array
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $this->emptyInfo($ip);
        }

        $geoEnabled = $this->configurationService->isAssistantGeoLookupEnabled();
        $cacheKey = 'vi_' . md5($ip . ($geoEnabled ? '_geo' : ''));

        // Cache access is best-effort: a missing cache table must not break the
        // module, so read/write are guarded.
        try {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable $e) {
            // ignore and resolve fresh
        }

        $private = $this->isPrivateOrReserved($ip);
        $info = [
            'ip' => $ip,
            'hostname' => $this->reverseDns($ip),
            'country' => '',
            'countryCode' => '',
            'private' => $private,
        ];

        if (!$private && $geoEnabled) {
            $geo = $this->lookupCountry($ip);
            $info['country'] = $geo['country'];
            $info['countryCode'] = $geo['countryCode'];
        }

        try {
            $this->cache->set($cacheKey, $info, ['wn_ai_bridge_visitorinfo'], self::CACHE_LIFETIME);
        } catch (\Throwable $e) {
            // ignore — returning uncached info is fine
        }

        return $info;
    }

    private function reverseDns(string $ip): string
    {
        $host = @gethostbyaddr($ip);
        if ($host === false || $host === '' || $host === $ip) {
            return '';
        }
        return $host;
    }

    /**
     * @return array{country: string, countryCode: string}
     */
    private function lookupCountry(string $ip): array
    {
        try {
            $response = $this->requestFactory->request(
                self::GEO_ENDPOINT . rawurlencode($ip) . '?fields=status,country,countryCode',
                'GET',
                ['timeout' => 3, 'headers' => ['Accept' => 'application/json']]
            );

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                return ['country' => '', 'countryCode' => ''];
            }

            $data = json_decode((string)$response->getBody(), true, 8, JSON_THROW_ON_ERROR);
            if (($data['status'] ?? '') !== 'success') {
                return ['country' => '', 'countryCode' => ''];
            }

            return [
                'country' => (string)($data['country'] ?? ''),
                'countryCode' => (string)($data['countryCode'] ?? ''),
            ];
        } catch (\Throwable $e) {
            return ['country' => '', 'countryCode' => ''];
        }
    }

    private function isPrivateOrReserved(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * @return array{ip: string, hostname: string, country: string, countryCode: string, private: bool}
     */
    private function emptyInfo(string $ip): array
    {
        return [
            'ip' => $ip,
            'hostname' => '',
            'country' => '',
            'countryCode' => '',
            'private' => false,
        ];
    }
}

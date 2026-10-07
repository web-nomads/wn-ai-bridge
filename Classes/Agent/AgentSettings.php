<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Agent;

use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Agent analytics switches: extension configuration, overridable per site (tab "AI Bridge")
 */
final class AgentSettings
{
    public const SITE_ANALYTICS = 'aiBridgeAgentAnalytics';
    public const SITE_REFERRALS = 'aiBridgeAgentReferrals';
    public const SITE_VERIFY_IP = 'aiBridgeAgentVerifyIp';
    public const SITE_EXTRA_BOTS = 'aiBridgeAgentExtraBots';

    public const DEFAULT_RETENTION_DAYS = 90;

    /**
     * @param array<string, mixed>|null $extensionConfiguration null = $GLOBALS['TYPO3_CONF_VARS']
     */
    public function __construct(
        private readonly ?SiteFinder $siteFinder = null,
        private readonly ?array $extensionConfiguration = null,
    ) {}

    /**
     * @param array<string, mixed> $site site configuration, empty = extension configuration only
     */
    public function analytics(array $site = []): bool
    {
        return self::switch($site, self::SITE_ANALYTICS, $this->flag('agentAnalytics', true));
    }

    /**
     * @param array<string, mixed> $site
     */
    public function referrals(array $site = []): bool
    {
        return self::switch($site, self::SITE_REFERRALS, $this->flag('agentReferrals', true));
    }

    /**
     * @param array<string, mixed> $site
     */
    public function verifyIp(array $site = []): bool
    {
        return self::switch($site, self::SITE_VERIFY_IP, $this->flag('agentVerifyIp', false));
    }

    /**
     * @param array<string, mixed> $site
     * @return list<string>
     */
    public function extraBots(array $site = []): array
    {
        $own = is_scalar($site[self::SITE_EXTRA_BOTS] ?? null) ? self::tokens((string)$site[self::SITE_EXTRA_BOTS]) : [];

        return array_values(array_unique([...self::tokens((string)($this->configuration()['agentExtraBots'] ?? '')), ...$own]));
    }

    /**
     * 0 keeps everything
     */
    public function retentionDays(): int
    {
        $value = $this->configuration()['agentRetentionDays'] ?? self::DEFAULT_RETENTION_DAYS;

        return is_numeric($value) ? max(0, (int)$value) : self::DEFAULT_RETENTION_DAYS;
    }

    /**
     * Site whose base or language base has this host, null when none
     */
    public function siteOfHost(string $host): ?Site
    {
        $host = strtolower($host);
        if ($host === '' || $this->siteFinder === null) {
            return null;
        }
        foreach ($this->siteFinder->getAllSites(false) as $site) {
            $hosts = [strtolower($site->getBase()->getHost())];
            foreach ($site->getAllLanguages() as $language) {
                $hosts[] = strtolower($language->getBase()->getHost());
            }
            if (in_array($host, $hosts, true)) {
                return $site;
            }
        }

        return null;
    }

    /**
     * "" or missing = default, "1" = on, "0" = off
     *
     * @param array<string, mixed> $site
     */
    public static function switch(array $site, string $key, bool $default): bool
    {
        $value = $site[$key] ?? '';

        return $value === '' || !is_scalar($value) ? $default : (bool)(int)$value;
    }

    /**
     * @return list<string>
     */
    public static function tokens(string $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn(string $token): string => mb_substr(trim($token), 0, 60),
            preg_split('/[,\n]/', $value) ?: []
        ), static fn(string $token): bool => mb_strlen($token) >= 3)));
    }

    private function flag(string $key, bool $default): bool
    {
        $value = $this->configuration()[$key] ?? null;

        return $value === null || $value === '' ? $default : (bool)(int)$value;
    }

    /**
     * @return array<string, mixed>
     */
    private function configuration(): array
    {
        $configuration = $this->extensionConfiguration ?? ($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? []);

        return is_array($configuration) ? $configuration : [];
    }
}

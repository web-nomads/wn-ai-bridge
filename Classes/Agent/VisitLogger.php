<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Agent;

/**
 * Turns a request (live or from a log line) into a stored visit, or nothing
 */
final class VisitLogger
{
    public const SOURCE_LIVE = 'live';
    public const SOURCE_IMPORT = 'import';

    public const TYPE_LLMSTXT = 'llmstxt';
    public const TYPE_LLMSFULL = 'llmsfull';
    public const TYPE_MARKDOWN = 'markdown';
    public const TYPE_PAGE = 'page';

    public const TYPES = [self::TYPE_LLMSTXT, self::TYPE_LLMSFULL, self::TYPE_MARKDOWN, self::TYPE_PAGE];

    public function __construct(
        private readonly AgentDetector $detector,
        private readonly VisitRepository $visits,
        private readonly BotVerifier $verifier,
        private readonly AgentSettings $settings,
    ) {}

    /**
     * @param string $path path with query
     * @param string $type one of TYPES
     * @param array<string, mixed> $site configuration of the site the request belongs to; empty = extension configuration
     * @return string kind stored (crawler/referral), empty when nothing was stored
     */
    public function log(string $siteIdentifier, string $host, string $path, string $type, int $status, string $userAgent, string $referrer, string $ip, int $time, array $site = [], string $source = self::SOURCE_LIVE, string $lineHash = ''): string
    {
        $crawler = $this->settings->analytics($site) ? $this->detector->crawler($userAgent, $this->settings->extraBots($site)) : null;
        if ($crawler !== null) {
            $ipHash = self::hashIp($ip);
            $this->visits->add([
                'time' => $time,
                'site' => $siteIdentifier,
                'host' => $host,
                'path' => self::path($path, true),
                'type' => $type,
                'status' => $status,
                'kind' => AgentDetector::CRAWLER,
                'agent' => $crawler['token'],
                'purpose' => $crawler['purpose'],
                'ipHash' => $ipHash,
                'verified' => $this->settings->verifyIp($site) ? $this->verification($crawler['token'], $ip, $ipHash, $time) : BotVerifier::UNKNOWN,
                'source' => $source,
                'lineHash' => $lineHash,
            ]);

            return AgentDetector::CRAWLER;
        }

        // visitors only land on pages
        if ($type !== self::TYPE_PAGE || !$this->settings->referrals($site)) {
            return '';
        }
        parse_str((string)parse_url($path, PHP_URL_QUERY), $query);
        $platform = $this->detector->platform($referrer, is_string($query['utm_source'] ?? null) ? $query['utm_source'] : '');
        if ($platform === '') {
            return '';
        }
        $this->visits->add([
            'time' => $time,
            'site' => $siteIdentifier,
            'host' => $host,
            'path' => self::path($path, false),
            'type' => $type,
            'status' => $status,
            'kind' => AgentDetector::REFERRAL,
            'agent' => $platform,
            'purpose' => '',
            // visitors are people: no IP address, not even hashed
            'ipHash' => '',
            'verified' => BotVerifier::UNKNOWN,
            'source' => $source,
            'lineHash' => $lineHash,
        ]);

        return AgentDetector::REFERRAL;
    }

    /**
     * llms.txt, llms-full.txt, Markdown version or HTML page; null for assets, feeds and the like
     *
     * @param string|null $contentType null = unknown (log import): pages are paths without file extension
     */
    public static function type(string $path, ?string $contentType): ?string
    {
        $path = rtrim(substr($path, 0, strcspn($path, '?#')), '/');
        if (str_ends_with($path, '/llms-full.txt')) {
            return self::TYPE_LLMSFULL;
        }
        if (str_ends_with($path, '/llms.txt')) {
            return self::TYPE_LLMSTXT;
        }
        if (str_ends_with($path, '.md')) {
            return self::TYPE_MARKDOWN;
        }
        if ($contentType !== null) {
            return str_contains(strtolower($contentType), 'text/html') ? self::TYPE_PAGE : null;
        }
        $extension = strtolower(pathinfo(basename($path), PATHINFO_EXTENSION));

        return $extension === '' || in_array($extension, ['html', 'htm', 'php'], true) ? self::TYPE_PAGE : null;
    }

    /**
     * Salted, shortened hash; counts distinct addresses without storing them
     */
    public static function hashIp(string $ip): string
    {
        if ($ip === '') {
            return '';
        }
        $key = (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');

        return substr(hash_hmac('sha256', $ip, $key !== '' ? $key : 'wn_ai_bridge'), 0, 16);
    }

    /**
     * Path; crawlers keep a short query, referred visits lose it (it may carry personal data)
     */
    public static function path(string $path, bool $keepQuery): string
    {
        $path = $path === '' ? '/' : $path;
        $plain = substr($path, 0, strcspn($path, '?#'));
        $plain = $plain === '' ? '/' : $plain;
        $query = (string)parse_url($path, PHP_URL_QUERY);

        return $keepQuery && $query !== '' ? $plain . '?' . mb_substr($query, 0, 255) : $plain;
    }

    private function verification(string $token, string $ip, string $ipHash, int $time): int
    {
        if (!$this->verifier->canVerify($token)) {
            return BotVerifier::UNKNOWN;
        }

        return $this->visits->recentVerification($ipHash, $token, $time - 86400) ?? $this->verifier->verify($token, $ip);
    }
}

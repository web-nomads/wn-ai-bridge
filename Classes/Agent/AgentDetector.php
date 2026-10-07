<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Agent;

/**
 * Which AI crawler sent a request, or which AI platform referred a visitor
 */
final class AgentDetector
{
    public const CRAWLER = 'crawler';
    public const REFERRAL = 'referral';

    public const PURPOSE_TRAINING = 'training';
    public const PURPOSE_SEARCH = 'search';
    public const PURPOSE_USER = 'user';

    /**
     * User agent token => [operator, purpose]; same list as wn_ai_monitor
     */
    private const CRAWLERS = [
        'OAI-SearchBot' => ['OpenAI', self::PURPOSE_SEARCH],
        'ChatGPT-User' => ['OpenAI', self::PURPOSE_USER],
        'GPTBot' => ['OpenAI', self::PURPOSE_TRAINING],
        'Claude-SearchBot' => ['Anthropic', self::PURPOSE_SEARCH],
        'Claude-User' => ['Anthropic', self::PURPOSE_USER],
        'ClaudeBot' => ['Anthropic', self::PURPOSE_TRAINING],
        'PerplexityBot' => ['Perplexity', self::PURPOSE_SEARCH],
        'Perplexity-User' => ['Perplexity', self::PURPOSE_USER],
        'Googlebot' => ['Google (AI Overviews, AI Mode)', self::PURPOSE_SEARCH],
        'Bingbot' => ['Microsoft (Copilot)', self::PURPOSE_SEARCH],
        'Amazonbot' => ['Amazon', self::PURPOSE_SEARCH],
        'DuckAssistBot' => ['DuckDuckGo', self::PURPOSE_SEARCH],
        'MistralAI-User' => ['Mistral', self::PURPOSE_USER],
        'meta-externalagent' => ['Meta', self::PURPOSE_TRAINING],
        'CCBot' => ['Common Crawl', self::PURPOSE_TRAINING],
        'Bytespider' => ['ByteDance', self::PURPOSE_TRAINING],
        'Applebot' => ['Apple', self::PURPOSE_SEARCH],
        'GoogleOther' => ['Google', self::PURPOSE_TRAINING],
        'cohere-ai' => ['Cohere', self::PURPOSE_TRAINING],
        'YouBot' => ['You.com', self::PURPOSE_SEARCH],
        'Diffbot' => ['Diffbot', self::PURPOSE_TRAINING],
        'Timpibot' => ['Timpi', self::PURPOSE_TRAINING],
        'ImagesiftBot' => ['Hive', self::PURPOSE_TRAINING],
        'PetalBot' => ['Huawei', self::PURPOSE_SEARCH],
    ];

    /**
     * Referrer host (or its ending) => platform name
     */
    private const PLATFORMS = [
        'chatgpt.com' => 'ChatGPT',
        'chat.openai.com' => 'ChatGPT',
        'perplexity.ai' => 'Perplexity',
        'gemini.google.com' => 'Gemini',
        'bard.google.com' => 'Gemini',
        'copilot.microsoft.com' => 'Copilot',
        'claude.ai' => 'Claude',
        'you.com' => 'You.com',
        'poe.com' => 'Poe',
        'phind.com' => 'Phind',
        'kagi.com' => 'Kagi',
        'chat.deepseek.com' => 'DeepSeek',
        'meta.ai' => 'Meta AI',
        'chat.mistral.ai' => 'Mistral',
    ];

    /**
     * utm_source values AI apps set => platform name
     */
    private const UTM_SOURCES = [
        'chatgpt.com' => 'ChatGPT', 'chatgpt' => 'ChatGPT', 'openai' => 'ChatGPT',
        'perplexity' => 'Perplexity', 'perplexity.ai' => 'Perplexity',
        'gemini' => 'Gemini', 'copilot' => 'Copilot', 'claude' => 'Claude', 'claude.ai' => 'Claude',
    ];

    /** @var array<string, array{0: string, 1: string}>|null */
    private static ?array $sorted = null;

    /**
     * @param list<string> $extraTokens further tokens of the extension and site configuration
     * @return array{token: string, operator: string, purpose: string}|null
     */
    public function crawler(string $userAgent, array $extraTokens = []): ?array
    {
        if ($userAgent === '') {
            return null;
        }
        // longest token first, so "ChatGPT-User" wins over a shorter overlap
        foreach (self::crawlers() as $token => [$operator, $purpose]) {
            if (stripos($userAgent, $token) !== false) {
                return ['token' => $token, 'operator' => $operator, 'purpose' => $purpose];
            }
        }
        foreach ($extraTokens as $token) {
            if ($token !== '' && stripos($userAgent, $token) !== false) {
                return ['token' => $token, 'operator' => $token, 'purpose' => self::PURPOSE_TRAINING];
            }
        }

        return null;
    }

    /**
     * Platform name of a referrer or utm_source, empty when no AI platform
     */
    public function platform(string $referrer, string $utmSource = ''): string
    {
        $host = strtolower((string)parse_url($referrer, PHP_URL_HOST));
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        if ($host !== '') {
            foreach (self::PLATFORMS as $domain => $platform) {
                if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                    return $platform;
                }
            }
            if (($host === 'bing.com' || str_ends_with($host, '.bing.com')) && str_contains((string)parse_url($referrer, PHP_URL_PATH), '/chat')) {
                return 'Copilot';
            }
        }

        return self::UTM_SOURCES[strtolower(trim($utmSource))] ?? '';
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    private static function crawlers(): array
    {
        if (self::$sorted === null) {
            $crawlers = self::CRAWLERS;
            uksort($crawlers, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
            self::$sorted = $crawlers;
        }

        return self::$sorted;
    }
}

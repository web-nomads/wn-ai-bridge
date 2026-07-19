<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Security;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Lightweight, dependency-free bot detection for the assistant endpoint.
 *
 * The endpoint is only ever called by the widget's JavaScript running in a real
 * visitor's browser, so we can be strict: a request is treated as a bot unless
 * it looks like a genuine browser-initiated widget call. Three signals are
 * combined:
 *
 *  1. A proof header that the widget always sends and that crawlers/scripts
 *     hitting the endpoint directly do not know about (the strongest signal).
 *  2. A missing or bot-like User-Agent (crawlers, HTTP libraries, headless).
 *  3. A cross-origin Origin/Referer, which a same-origin fetch never produces.
 *
 * This is a heuristic, not authentication — it stops crawlers and casual
 * scripted abuse cheaply without a CAPTCHA or third-party service.
 */
final class BotDetectionService
{
    /**
     * Header (and value) the widget sends to prove the request originates from
     * the widget UI. Kept in sync with Resources/Public/JavaScript/assistant.js.
     */
    public const PROOF_HEADER = 'X-Wn-Ai-Bridge';
    public const PROOF_VALUE = 'widget';

    /**
     * Substrings that identify non-human clients in the User-Agent. Matched
     * case-insensitively. Covers search/AI crawlers and common HTTP clients.
     *
     * Bare generic substrings such as "bot" are intentionally avoided because
     * they match legitimate device names (e.g. the "CUBOT" phone brand). The
     * proof-header check already blocks any crawler regardless of its UA, so
     * this list only needs unambiguous markers.
     *
     * @var list<string>
     */
    private const BOT_USER_AGENT_MARKERS = [
        'crawl', 'spider', 'slurp', 'scrapy',
        'googlebot', 'bingbot', 'yandexbot', 'baiduspider', 'duckduckbot', 'sogou',
        'gptbot', 'oai-searchbot', 'chatgpt-user', 'claudebot', 'claude-web',
        'anthropic-ai', 'ccbot', 'perplexitybot', 'applebot', 'amazonbot',
        'facebookexternalhit', 'meta-externalagent', 'bytespider',
        'semrushbot', 'ahrefsbot', 'mj12bot', 'dotbot', 'petalbot', 'dataforseo',
        'python-requests', 'python-httpx', 'aiohttp', 'go-http-client',
        'curl/', 'wget', 'libwww', 'okhttp', 'java/', 'httpclient', 'apache-httpclient',
        'headlesschrome', 'phantomjs', 'puppeteer', 'playwright',
    ];

    /**
     * Whether the request should be blocked as a bot. A null return from
     * {@see detect()} means "looks human".
     */
    public function isBot(ServerRequestInterface $request): bool
    {
        return $this->detect($request) !== null;
    }

    /**
     * Analyse the request and return a short machine-readable reason when it
     * looks like a bot, or null when it looks like a genuine widget call.
     */
    public function detect(ServerRequestInterface $request): ?string
    {
        $userAgent = trim($request->getHeaderLine('User-Agent'));
        if ($userAgent === '') {
            return 'empty-user-agent';
        }

        if ($this->matchesBotUserAgent($userAgent)) {
            return 'bot-user-agent';
        }

        // The proof header is the decisive signal: only the widget sets it, so a
        // request without it did not come from the assistant UI.
        if (strcasecmp(trim($request->getHeaderLine(self::PROOF_HEADER)), self::PROOF_VALUE) !== 0) {
            return 'missing-widget-header';
        }

        if ($this->isCrossOrigin($request)) {
            return 'cross-origin';
        }

        return null;
    }

    /**
     * User-Agent markers of AI/LLM crawlers specifically, so the bot access log
     * can highlight AI bots. Subset of {@see BOT_USER_AGENT_MARKERS}.
     *
     * @var list<string>
     */
    private const AI_BOT_MARKERS = [
        'gptbot', 'oai-searchbot', 'chatgpt-user', 'claudebot', 'claude-web',
        'anthropic-ai', 'ccbot', 'perplexitybot', 'perplexity-user', 'bytespider',
        'google-extended', 'applebot-extended', 'meta-externalagent', 'diffbot',
        'youbot', 'duckassistbot', 'cohere-ai', 'imagesiftbot', 'timpibot',
    ];

    /**
     * Whether the given User-Agent looks like a known crawler/HTTP client.
     */
    public function isBotUserAgent(string $userAgent): bool
    {
        return $this->matchesBotUserAgent($userAgent);
    }

    /**
     * Whether the User-Agent belongs to a known AI/LLM crawler.
     */
    public function isAiBot(string $userAgent): bool
    {
        $userAgent = strtolower($userAgent);
        foreach (self::AI_BOT_MARKERS as $marker) {
            if (str_contains($userAgent, $marker)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The identifying bot marker found in the User-Agent (e.g. "gptbot",
     * "googlebot"), or null when none matches.
     */
    public function botName(string $userAgent): ?string
    {
        $userAgent = strtolower($userAgent);
        // Prefer the more specific AI markers, then the general list.
        foreach ([self::AI_BOT_MARKERS, self::BOT_USER_AGENT_MARKERS] as $markers) {
            foreach ($markers as $marker) {
                if (str_contains($userAgent, $marker)) {
                    return rtrim($marker, '/');
                }
            }
        }
        return null;
    }

    private function matchesBotUserAgent(string $userAgent): bool
    {
        $userAgent = strtolower($userAgent);
        foreach (self::BOT_USER_AGENT_MARKERS as $marker) {
            if (str_contains($userAgent, $marker)) {
                return true;
            }
        }
        return false;
    }

    /**
     * A same-origin browser fetch carries an Origin/Referer matching the host.
     * If one is present but points at a different host, the call is cross-origin
     * (a strong bot/abuse signal). A missing Origin/Referer is not treated as a
     * bot on its own, because privacy settings can strip it.
     */
    private function isCrossOrigin(ServerRequestInterface $request): bool
    {
        $host = strtolower($request->getUri()->getHost());
        if ($host === '') {
            return false;
        }

        foreach (['Origin', 'Referer'] as $headerName) {
            $value = trim($request->getHeaderLine($headerName));
            if ($value === '') {
                continue;
            }
            $originHost = strtolower((string)parse_url($value, PHP_URL_HOST));
            if ($originHost !== '' && $originHost !== $host) {
                return true;
            }
        }

        return false;
    }
}

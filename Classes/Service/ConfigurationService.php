<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ConfigurationService
{
    /**
     * Request pointer, if injected. Use getRequest() instead of reading this property directly.
     */
    private ?ServerRequestInterface $request = null;

    private readonly SiteFinder $siteFinder;

    public function __construct(
        ?SiteFinder $siteFinder = null
    ) {
        $this->siteFinder = $siteFinder ?? GeneralUtility::makeInstance(SiteFinder::class);
    }

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    protected function getRequest(): ServerRequestInterface
    {
        if ($this->request !== null) {
            return $this->request;
        }

        // Fallback to global request for backward compatibility
        if (($GLOBALS['TYPO3_REQUEST'] ?? null) instanceof ServerRequestInterface) {
            return $GLOBALS['TYPO3_REQUEST'];
        }

        throw new \RuntimeException(
            'No request available. Call setRequest() before using ConfigurationService methods.',
            1765368301
        );
    }

    protected function getCurrentSite(): ?Site
    {
        $request = $this->getRequest();
        $site = $request->getAttribute('site');

        if (!$site instanceof Site) {
            $site = $this->siteFinder->getSiteByPageId(
                $this->getCurrentPageId()
            );
        }

        return $site;
    }

    public function getCurrentPageId(): int
    {
        $request = $this->getRequest();
        
        // Try to get page ID from request attribute first (standard for v12/v13/v14)
        $pageInformation = $request->getAttribute('frontend.page.information');
        if ($pageInformation instanceof \TYPO3\CMS\Frontend\Page\PageInformation) {
            return $pageInformation->getId();
        }

        // Fallback for older versions or specific contexts
        if (isset($GLOBALS['TSFE']) && isset($GLOBALS['TSFE']->id)) {
            return (int)$GLOBALS['TSFE']->id;
        }

        throw new \RuntimeException('Could not determine current page ID from request or TSFE.', 1765368300);
    }

    public function getSiteUrl(): string
    {
        try {
            $site = $this->getCurrentSite();
            if (!$site instanceof Site) {
                return $this->getFallbackBaseUrl();
            }
            $base = (string)$site->getBase();

            if (str_starts_with($base, '/')) {
                $request = $this->getRequest();
                $uri = $request->getUri();
                $base = $uri->getScheme() . '://' . $uri->getHost() . ($uri->getPort() ? ':' . $uri->getPort() : '') . $base;
            }

            return rtrim($base, '/');
        } catch (\Exception $e) {
            return $this->getFallbackBaseUrl();
        }
    }

    protected function getFallbackBaseUrl(): string
    {
        try {
            $request = $this->getRequest();
            $uri = $request->getUri();
            return $uri->getScheme() . '://' . $uri->getHost() . ($uri->getPort() ? ':' . $uri->getPort() : '');
        } catch (\Exception $e) {
            return '';
        }
    }

    public function getSiteName(): string
    {
        $site = $this->getCurrentSite();
        return $site->getIdentifier();
    }

    public function isEnabled(): bool
    {
        $site = $this->getCurrentSite();
        return (bool)($site->getConfiguration()['llmsTxtEnabled'] ?? true);
    }

    public function getTitleOverride(): ?string
    {
        $site = $this->getCurrentSite();
        $title = $site->getConfiguration()['llmsTxtTitle'] ?? '';
        return !empty($title) ? trim($title) : null;
    }

    public function getDescriptionOverride(): ?string
    {
        $site = $this->getCurrentSite();
        $description = $site->getConfiguration()['llmsTxtDescription'] ?? '';
        return !empty($description) ? trim($description) : null;
    }

    public function getAdditionalInfo(): ?string
    {
        $site = $this->getCurrentSite();
        $info = $site->getConfiguration()['llmsTxtAdditionalInfo'] ?? '';
        return !empty($info) ? trim($info) : null;
    }

    public function getContactEmail(): ?string
    {
        $site = $this->getCurrentSite();
        $email = $site->getConfiguration()['llmsTxtContactEmail'] ?? '';
        return !empty($email) ? trim($email) : null;
    }

    public function getKeywords(): array
    {
        $site = $this->getCurrentSite();

        $keywords = $site->getConfiguration()['llmsTxtKeywords'] ?? '';
        if (empty($keywords)) {
            return [];
        }

        return array_map('trim', explode(',', $keywords));
    }

    public function getMaxDepth(): int
    {
        $site = $this->getCurrentSite();

        return (int)($site->getConfiguration()['llmsTxtMaxDepth'] ?? 2);
    }

    public function isFallbackHtmlEnabled(): bool
    {
        $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        return (bool)($extConf['parsingFallbackHtml'] ?? false);
    }

    public function isDebugEnabled(): bool
    {
        $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        return (bool)($extConf['debug'] ?? false);
    }

    public function isCacheEnabled(): bool
    {
        $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        return (bool)($extConf['cacheMarkdown'] ?? false);
    }

    /**
     * Whether the rate limiter for AI-Bridge requests is globally enabled.
     */
    public function isRateLimiterEnabled(): bool
    {
        $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        return (bool)($extConf['rateLimiterEnabled'] ?? false);
    }

    /**
     * Maximum number of AI-Bridge requests per minute per client IP.
     * A value of 0 (or lower) disables the per-IP limit.
     */
    public function getRateLimiterRequestsPerMinute(): int
    {
        $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        return max(0, (int)($extConf['rateLimiterRequestsPerMinute'] ?? 60));
    }

    /**
     * Maximum number of AI-Bridge requests per minute per API key / bot ID.
     * A value of 0 (or lower) disables the per-key limit.
     */
    public function getRateLimiterPerKeyRequestsPerMinute(): int
    {
        $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        return max(0, (int)($extConf['rateLimiterPerKeyRequestsPerMinute'] ?? 120));
    }

    // ------------------------------------------------------------------
    // AI search assistant (site chat bot)
    // ------------------------------------------------------------------

    /**
     * Global master switch (extension configuration) for the AI search assistant.
     * When disabled, the assistant endpoint and widget are inactive on every site.
     */
    public function isAssistantEnabled(): bool
    {
        $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        return (bool)($extConf['assistantEnabled'] ?? false);
    }

    /**
     * Whether the assistant is active for the current site: requires both the
     * global master switch and the per-site toggle (defaults to on when the
     * global switch is set, so a single global flag is enough to get started).
     */
    public function isAssistantEnabledForCurrentSite(): bool
    {
        if (!$this->isAssistantEnabled()) {
            return false;
        }

        $site = $this->getCurrentSite();
        if (!$site instanceof Site) {
            return false;
        }

        return (bool)($site->getConfiguration()['aiAssistantEnabled'] ?? true);
    }

    public function getAssistantProvider(): string
    {
        $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        $provider = trim((string)($extConf['assistantProvider'] ?? 'anthropic'));
        return $provider !== '' ? $provider : 'anthropic';
    }

    public function getAssistantApiKey(): string
    {
        $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        return trim((string)($extConf['assistantApiKey'] ?? ''));
    }

    /**
     * Whether an LLM is configured. Without a key the assistant runs in
     * search-only mode (ranked hits + links, no generated answer).
     */
    public function isAssistantLlmConfigured(): bool
    {
        return $this->getAssistantApiKey() !== '';
    }

    public function getAssistantModel(): string
    {
        $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        $model = trim((string)($extConf['assistantModel'] ?? ''));
        return $model !== '' ? $model : 'claude-haiku-4-5';
    }

    /**
     * Configured search sources: auto | kesearch | indexed | pages.
     */
    public function getAssistantSearchSources(): string
    {
        $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        $sources = trim((string)($extConf['assistantSearchSources'] ?? 'auto'));
        return $sources !== '' ? $sources : 'auto';
    }

    public function getAssistantMaxResults(): int
    {
        $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        return max(1, (int)($extConf['assistantMaxResults'] ?? 5));
    }

    public function getAssistantMaxTokens(): int
    {
        $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        return max(256, (int)($extConf['assistantMaxTokens'] ?? 1024));
    }

    /**
     * Whether requests to the assistant endpoint are checked for being made by a
     * real human via the widget rather than a bot/crawler/script. On by default.
     */
    public function isAssistantBotProtectionEnabled(): bool
    {
        $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        return (bool)($extConf['assistantBotProtection'] ?? true);
    }

    /**
     * Whether this site is a OnePager, i.e. its sub-pages are rendered as
     * anchor sections on the homepage. When enabled, assistant result links to
     * direct children of the site root point to "site/#section" instead of a
     * separate sub-page URL.
     */
    public function isAssistantOnePagerEnabled(): bool
    {
        $site = $this->getCurrentSite();
        return $site instanceof Site && (bool)($site->getConfiguration()['aiAssistantOnePager'] ?? false);
    }

    /**
     * Optional per-site page id that limits the search to a subtree (0 = whole site).
     */
    public function getAssistantSearchRootPageId(): int
    {
        $site = $this->getCurrentSite();
        if (!$site instanceof Site) {
            return 0;
        }
        return max(0, (int)($site->getConfiguration()['aiAssistantSearchPid'] ?? 0));
    }

    public function getAssistantTitle(): string
    {
        $site = $this->getCurrentSite();
        $title = $site instanceof Site ? trim((string)($site->getConfiguration()['aiAssistantTitle'] ?? '')) : '';
        return $title !== '' ? $title : 'Wie kann ich helfen?';
    }

    public function getAssistantWelcomeMessage(): string
    {
        $site = $this->getCurrentSite();
        $welcome = $site instanceof Site ? trim((string)($site->getConfiguration()['aiAssistantWelcome'] ?? '')) : '';
        return $welcome !== ''
            ? $welcome
            : 'Stellen Sie mir eine Frage – ich durchsuche die Website und zeige Ihnen, wo Sie die passenden Informationen finden.';
    }

    public function getAssistantPlaceholder(): string
    {
        $site = $this->getCurrentSite();
        $placeholder = $site instanceof Site ? trim((string)($site->getConfiguration()['aiAssistantPlaceholder'] ?? '')) : '';
        return $placeholder !== '' ? $placeholder : 'Ihre Frage …';
    }

    /**
     * Whether the assistant overlay should open automatically after a delay.
     */
    public function isAssistantAutoOpenEnabled(): bool
    {
        $site = $this->getCurrentSite();
        return $site instanceof Site && (bool)($site->getConfiguration()['aiAssistantAutoOpen'] ?? false);
    }

    /**
     * Delay in seconds before the overlay opens automatically (min 0).
     */
    public function getAssistantAutoOpenDelay(): int
    {
        $site = $this->getCurrentSite();
        if (!$site instanceof Site) {
            return 5;
        }
        return max(0, (int)($site->getConfiguration()['aiAssistantAutoOpenDelay'] ?? 5));
    }

    /**
     * Accent colour (button, header, links) as a CSS hex value so the widget can
     * match the site design. Falls back to the default blue when unset/invalid.
     */
    public function getAssistantAccentColor(): string
    {
        $default = '#2563eb';
        $site = $this->getCurrentSite();
        if (!$site instanceof Site) {
            return $default;
        }

        $color = trim((string)($site->getConfiguration()['aiAssistantAccentColor'] ?? ''));
        // Accept #rgb / #rrggbb only; ignore anything else to avoid CSS injection.
        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) === 1
            ? $color
            : $default;
    }

    /**
     * Additional site-specific instructions appended to the assistant system prompt
     * (persona, tone, escalation hints, etc.).
     */
    public function getAssistantSystemPrompt(): string
    {
        $site = $this->getCurrentSite();
        return $site instanceof Site ? trim((string)($site->getConfiguration()['aiAssistantSystemPrompt'] ?? '')) : '';
    }
}

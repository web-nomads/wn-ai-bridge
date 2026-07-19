<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

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
     * Whether bot/crawler accesses to llms.txt, the Markdown (.md) versions and
     * normal pages are recorded for review in the "Bot Access Log" backend
     * module. Off by default.
     */
    public function isBotAccessLoggingEnabled(): bool
    {
        $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        return (bool)($extConf['botAccessLogging'] ?? false);
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
     * Sampling temperature for the LLM answer: a decimal between 0.0
     * (deterministic/precise) and 1.0 (more creative). Non-numeric input falls
     * back to 0.2; values outside the range are clamped.
     */
    public function getAssistantTemperature(): float
    {
        $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        $raw = str_replace(',', '.', trim((string)($extConf['assistantTemperature'] ?? '0.2')));
        if (!is_numeric($raw)) {
            return 0.2;
        }
        return max(0.0, min(1.0, (float)$raw));
    }

    /**
     * Global agent instructions (persona, tone, rules) configured in the
     * extension configuration and applied to every site's assistant answers.
     * Per-site instructions can additionally be set via getAssistantSystemPrompt().
     */
    public function getAssistantInstructions(): string
    {
        $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        return trim((string)($extConf['assistantInstructions'] ?? ''));
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
     * Whether every question/answer is persisted to the assistant log (with
     * date, IP, provider and token usage) for review in the backend module.
     * Off by default for data-protection reasons.
     */
    public function isAssistantLoggingEnabled(): bool
    {
        $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        return (bool)($extConf['assistantLogging'] ?? false);
    }

    /**
     * Whether the assistant captures visitor corrections for owner-moderated
     * learning on the current site. Configured per site in the Site settings and
     * off by default. When on, a detected correction is stored as "pending" for
     * review in the backend; only approved corrections are fed back into future
     * answers (and only when the question thematically matches).
     */
    public function isAssistantLearningEnabled(): bool
    {
        try {
            $site = $this->getCurrentSite();
            return $site instanceof Site && (bool)($site->getConfiguration()['aiAssistantLearning'] ?? false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether the backend log module may resolve the country for an IP via an
     * external geolocation service. Off by default (privacy).
     */
    public function isAssistantGeoLookupEnabled(): bool
    {
        $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        return (bool)($extConf['assistantLogGeoLookup'] ?? false);
    }

    /**
     * USD-to-CHF conversion rate used to estimate LLM cost in the log module.
     * Model prices are quoted in USD; this converts them to CHF.
     */
    public function getAssistantUsdToChfRate(): float
    {
        $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        $rate = (float)str_replace(',', '.', (string)($extConf['assistantUsdToChfRate'] ?? '0.90'));
        return $rate > 0 ? $rate : 0.90;
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
        return $this->getLocalizedSiteText('aiAssistantTitle', 'widget.title.default', 'Wie kann ich helfen?');
    }

    public function getAssistantWelcomeMessage(): string
    {
        return $this->getLocalizedSiteText(
            'aiAssistantWelcome',
            'widget.welcome.default',
            'Stellen Sie mir eine Frage – ich durchsuche die Website und zeige Ihnen, wo Sie die passenden Informationen finden.'
        );
    }

    public function getAssistantPlaceholder(): string
    {
        return $this->getLocalizedSiteText('aiAssistantPlaceholder', 'widget.placeholder.default', 'Ihre Frage …');
    }

    /**
     * Resolve a per-site text that can be maintained per language: prefer the
     * value set on the current site language, then the site-level value, then a
     * translated default for the current language (finally the hard fallback).
     */
    private function getLocalizedSiteText(string $key, string $defaultLabelKey, string $hardFallback): string
    {
        $value = $this->getLanguageConfigValue($key);
        if ($value === '') {
            $value = $this->getSiteConfigurationValue($key);
        }
        if ($value !== '') {
            return $value;
        }

        $default = $this->translate($defaultLabelKey);
        return $default !== '' ? $default : $hardFallback;
    }

    /**
     * The current site language, or null when it cannot be resolved (e.g. in a
     * context without a frontend request).
     */
    public function getCurrentSiteLanguage(): ?SiteLanguage
    {
        try {
            $language = $this->getRequest()->getAttribute('language');
            return $language instanceof SiteLanguage ? $language : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Read a custom site-language configuration value (maintained per language in
     * the Site settings), trimmed. Empty string when unset/unavailable.
     */
    private function getLanguageConfigValue(string $key): string
    {
        $language = $this->getCurrentSiteLanguage();
        if ($language === null) {
            return '';
        }
        return trim((string)($language->toArray()[$key] ?? ''));
    }

    /**
     * Translate a widget label key into the current site language, using the
     * bundled default translations. Returns '' when the key cannot be resolved.
     */
    public function translate(string $key): string
    {
        try {
            $factory = GeneralUtility::makeInstance(LanguageServiceFactory::class);
            $language = $this->getCurrentSiteLanguage();
            $languageService = $language !== null
                ? $factory->createFromSiteLanguage($language)
                : $factory->create('default');

            return trim((string)$languageService->sL(
                'LLL:EXT:wn_ai_bridge/Resources/Private/Language/locallang_widget.xlf:' . $key
            ));
        } catch (\Throwable $e) {
            return '';
        }
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
     * Web URL of the optional per-site avatar (logo/photo) shown in the widget.
     * Accepts an absolute/root-relative URL, an EXT: path or a path relative to
     * the public root (e.g. "fileadmin/logo.png"). Returns '' when unset or the
     * file cannot be resolved.
     */
    public function getAssistantAvatarUrl(): string
    {
        return $this->resolveResourceUrl($this->getSiteConfigurationValue('aiAssistantAvatar'));
    }

    /**
     * Web URL of an optional per-site custom CSS file, loaded after the widget's
     * default stylesheet so site-specific overrides win. Same path handling as
     * the avatar (URL / EXT: / public-root-relative path).
     */
    public function getAssistantCustomCssUrl(): string
    {
        return $this->resolveResourceUrl($this->getSiteConfigurationValue('aiAssistantCustomCss'));
    }

    private function getSiteConfigurationValue(string $key): string
    {
        $site = $this->getCurrentSite();
        return $site instanceof Site ? trim((string)($site->getConfiguration()[$key] ?? '')) : '';
    }

    /**
     * Resolve a configured resource reference to a public web URL. Accepts an
     * absolute/protocol-relative URL, a root-relative path, an EXT: path or a
     * path relative to the public root. Returns '' when unset or unresolvable.
     */
    private function resolveResourceUrl(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // Already a URL (absolute, protocol-relative) or a root-relative path.
        if (preg_match('#^(https?:)?//#', $value) === 1 || str_starts_with($value, '/')) {
            return $value;
        }

        try {
            if (str_starts_with($value, 'EXT:')) {
                return PathUtility::getPublicResourceWebPath($value);
            }

            // Path relative to the public root, e.g. "fileadmin/custom.css".
            $absolute = GeneralUtility::getFileAbsFileName($value);
            if ($absolute !== '' && is_file($absolute)) {
                return PathUtility::getAbsoluteWebPath($absolute);
            }
        } catch (\Throwable $e) {
            return '';
        }

        return '';
    }

    /**
     * Per-site colour overrides for the widget, keyed by their CSS custom
     * property suffix (e.g. "accent" -> --wn-ai-accent). Only explicitly
     * configured, valid hex values are returned; anything else is omitted so the
     * stylesheet defaults (including dark-mode) still apply.
     *
     * @return array<string, string>
     */
    public function getAssistantColors(): array
    {
        $site = $this->getCurrentSite();
        $configuration = $site instanceof Site ? $site->getConfiguration() : [];

        // CSS variable suffix => site configuration field.
        $map = [
            'accent' => 'aiAssistantAccentColor',
            'bg' => 'aiAssistantBgColor',
            'fg' => 'aiAssistantTextColor',
            'user-bg' => 'aiAssistantUserBgColor',
            'user-fg' => 'aiAssistantUserTextColor',
            'user-link' => 'aiAssistantUserLinkColor',
            'assistant-bg' => 'aiAssistantAssistantBgColor',
            'assistant-fg' => 'aiAssistantAssistantTextColor',
            'assistant-link' => 'aiAssistantAssistantLinkColor',
            'sources-bg' => 'aiAssistantSourcesBgColor',
            'sources-fg' => 'aiAssistantSourcesTextColor',
            'sources-link' => 'aiAssistantSourcesLinkColor',
        ];

        $colors = [];
        foreach ($map as $cssKey => $field) {
            $value = trim((string)($configuration[$field] ?? ''));
            // Accept #rgb / #rrggbb only; ignore anything else to avoid CSS injection.
            if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1) {
                $colors[$cssKey] = $value;
            }
        }

        return $colors;
    }

    /**
     * Additional site-specific instructions appended to the assistant system prompt
     * (persona, tone, escalation hints, etc.).
     */
    public function getAssistantSystemPrompt(): string
    {
        // Prefer a per-language system prompt, fall back to the site-level one.
        $value = $this->getLanguageConfigValue('aiAssistantSystemPrompt');
        if ($value !== '') {
            return $value;
        }
        return $this->getSiteConfigurationValue('aiAssistantSystemPrompt');
    }
}

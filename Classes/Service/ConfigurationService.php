<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use WebNomads\WnAiBridge\Subscription\SubscriptionService;
use WebNomads\WnAiBridge\Subscription\SubscriptionStatus;

class ConfigurationService
{
    /**
     * Request pointer, if injected. Use getRequest() instead of reading this property directly.
     */
    private ?ServerRequestInterface $request = null;

    private readonly SiteFinder $siteFinder;

    private readonly SubscriptionService $subscriptionService;

    public function __construct(
        ?SiteFinder $siteFinder = null,
        ?SubscriptionService $subscriptionService = null
    ) {
        $this->siteFinder = $siteFinder ?? GeneralUtility::makeInstance(SiteFinder::class);
        $this->subscriptionService = $subscriptionService ?? GeneralUtility::makeInstance(SubscriptionService::class);
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

    /**
     * Scheme, host and port of the current site — without the entry point path.
     *
     * Needed wherever a router result is turned into an absolute URL. When the
     * site base is a path ("/camino/"), the router returns a path that already
     * carries that entry point, so prefixing getSiteUrl() would repeat it.
     */
    public function getSiteOrigin(): string
    {
        try {
            $site = $this->getCurrentSite();
            if ($site instanceof Site) {
                $base = $site->getBase();
                $host = $base->getHost();

                if ($host !== '') {
                    $port = $base->getPort();

                    return ($base->getScheme() ?: 'https') . '://' . $host . ($port ? ':' . $port : '');
                }
            }
        } catch (\Exception $e) {
            // No resolvable site — the request below is the better source anyway.
        }

        return $this->getFallbackBaseUrl();
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
        $title = $this->getLocalizedLlmsValue('llmsTxtTitle');
        return $title !== '' ? $title : null;
    }

    public function getDescriptionOverride(): ?string
    {
        $description = $this->getLocalizedLlmsValue('llmsTxtDescription');
        return $description !== '' ? $description : null;
    }

    public function getAdditionalInfo(): ?string
    {
        $info = $this->getLocalizedLlmsValue('llmsTxtAdditionalInfo');
        return $info !== '' ? $info : null;
    }

    public function getContactEmail(): ?string
    {
        $site = $this->getCurrentSite();
        $email = $site->getConfiguration()['llmsTxtContactEmail'] ?? '';
        return !empty($email) ? trim($email) : null;
    }

    public function getKeywords(): array
    {
        $keywords = $this->getLocalizedLlmsValue('llmsTxtKeywords');
        if ($keywords === '') {
            return [];
        }

        return array_map('trim', explode(',', $keywords));
    }

    /**
     * Resolve a per-site llms.txt text that can be maintained per language:
     * prefer the value set on the current site language, then fall back to the
     * site-level value. Returns '' when neither is set.
     */
    private function getLocalizedLlmsValue(string $key): string
    {
        $value = $this->getLanguageConfigValue($key);
        if ($value !== '') {
            return $value;
        }
        return $this->getSiteConfigurationValue($key);
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

    /**
     * Whether the llms-full.txt endpoint is served. Off by default: one request
     * renders every page of the site, which is far more expensive than the link
     * list of llms.txt.
     */
    public function isLlmsFullTxtEnabled(): bool
    {
        $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        return (bool)($extConf['llmsFullTxt'] ?? false);
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
     * The current subscription status. Everything the subscription covers (chat
     * bot, log module, corrections) is gated on this; llms.txt and the Markdown
     * export are unaffected and keep working without a key.
     */
    public function getSubscriptionStatus(): SubscriptionStatus
    {
        return $this->subscriptionService->getStatus();
    }

    /**
     * Whether the assistant is active for the current site: requires a valid
     * subscription covering the chat bot, the global master switch and the
     * per-site toggle (which defaults to on, so a single global flag is enough to
     * get started).
     */
    public function isAssistantEnabledForCurrentSite(): bool
    {
        if (!$this->subscriptionService->hasFeature(SubscriptionService::FEATURE_CHATBOT)) {
            return false;
        }

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

    /**
     * The LLM API key this site bills against.
     *
     * Maintained in the Site settings. The extension configuration is still read
     * when a site names none, so an installation that has not run the upgrade
     * wizard yet keeps working on the key it always used.
     */
    public function getAssistantApiKey(): string
    {
        $key = $this->safeSiteConfigurationValue('aiAssistantApiKey');
        if ($key !== '') {
            return $key;
        }

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

    /** The temperature used when a site names none. */
    public const DEFAULT_TEMPERATURE = 0.2;

    /**
     * Sampling temperature for the LLM answer: a decimal between 0.0
     * (deterministic/precise) and 1.0 (more creative). Maintained in the Site
     * settings, with the extension configuration as the legacy fallback.
     * Non-numeric input falls back to the default; values outside the range are
     * clamped.
     */
    public function getAssistantTemperature(): float
    {
        $raw = $this->safeSiteConfigurationValue('aiAssistantTemperature');
        if ($raw === '') {
            $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
            $raw = trim((string)($extConf['assistantTemperature'] ?? ''));
        }

        return self::normaliseTemperature($raw);
    }

    /**
     * Read a temperature as it may be written by hand: with a comma, with
     * padding, or not as a number at all.
     *
     * Public and static so the upgrade wizard can put the same value into a site
     * configuration that this would read back out of it.
     */
    public static function normaliseTemperature(string $raw): float
    {
        $raw = str_replace(',', '.', trim($raw));
        if ($raw === '' || !is_numeric($raw)) {
            return self::DEFAULT_TEMPERATURE;
        }

        return max(0.0, min(1.0, (float)$raw));
    }

    /**
     * Agent instructions (persona, tone, rules) applied to this site's assistant
     * answers. Maintained in the Site settings, with the extension configuration
     * as the legacy fallback. Further per-site notes can be added through
     * getAssistantSystemPrompt(), which is also maintained per language.
     */
    public function getAssistantInstructions(): string
    {
        $instructions = $this->safeSiteConfigurationValue('aiAssistantInstructions');
        if ($instructions !== '') {
            return $instructions;
        }

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
     * Whether the assistant uses its local learning source on the current site.
     * Requires a subscription covering corrections and the per-site switch in the
     * Site settings (off by default). When on, a detected correction is stored as
     * "pending" for review in the backend; only approved entries are played back
     * into future answers, and only when the question matches them in meaning.
     */
    public function isAssistantLearningEnabled(): bool
    {
        if (!$this->subscriptionService->hasFeature(SubscriptionService::FEATURE_CORRECTIONS)) {
            return false;
        }

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
     * Rate the USD model prices are converted with before the log module shows
     * them. Named after what it does rather than after one currency, so an
     * installation that bills in euros no longer has to read "CHF" everywhere.
     *
     * The former "assistantUsdToChfRate" is still read when the new setting is
     * absent, so an installation that has not run the upgrade wizard yet keeps
     * the rate it had.
     */
    public function getAssistantUsdConversionRate(): float
    {
        $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        $raw = trim((string)($extConf['assistantUsdConversionRate'] ?? ''));
        if ($raw === '') {
            $raw = trim((string)($extConf['assistantUsdToChfRate'] ?? ''));
        }

        $rate = (float)str_replace(',', '.', $raw);

        return $rate > 0 ? $rate : 0.90;
    }

    /**
     * The currency the converted cost is quoted in — a label, not a conversion:
     * it has to match the rate above, which nothing here can check.
     */
    public function getAssistantCurrency(): string
    {
        $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        $currency = trim((string)($extConf['assistantCurrency'] ?? ''));

        return $currency !== '' ? $currency : 'CHF';
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
     * Whether llms.txt and the Markdown export treat this site as a OnePager.
     *
     * Off by default: a site whose sub-pages are real pages must produce real
     * page URLs. Only when this is on does a direct child of the site root
     * become an anchor on the home page ("/#packing-list").
     *
     * Falls back to the assistant's own OnePager switch while this one has never
     * been saved, so sites that were configured before it existed keep the
     * behaviour they had.
     */
    public function isLlmsTxtOnePagerEnabled(): bool
    {
        $site = $this->getCurrentSite();
        if (!$site instanceof Site) {
            return false;
        }

        $configuration = $site->getConfiguration();
        if (array_key_exists('llmsTxtOnePager', $configuration)) {
            return (bool)$configuration['llmsTxtOnePager'];
        }

        return (bool)($configuration['aiAssistantOnePager'] ?? false);
    }

    /**
     * The page the assistant searches below.
     *
     * A site can narrow this to a subtree of its own ("aiAssistantSearchPid");
     * left unset, it is the site's own root page. It used to be 0 there, which
     * the providers read as "no restriction" — and on an installation serving
     * more than one site that meant the whole page tree. A visitor asking the
     * assistant on one site was answered with pages from the other.
     */
    public function getAssistantSearchRootPageId(): int
    {
        $site = $this->getCurrentSite();
        if (!$site instanceof Site) {
            return 0;
        }

        $configured = max(0, (int)($site->getConfiguration()['aiAssistantSearchPid'] ?? 0));

        return $configured > 0 ? $configured : max(0, $site->getRootPageId());
    }

    /**
     * The root page of the site the current request belongs to, 0 when it cannot
     * be resolved. The boundary every search hit has to fall inside.
     */
    public function getCurrentSiteRootPageId(): int
    {
        try {
            $site = $this->getCurrentSite();

            return $site instanceof Site ? max(0, $site->getRootPageId()) : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * The identifier of the site the current request belongs to, '' when it
     * cannot be resolved.
     */
    public function getCurrentSiteIdentifier(): string
    {
        try {
            $site = $this->getCurrentSite();

            return $site instanceof Site ? $site->getIdentifier() : '';
        } catch (\Throwable $e) {
            return '';
        }
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
     * The same, but never throwing.
     *
     * The settings read through this one are also asked for outside a frontend
     * request — from the command line, from a backend module — where there is no
     * site to resolve. An exception there would take down whatever was running;
     * an empty value falls through to the extension configuration instead.
     */
    private function safeSiteConfigurationValue(string $key): string
    {
        try {
            return $this->getSiteConfigurationValue($key);
        } catch (\Throwable $e) {
            return '';
        }
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

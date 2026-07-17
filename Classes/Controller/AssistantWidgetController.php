<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Controller;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Attribute\AsAllowedCallable;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use WebNomads\WnAiBridge\Middleware\AssistantRequestMiddleware;
use WebNomads\WnAiBridge\Service\ConfigurationService;

/**
 * Renders the floating chat widget into the frontend.
 *
 * Invoked as an uncached TypoScript USER_INT object so it can (a) evaluate the
 * per-site enable flag on every request and (b) only emit the CSS/JS when the
 * widget is actually shown. The assets are output as plain <link>/<script> tags
 * in the returned markup rather than via PageRenderer, because PageRenderer
 * additions made inside a USER_INT can be applied too late to reach the rendered
 * page. Emitting the tags directly avoids that timing problem entirely.
 */
final class AssistantWidgetController
{
    private const CSS_PATH = 'EXT:wn_ai_bridge/Resources/Public/Css/assistant.css';
    private const JS_PATH = 'EXT:wn_ai_bridge/Resources/Public/JavaScript/assistant.js';

    public function __construct(
        private readonly ConfigurationService $configurationService,
    ) {}

    #[AsAllowedCallable]
    public function render(string $content, array $conf, ?ServerRequestInterface $request = null): string
    {
        $request ??= $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequestInterface) {
            $this->configurationService->setRequest($request);
        }

        if (!$this->configurationService->isAssistantEnabledForCurrentSite()) {
            return '';
        }

        $config = [
            'endpoint' => $this->buildEndpointUrl($request),
            'title' => $this->configurationService->getAssistantTitle(),
            'welcome' => $this->configurationService->getAssistantWelcomeMessage(),
            'placeholder' => $this->configurationService->getAssistantPlaceholder(),
            'autoOpen' => $this->configurationService->isAssistantAutoOpenEnabled(),
            'autoOpenDelay' => $this->configurationService->getAssistantAutoOpenDelay(),
            'accentColor' => $this->configurationService->getAssistantAccentColor(),
            'labels' => [
                'toggle' => 'Suchassistent öffnen',
                'send' => 'Senden',
                'close' => 'Schließen',
                'sources' => 'Passende Seiten',
                'thinking' => 'Ich durchsuche die Website …',
                'error' => 'Es ist ein Fehler aufgetreten. Bitte versuchen Sie es erneut.',
                'rateLimited' => 'Zu viele Anfragen. Bitte warten Sie einen Moment.',
            ],
        ];

        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $cssUrl = htmlspecialchars($this->assetWebPath(self::CSS_PATH), ENT_QUOTES, 'UTF-8');
        $jsUrl = htmlspecialchars($this->assetWebPath(self::JS_PATH), ENT_QUOTES, 'UTF-8');

        return sprintf(
            '<div id="wn-ai-assistant" data-wn-ai-config="%s"></div>'
                . "\n" . '<link rel="stylesheet" href="%s">'
                . "\n" . '<script src="%s" defer></script>',
            htmlspecialchars((string)$json, ENT_QUOTES, 'UTF-8'),
            $cssUrl,
            $jsUrl,
        );
    }

    /**
     * Resolve the public web URL for an EXT: resource and append a cache-busting
     * query based on the file modification time.
     */
    private function assetWebPath(string $extPath): string
    {
        $webPath = PathUtility::getPublicResourceWebPath($extPath);
        $absolute = GeneralUtility::getFileAbsFileName($extPath);
        if ($absolute !== '' && is_file($absolute)) {
            $webPath .= '?' . filemtime($absolute);
        }
        return $webPath;
    }

    /**
     * Build the endpoint URL relative to the current language base, so the
     * request keeps the language prefix (e.g. "/de/") and the middleware
     * resolves the correct frontend language. Falls back to the site path for
     * sub-directory installs.
     */
    private function buildEndpointUrl(?ServerRequestInterface $request): string
    {
        $base = '/';

        if ($request instanceof ServerRequestInterface) {
            $language = $request->getAttribute('language');
            if ($language instanceof SiteLanguage) {
                $languagePath = $language->getBase()->getPath();
                if ($languagePath !== '') {
                    $base = $languagePath;
                }
            } elseif (($normalizedParams = $request->getAttribute('normalizedParams')) !== null
                && method_exists($normalizedParams, 'getSitePath')
            ) {
                $base = $normalizedParams->getSitePath() ?: '/';
            }
        }

        return rtrim($base, '/') . AssistantRequestMiddleware::ENDPOINT_PATH;
    }
}

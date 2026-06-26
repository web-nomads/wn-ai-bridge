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
}

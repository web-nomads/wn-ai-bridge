<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Service;

use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WebNomads\WnAiBridge\Builder\NavigationBuilder;
use WebNomads\WnAiBridge\Repository\PageRepository;

/**
 * Assembles the textual llms.txt document for a site.
 *
 * The output follows the llmstxt.org layout: a small key/value header, the
 * site title and description, an optional topics/contact block, the navigation
 * tree and finally any free-form additional information the editor configured.
 * All the heavy lifting (page lookups, navigation traversal, configuration
 * access) is delegated to the injected collaborators.
 */
class LlmsTxtGeneratorService
{
    private readonly ConfigurationService $configurationService;
    private readonly PageRepository $pageRepository;
    private readonly NavigationBuilder $navigationBuilder;
    private readonly SiteFinder $siteFinder;

    public function __construct(
        ?ConfigurationService $configurationService = null,
        ?PageRepository $pageRepository = null,
        ?NavigationBuilder $navigationBuilder = null,
        ?SiteFinder $siteFinder = null
    ) {
        $this->configurationService = $configurationService ?? GeneralUtility::makeInstance(ConfigurationService::class);
        $this->pageRepository = $pageRepository ?? GeneralUtility::makeInstance(PageRepository::class);
        $this->navigationBuilder = $navigationBuilder ?? GeneralUtility::makeInstance(NavigationBuilder::class);
        $this->siteFinder = $siteFinder ?? GeneralUtility::makeInstance(SiteFinder::class);
    }

    /**
     * Build the complete llms.txt document for the given page context.
     */
    public function generateLlmsTxt(int $currentPageId, int $languageUid = 0): string
    {
        if (!$this->configurationService->isEnabled()) {
            return "# LLMS.TXT generation is disabled for this site\n";
        }

        $site = $this->siteFinder->getSiteByPageId($currentPageId);
        $homePage = $this->pageRepository->findById($site->getRootPageId());

        $lines = [
            'llmstxt: 1.0',
            'site: ' . $this->configurationService->getSiteUrl(),
            '',
        ];

        $this->appendHeader($lines, $homePage);
        $this->appendTopicsAndContact($lines);
        $this->appendNavigation($lines, $site->getRootPageId(), $languageUid);
        $this->appendAdditionalInfo($lines);

        return implode("\n", $lines) . "\n";
    }

    /**
     * Append the "# Title" and "> Description" block, preferring the configured
     * overrides over the home page's own metadata.
     *
     * @param list<string> $lines
     * @param array<string, mixed> $homePage
     */
    private function appendHeader(array &$lines, array $homePage): void
    {
        $title = $this->configurationService->getTitleOverride() ?: ($homePage['title'] ?? '');
        if (!empty($title)) {
            $title = preg_replace('/\s+/', ' ', trim((string)$title));
            $lines[] = "# $title";
        }

        $lines[] = '';

        $description = $this->configurationService->getDescriptionOverride() ?: ($homePage['description'] ?? '');
        if (!empty($description)) {
            $lines[] = "> $description";
        }
    }

    /**
     * Append the optional "**Topics:**" and "**Contact:**" metadata lines.
     *
     * @param list<string> $lines
     */
    private function appendTopicsAndContact(array &$lines): void
    {
        $keywords = $this->configurationService->getKeywords();
        if (!empty($keywords)) {
            $lines[] = '';
            $lines[] = '**Topics:** ' . implode(', ', $keywords);
        }

        $contactEmail = $this->configurationService->getContactEmail();
        if (!empty($contactEmail)) {
            $lines[] = '**Contact:** ' . $contactEmail;
        }
    }

    /**
     * Append the "## Main Page Structure" heading and the rendered navigation
     * tree for the requested language.
     *
     * @param list<string> $lines
     */
    private function appendNavigation(array &$lines, int $rootPageId, int $languageUid): void
    {
        $lines[] = '';
        $lines[] = '## Main Page Structure';
        $lines[] = '';

        $navigationStructure = $this->navigationBuilder->build(
            $rootPageId,
            $this->configurationService->getMaxDepth(),
            $languageUid
        );

        foreach ($this->navigationBuilder->formatAsMarkdown($navigationStructure, $languageUid) as $line) {
            $lines[] = $line;
        }
    }

    /**
     * Append the editor's free-form additional information, separated by a
     * horizontal rule.
     *
     * @param list<string> $lines
     */
    private function appendAdditionalInfo(array &$lines): void
    {
        $additionalInfo = $this->configurationService->getAdditionalInfo();
        if (!empty($additionalInfo)) {
            $lines[] = '';
            $lines[] = '---';
            $lines[] = '';
            $lines[] = $additionalInfo;
        }
    }
}

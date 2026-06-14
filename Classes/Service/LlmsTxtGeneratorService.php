<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Service;

use TYPO3\CMS\Core\Site\SiteFinder;
use WebNomads\WnAiBridge\Builder\NavigationBuilder;
use WebNomads\WnAiBridge\Repository\PageRepository;

/**
 * Application Service for generating llms.txt content
 * Orchestrates repositories, builders, and other services to build the final output
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
        $this->configurationService = $configurationService ?? \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ConfigurationService::class);
        $this->pageRepository = $pageRepository ?? \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(PageRepository::class);
        $this->navigationBuilder = $navigationBuilder ?? \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(NavigationBuilder::class);
        $this->siteFinder = $siteFinder ?? \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(SiteFinder::class);
    }

    /**
     * Generate llms.txt content
     */
    public function generateLlmsTxt(int $currentPageId, int $languageUid = 0): string
    {
        $lines = [];

        if (!$this->configurationService->isEnabled()) {
            return "# LLMS.TXT generation is disabled for this site\n";
        }

        $lines[] = 'llmstxt: 1.0';
        $lines[] = 'site: ' . $this->configurationService->getSiteUrl();
        $lines[] = '';

        $site = $this->siteFinder->getSiteByPageId($currentPageId);
        $homePage = $this->pageRepository->findById($site->getRootPageId());

        $title = $this->configurationService->getTitleOverride() ?: $homePage['title'] ?? '';
        if (!empty($title)) {
            $title = preg_replace('/\s+/', ' ', trim($title));
            $lines[] = "# $title";
        }

        $lines[] = '';

        $description = $this->configurationService->getDescriptionOverride() ?: $homePage['description'] ?? '';
        if (!empty($description)) {
            $lines[] = "> $description";
        }

        $lines = array_merge($lines, $this->buildMetadataSection());

        $lines[] = '';
        $lines[] = '## Main Page Structure';
        $lines[] = '';

        $maxDepth = $this->configurationService->getMaxDepth();
        $navigationStructure = $this->navigationBuilder->build(
            $site->getRootPageId(),
            $maxDepth,
            $languageUid
        );
        $navigationLines = $this->navigationBuilder->formatAsMarkdown($navigationStructure, $languageUid);
        $lines = array_merge($lines, $navigationLines);

        $additionalInfo = $this->configurationService->getAdditionalInfo();
        if (!empty($additionalInfo)) {
            $lines[] = '';
            $lines[] = '---';
            $lines[] = '';
            $lines[] = $additionalInfo;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Build metadata section (topics, contact)
     */
    protected function buildMetadataSection(): array
    {
        $lines = [];

        $keywords = $this->configurationService->getKeywords();
        if (!empty($keywords)) {
            $lines[] = '';
            $lines[] = '**Topics:** ' . implode(', ', $keywords);
        }

        $contactEmail = $this->configurationService->getContactEmail();
        if (!empty($contactEmail)) {
            $lines[] = '**Contact:** ' . $contactEmail;
        }

        return $lines;
    }
}

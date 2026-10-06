<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Service;

use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WebNomads\WnAiBridge\Builder\NavigationBuilder;
use WebNomads\WnAiBridge\Repository\PageRepository;

/**
 * Assembles the textual llms.txt document for a site.
 *
 * The output follows the section order llmstxt.org v2 prescribes, and nothing
 * else may come between them: the H1 with the site name (the only required
 * section), a blockquote summary, then any number of heading-free markdown
 * blocks holding the details an agent needs to read the rest, and finally the
 * H2-delimited file lists. Anything written after the first H2 belongs to that
 * list's section — which is why the editor's free-form text sits in the detail
 * block and not, as it once did, at the end of the document.
 *
 * All the heavy lifting (page lookups, navigation traversal, configuration
 * access) is delegated to the injected collaborators.
 *
 * @see https://llmstxt.org/
 */
class LlmsTxtGeneratorService
{
    /**
     * Heading of the file list holding the site's own pages.
     */
    private const NAVIGATION_SECTION = 'Main Page Structure';

    /**
     * Heading v2 keeps as a convention for secondary links — the ones an agent
     * may skip when it needs a shorter context.
     */
    private const OPTIONAL_SECTION = 'Optional';

    /**
     * Heading of the pages hidden in menus but marked for llms.txt, e.g. landing pages.
     */
    public const FURTHER_SECTION = 'Further Pages';

    private readonly ConfigurationService $configurationService;
    private readonly PageRepository $pageRepository;
    private readonly NavigationBuilder $navigationBuilder;

    /**
     * Resolved on first use rather than in the constructor: SiteFinder is a
     * readonly class and cannot be stood in for, so resolveSite() is the seam.
     */
    private ?SiteFinder $siteFinder;

    public function __construct(
        ?ConfigurationService $configurationService = null,
        ?PageRepository $pageRepository = null,
        ?NavigationBuilder $navigationBuilder = null,
        ?SiteFinder $siteFinder = null
    ) {
        $this->configurationService = $configurationService ?? GeneralUtility::makeInstance(ConfigurationService::class);
        $this->pageRepository = $pageRepository ?? GeneralUtility::makeInstance(PageRepository::class);
        $this->navigationBuilder = $navigationBuilder ?? GeneralUtility::makeInstance(NavigationBuilder::class);
        $this->siteFinder = $siteFinder;
    }

    /**
     * The site a page belongs to.
     */
    protected function resolveSite(int $pageId): Site
    {
        $this->siteFinder ??= GeneralUtility::makeInstance(SiteFinder::class);

        return $this->siteFinder->getSiteByPageId($pageId);
    }

    /**
     * Build the complete llms.txt document for the given page context.
     */
    public function generateLlmsTxt(int $currentPageId, int $languageUid = 0): string
    {
        if (!$this->configurationService->isEnabled()) {
            return "# LLMS.TXT generation is disabled for this site\n";
        }

        $site = $this->resolveSite($currentPageId);
        $homePage = $this->pageRepository->findById($site->getRootPageId());

        $lines = [];

        $this->appendHeader($lines, $homePage);
        $this->appendDetails($lines);
        $this->appendNavigation($lines, $site->getRootPageId(), $languageUid);
        $this->appendListedPages($lines, $site->getRootPageId(), $languageUid);
        $this->appendFullDocumentLink($lines);

        return implode("\n", $lines) . "\n";
    }

    /**
     * Append the "# Title" and "> Description" block, preferring the configured
     * overrides over the home page's own metadata.
     *
     * The H1 is the one section the spec requires, so it is written even when a
     * site configured no title and its home page carries none.
     *
     * @param list<string> $lines
     * @param array<string, mixed> $homePage
     */
    private function appendHeader(array &$lines, array $homePage): void
    {
        $title = self::singleLine($this->configurationService->getTitleOverride() ?: ($homePage['title'] ?? ''));
        $lines[] = '# ' . ($title !== '' ? $title : $this->configurationService->getSiteName());

        $description = self::singleLine(
            $this->configurationService->getDescriptionOverride() ?: ($homePage['description'] ?? '')
        );
        if ($description !== '') {
            $lines[] = '';
            $lines[] = '> ' . $description;
        }
    }

    /**
     * Append the heading-free detail block: the topics and contact metadata, the
     * note on how to read the links below, and the editor's own free-form text.
     *
     * Everything here has to stay above the first H2 — past it, a parser reads
     * it as part of a file list.
     *
     * @param list<string> $lines
     */
    private function appendDetails(array &$lines): void
    {
        $keywords = $this->configurationService->getKeywords();
        $contactEmail = $this->configurationService->getContactEmail();

        if ($keywords !== [] || !empty($contactEmail)) {
            $lines[] = '';
            if ($keywords !== []) {
                $lines[] = '**Topics:** ' . implode(', ', $keywords);
            }
            if (!empty($contactEmail)) {
                $lines[] = '**Contact:** ' . $contactEmail;
            }
        }

        $lines[] = '';
        $lines[] = 'The links below point at the Markdown version of each page. Every page of this site has one'
            . ' at its own URL with `' . LinkRelationService::MARKDOWN_SUFFIX . '` appended, and each page links'
            . ' back to it with `rel="alternate" type="' . LinkRelationService::MARKDOWN_MEDIA_TYPE . '"`.';

        $additionalInfo = trim((string)$this->configurationService->getAdditionalInfo());
        if ($additionalInfo !== '') {
            $lines[] = '';
            $lines[] = self::withoutHeadings($additionalInfo);
        }
    }

    /**
     * Append the navigation tree for the requested language as file lists.
     *
     * The editor splits it by putting menu separators into the page tree, each of
     * which becomes an H2 of its own. The default heading is only written when
     * the list does not already open with one — otherwise it would be an empty
     * section standing in front of the editor's first.
     *
     * @param list<string> $lines
     */
    private function appendNavigation(array &$lines, int $rootPageId, int $languageUid): void
    {
        $navigationStructure = $this->navigationBuilder->build(
            $rootPageId,
            $this->configurationService->getMaxDepth(),
            $languageUid
        );

        $navigation = $this->navigationBuilder->formatAsMarkdown($navigationStructure, $languageUid);

        $lines[] = '';

        if (!str_starts_with((string)($navigation[0] ?? ''), '## ')) {
            $lines[] = '## ' . self::NAVIGATION_SECTION;
        }

        foreach ($navigation as $line) {
            $lines[] = $line;
        }
    }

    /**
     * Append the pages hidden in menus that the editor marked for llms.txt, as a
     * file list of their own; the section is left out when there are none.
     *
     * @param list<string> $lines
     */
    private function appendListedPages(array &$lines, int $rootPageId, int $languageUid): void
    {
        $listed = $this->navigationBuilder->formatAsMarkdown($this->navigationBuilder->buildListed($rootPageId, $languageUid), $languageUid);
        if ($listed === []) {
            return;
        }

        $lines[] = '';
        $lines[] = '## ' . self::FURTHER_SECTION;
        foreach ($listed as $line) {
            $lines[] = $line;
        }
    }

    /**
     * Point at the full document, but only where it is actually served — a link
     * to llms-full.txt is worthless while the endpoint is switched off.
     *
     * @param list<string> $lines
     */
    private function appendFullDocumentLink(array &$lines): void
    {
        if (!$this->configurationService->isLlmsFullTxtEnabled()) {
            return;
        }

        $lines[] = '';
        $lines[] = '## ' . self::OPTIONAL_SECTION;
        $lines[] = '- [Full site content](' . $this->configurationService->getSiteUrl()
            . '/' . LinkRelationService::LLMS_FULL_TXT_FILE
            . '): The readable content of every page in one document';
    }

    /**
     * Collapse a value onto one line. A title or description straight out of the
     * database may carry newlines, and a line break inside a blockquote or a
     * list item ends it.
     */
    private static function singleLine(mixed $value): string
    {
        return trim((string)preg_replace('/\s+/', ' ', trim((string)$value)));
    }

    /**
     * Demote any ATX heading in editor text to bold.
     *
     * The detail block sits above the file lists, so a heading inside it would
     * open a section of its own and swallow everything the document still has
     * to say.
     */
    private static function withoutHeadings(string $text): string
    {
        return (string)preg_replace('/^#{1,6}\s+(.*?)\s*$/m', '**$1**', $text);
    }
}

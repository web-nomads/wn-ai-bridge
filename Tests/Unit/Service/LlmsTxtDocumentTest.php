<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use WebNomads\WnAiBridge\Builder\NavigationBuilder;
use WebNomads\WnAiBridge\Repository\PageRepository;
use WebNomads\WnAiBridge\Service\ConfigurationService;
use WebNomads\WnAiBridge\Service\LlmsTxtGeneratorService;

/**
 * The shape of the llms.txt document, as llmstxt.org v2 defines it.
 *
 * The order of the sections is the whole of the format: an H1, a blockquote,
 * heading-free detail, then H2-delimited file lists. A parser reads everything
 * after the first H2 as belonging to that list, so anything the site wants to
 * say about itself has to come before it.
 */
final class LlmsTxtDocumentTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $siteConfiguration = [
        'base' => 'https://example.com/',
        'llmsTxtEnabled' => 1,
        'llmsTxtTitle' => 'Example Site',
        'llmsTxtDescription' => 'A site about examples.',
        'llmsTxtKeywords' => 'examples, documentation',
        'llmsTxtContactEmail' => 'info@example.com',
        'llmsTxtAdditionalInfo' => 'Run by two people in Winterthur.',
        'llmsTxtMaxDepth' => 2,
        'languages' => [
            [
                'languageId' => 0,
                'title' => 'English',
                'navigationTitle' => 'English',
                'base' => '/',
                'locale' => 'en_US.UTF-8',
                'flag' => 'us',
            ],
        ],
    ];

    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);
    }

    #[Test]
    public function theDocumentOpensWithTheHeadingAndNothingBeforeIt(): void
    {
        $lines = explode("\n", $this->subject()->generateLlmsTxt(1));

        self::assertSame('# Example Site', $lines[0], 'The H1 is the first line — no key/value preamble above it.');
        self::assertSame('', $lines[1]);
        self::assertSame('> A site about examples.', $lines[2]);
    }

    #[Test]
    public function aSiteWithoutATitleStillGetsTheOneRequiredSection(): void
    {
        unset($this->siteConfiguration['llmsTxtTitle']);

        $lines = explode("\n", $this->subject()->generateLlmsTxt(1));

        self::assertSame('# Home', $lines[0], 'The home page title stands in.');
    }

    #[Test]
    public function everythingTheSiteSaysAboutItselfComesBeforeTheFirstFileList(): void
    {
        $document = $this->subject()->generateLlmsTxt(1);
        $firstHeading = strpos($document, "\n## ");

        self::assertIsInt($firstHeading);
        $preamble = substr($document, 0, $firstHeading);

        self::assertStringContainsString('**Topics:** examples, documentation', $preamble);
        self::assertStringContainsString('**Contact:** info@example.com', $preamble);
        self::assertStringContainsString('Run by two people in Winterthur.', $preamble);
        self::assertStringNotContainsString(
            'Winterthur',
            substr($document, $firstHeading),
            'Free-form text after a file list would be read as part of it.'
        );
    }

    #[Test]
    public function aHeadingInTheEditorsTextIsDemotedSoItOpensNoSection(): void
    {
        $this->siteConfiguration['llmsTxtAdditionalInfo'] = "## About us\n\nTwo people in Winterthur.";

        $document = $this->subject()->generateLlmsTxt(1);

        self::assertStringContainsString('**About us**', $document);
        self::assertStringNotContainsString('## About us', $document);
    }

    #[Test]
    public function thePreambleSaysWhereTheMarkdownVersionsAre(): void
    {
        $document = $this->subject()->generateLlmsTxt(1);

        self::assertStringContainsString('`.md`', $document);
        self::assertStringContainsString('rel="alternate" type="text/markdown"', $document);
    }

    #[Test]
    public function everyLinkInTheFileListCarriesItsNotes(): void
    {
        $document = $this->subject()->generateLlmsTxt(1);

        self::assertStringContainsString(
            '- [Products](https://example.com/products.md): What we sell.',
            $document,
            'Top level entries are described too, not only the nested ones.'
        );
        self::assertStringContainsString('    - [Chairs](https://example.com/chairs.md): Four legs.', $document);
    }

    #[Test]
    public function theDefaultHeadingStandsAsideWhereTheEditorWroteTheirOwn(): void
    {
        $document = $this->subject($this->sectionedNavigationBuilder())->generateLlmsTxt(1);

        self::assertStringNotContainsString(
            '## Main Page Structure',
            $document,
            'An empty default section in front of the editor\'s first one.'
        );
        self::assertStringContainsString("\n## Services\n", $document);
        self::assertStringContainsString("\n## Legal\n", $document);

        // The detail block still has to end before the first heading.
        $firstHeading = strpos($document, "\n## ");
        self::assertIsInt($firstHeading);
        self::assertStringContainsString('Run by two people in Winterthur.', substr($document, 0, $firstHeading));
    }

    #[Test]
    public function pagesAboveTheFirstSeparatorKeepASectionOfTheirOwn(): void
    {
        $document = $this->subject($this->sectionedNavigationBuilder(withLeadingPage: true))->generateLlmsTxt(1);

        self::assertStringContainsString('## Main Page Structure', $document);
        self::assertStringContainsString('- [Home](https://example.com/home.md)', $document);
        self::assertLessThan(
            strpos($document, '## Services'),
            strpos($document, '## Main Page Structure'),
            'The default section comes first — it holds the pages above the separator.'
        );
    }

    #[Test]
    public function theOptionalSectionOnlyAppearsWhileTheFullDocumentIsServed(): void
    {
        self::assertStringNotContainsString('## Optional', $this->subject()->generateLlmsTxt(1));

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] = ['llmsFullTxt' => 1];

        $document = $this->subject()->generateLlmsTxt(1);
        self::assertStringContainsString('## Optional', $document);
        self::assertStringContainsString('(https://example.com/llms-full.txt)', $document);
    }

    private function subject(?NavigationBuilder $navigationBuilder = null): LlmsTxtGeneratorService
    {
        $site = new Site('test', 1, $this->siteConfiguration);

        return new class (
            $site,
            $this->configurationService($site),
            $this->pageRepository(),
            $navigationBuilder ?? $this->navigationBuilder()
        ) extends LlmsTxtGeneratorService {
            public function __construct(
                private readonly Site $site,
                ConfigurationService $configurationService,
                PageRepository $pageRepository,
                NavigationBuilder $navigationBuilder
            ) {
                parent::__construct($configurationService, $pageRepository, $navigationBuilder, null);
            }

            protected function resolveSite(int $pageId): Site
            {
                return $this->site;
            }
        };
    }

    private function configurationService(Site $site): ConfigurationService
    {
        return new class ($site) extends ConfigurationService {
            public function __construct(private readonly Site $site) {}

            protected function getCurrentSite(): ?Site
            {
                return $this->site;
            }

            public function getCurrentSiteLanguage(): ?SiteLanguage
            {
                return null;
            }
        };
    }

    private function pageRepository(): PageRepository
    {
        return new class () extends PageRepository {
            public function __construct() {}

            public function findById(int $pageId, int $languageUid = 0): array
            {
                return self::homePage();
            }

            /**
             * @return array<string, mixed>
             */
            private static function homePage(): array
            {
                return [
                    'uid' => 1,
                    'pid' => 0,
                    'title' => 'Home',
                    'nav_title' => '',
                    'description' => '',
                    'abstract' => '',
                    'sys_language_uid' => 0,
                ];
            }
        };
    }

    /**
     * A tree an editor split with menu separators, optionally with one page
     * standing above the first of them.
     */
    private function sectionedNavigationBuilder(bool $withLeadingPage = false): NavigationBuilder
    {
        return new class ($withLeadingPage) extends NavigationBuilder {
            public function __construct(private readonly bool $withLeadingPage) {}

            /**
             * @return list<array<string, mixed>>
             */
            public function build(int $rootPageUid, int $maxDepth = 2, int $languageUid = 0): array
            {
                $structure = [];

                if ($this->withLeadingPage) {
                    $structure[] = [
                        'uid' => 5,
                        'title' => 'Home',
                        'description' => '',
                        'url' => 'https://example.com/home.md',
                        'language' => 'English',
                        'pages' => [],
                    ];
                }

                $structure[] = ['uid' => 90, 'title' => 'Services', 'section' => true];
                $structure[] = [
                    'uid' => 2,
                    'title' => 'Consulting',
                    'description' => 'What we advise on.',
                    'url' => 'https://example.com/consulting.md',
                    'language' => 'English',
                    'pages' => [],
                ];
                $structure[] = ['uid' => 91, 'title' => 'Legal', 'section' => true];
                $structure[] = [
                    'uid' => 3,
                    'title' => 'Imprint',
                    'description' => 'Who runs this.',
                    'url' => 'https://example.com/imprint.md',
                    'language' => 'English',
                    'pages' => [],
                ];

                return $structure;
            }
        };
    }

    /**
     * Three pages, one of them nested, all with notes.
     */
    private function navigationBuilder(): NavigationBuilder
    {
        return new class () extends NavigationBuilder {
            public function __construct() {}

            /**
             * @return list<array<string, mixed>>
             */
            public function build(int $rootPageUid, int $maxDepth = 2, int $languageUid = 0): array
            {
                return [
                    [
                        'uid' => 2,
                        'title' => 'Products',
                        'description' => 'What we sell.',
                        'url' => 'https://example.com/products.md',
                        'language' => 'English',
                        'pages' => [
                            [
                                'uid' => 4,
                                'title' => 'Chairs',
                                'description' => 'Four legs.',
                                'url' => 'https://example.com/chairs.md',
                                'language' => 'English',
                                'pages' => [],
                            ],
                        ],
                    ],
                    [
                        'uid' => 3,
                        'title' => 'Contact',
                        'description' => 'Where to find us.',
                        'url' => 'https://example.com/contact.md',
                        'language' => 'English',
                        'pages' => [],
                    ],
                ];
            }
        };
    }
}

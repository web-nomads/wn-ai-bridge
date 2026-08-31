<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use WebNomads\WnAiBridge\Repository\PageRepository;
use WebNomads\WnAiBridge\Service\ConfigurationService;
use WebNomads\WnAiBridge\Service\LlmsFullTxtGeneratorService;
use WebNomads\WnAiBridge\Service\MarkdownConverterService;
use WebNomads\WnAiBridge\Service\PageContentRenderer;
use WebNomads\WnAiBridge\Service\UrlGeneratorService;

/**
 * The shape of the llms-full.txt document.
 *
 * A full document is read as one file, so it has to open with a single H1 and a
 * blockquote summary and then stay a plain outline of sections — the layout the
 * llms.txt validator checks a full document against.
 */
final class LlmsFullTxtDocumentTest extends TestCase
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
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] = ['llmsFullTxt' => 1];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);
    }

    #[Test]
    public function theEndpointStaysOffUntilItIsSwitchedOn(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] = [];

        self::assertFalse($this->configurationService()->isLlmsFullTxtEnabled(), 'Off by default.');
        self::assertStringNotContainsString('Example Site', $this->subject()->generateLlmsFullTxt(1));
    }

    #[Test]
    public function theDocumentOpensWithOneTitleAndOneSummary(): void
    {
        $lines = explode("\n", $this->subject()->generateLlmsFullTxt(1));

        self::assertSame('# Example Site', $lines[0]);
        self::assertSame('', $lines[1]);
        self::assertSame('> A site about examples.', $lines[2]);
    }

    #[Test]
    public function everyPageBecomesASectionThatNestsTheWayTheTreeDoes(): void
    {
        $document = $this->subject()->generateLlmsFullTxt(1);

        self::assertStringContainsString("\n## Home\n", $document, 'The home page is a section of its own.');
        self::assertStringContainsString("\n## Products\n", $document, 'A child of the root is an H2.');
        self::assertStringContainsString("\n### Chairs\n", $document, 'Its own child goes one level deeper.');
        self::assertStringContainsString('Source: https://example.com/page-3', $document);
    }

    #[Test]
    public function aPagesOwnHeadingsAreNestedBelowItsSection(): void
    {
        $document = $this->subject()->generateLlmsFullTxt(1);

        // The fake page content starts at h2, the section heading of a child of
        // the root is an h2 as well — so the content has to land on h3.
        self::assertStringContainsString("\n### Content heading\n", $document);
        self::assertStringNotContainsString("\n## Content heading\n", $document);
    }

    #[Test]
    public function thereIsExactlyOneH1(): void
    {
        $h1 = preg_grep('/^# /', explode("\n", $this->subject()->generateLlmsFullTxt(1)));

        self::assertCount(1, (array)$h1);
    }

    #[Test]
    public function theConfiguredMetadataBecomesTheFirstSection(): void
    {
        $document = $this->subject()->generateLlmsFullTxt(1);

        self::assertStringContainsString("\n## About This Site\n", $document);
        self::assertStringContainsString('**Topics:** examples, documentation', $document);
        self::assertStringContainsString('**Contact:** info@example.com', $document);
    }

    #[Test]
    public function aSiteThatDisabledLlmsTxtGetsNoDocument(): void
    {
        $this->siteConfiguration['llmsTxtEnabled'] = 0;

        self::assertStringContainsString('disabled', $this->subject()->generateLlmsFullTxt(1));
        self::assertStringNotContainsString('## Home', $this->subject()->generateLlmsFullTxt(1));
    }

    private function subject(): LlmsFullTxtGeneratorService
    {
        $site = $this->site();

        return new class (
            $this->configurationService(),
            $this->pageRepository(),
            $this->pageContentRenderer(),
            $this->markdownConverter(),
            $this->urlGenerator(),
            $site
        ) extends LlmsFullTxtGeneratorService {
            public function __construct(
                ConfigurationService $configurationService,
                PageRepository $pageRepository,
                PageContentRenderer $pageContentRenderer,
                MarkdownConverterService $markdownConverter,
                UrlGeneratorService $urlGenerator,
                private readonly Site $site
            ) {
                parent::__construct(
                    $configurationService,
                    $pageRepository,
                    $pageContentRenderer,
                    $markdownConverter,
                    $urlGenerator
                );
            }

            protected function resolveSite(int $pageId): Site
            {
                return $this->site;
            }
        };
    }

    private function site(): Site
    {
        return new Site('test', 1, $this->siteConfiguration);
    }

    private function configurationService(): ConfigurationService
    {
        $site = $this->site();

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

    /**
     * A root page with two children, one of which has a child of its own.
     */
    private function pageRepository(): PageRepository
    {
        return new class () extends PageRepository {
            public function __construct() {}

            public function findById(int $pageId, int $languageUid = 0): array
            {
                return self::page(1, 0, 'Home', 'The home page.');
            }

            public function findNavigationByParentWithFallback(int $parentUid, SiteLanguage $siteLanguage): array
            {
                return match ($parentUid) {
                    1 => [self::page(2, 1, 'Products', ''), self::page(3, 1, 'Contact', '')],
                    2 => [self::page(4, 2, 'Chairs', '')],
                    default => [],
                };
            }

            /**
             * @return array<string, mixed>
             */
            private static function page(int $uid, int $pid, string $title, string $description): array
            {
                return [
                    'uid' => $uid,
                    'pid' => $pid,
                    'title' => $title,
                    'nav_title' => '',
                    'description' => $description,
                    'abstract' => '',
                    'sys_language_uid' => 0,
                ];
            }
        };
    }

    private function pageContentRenderer(): PageContentRenderer
    {
        return new class () extends PageContentRenderer {
            public function __construct() {}

            public function render(int $pageId, int $languageUid = 0, bool $withHeader = true): string
            {
                return '<h2>Content heading</h2><p>Body text.</p>';
            }
        };
    }

    private function markdownConverter(): MarkdownConverterService
    {
        return new class () extends MarkdownConverterService {
            public function __construct() {}

            public function convertHtmlToMarkdown(string $html): string
            {
                return "## Content heading\n\nBody text.";
            }
        };
    }

    private function urlGenerator(): UrlGeneratorService
    {
        return new class () extends UrlGeneratorService {
            public function __construct() {}

            public function generateHtmlUrl(array $page, ?bool $onePager = null): string
            {
                return 'https://example.com/page-' . $page['uid'];
            }
        };
    }
}

<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The llms.txt endpoints as they are actually served, through the whole
 * frontend stack.
 *
 * The unit tests cover what the document looks like; this covers the parts no
 * unit test can reach — that the middleware is wired into the frontend stack
 * and sets its header, that the TypoScript USER object in the page head is
 * called, and that the route enhancer resolves the URLs the link relations hand
 * out. Those are the three places where TYPO3 13.4 and 14.x could have differed.
 */
final class LlmsTxtEndpointTest extends FunctionalTestCase
{
    /**
     * EXT:seo owns the "seo_title" column the page lookup selects. It is a hard
     * requirement of this extension, so it is always present in a real
     * installation — but a test instance only gets what is named here.
     */
    protected array $coreExtensionsToLoad = ['seo'];

    protected array $testExtensionsToLoad = ['b13/aim', 'web-nomads/wn-ai-bridge'];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'wn_ai_bridge' => [
                'llmsFullTxt' => '1',
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/Fixtures/Database/pages.csv');
        $this->writeSiteConfiguration();

        $this->setUpFrontendRootPage(1, [
            'EXT:wn_ai_bridge/Tests/Functional/Fixtures/TypoScript/page.typoscript',
            'EXT:wn_ai_bridge/Configuration/TypoScript/setup.typoscript',
            'EXT:wn_ai_bridge/Configuration/TypoScript/markdown.typoscript',
            'EXT:wn_ai_bridge/Configuration/TypoScript/llmsfull.typoscript',
        ]);
    }

    #[Test]
    public function theDocumentIsServedInTheOrderTheFormatPrescribes(): void
    {
        $body = $this->body('http://localhost/llms.txt');
        $lines = explode("\n", $body);

        self::assertSame('# Example Site', $lines[0]);
        self::assertSame('', $lines[1]);
        self::assertSame('> A site about examples.', $lines[2]);

        $firstHeading = strpos($body, "\n## ");
        self::assertIsInt($firstHeading);
        self::assertStringContainsString('**Contact:** info@example.com', substr($body, 0, $firstHeading));
        self::assertStringContainsString('Run by two people in Winterthur.', substr($body, 0, $firstHeading));

        self::assertStringNotContainsString('llmstxt: 1.0', $body);
    }

    #[Test]
    public function theFileListLinksToTheMarkdownVersions(): void
    {
        $body = $this->body('http://localhost/llms.txt');

        self::assertStringContainsString('- [About](http://localhost/about.md): What we do.', $body);
        self::assertStringContainsString('- [Contact](http://localhost/contact.md): Where to find us.', $body);
    }

    /**
     * The page tree carries menu separators, and each of them opens a section of
     * the link list. Proven here rather than only in the unit tests, because it
     * depends on the separator surviving the page lookup — which drops folders,
     * shortcuts and, everywhere else, separators too.
     */
    #[Test]
    public function menuSeparatorsSplitTheListIntoSections(): void
    {
        $body = $this->body('http://localhost/llms.txt');

        self::assertStringContainsString(
            "## Services\n- [About](http://localhost/about.md): What we do.",
            $body
        );
        self::assertStringContainsString(
            "## Legal\n- [Contact](http://localhost/contact.md): Where to find us.",
            $body
        );

        self::assertStringNotContainsString(
            '## Main Page Structure',
            $body,
            'Nothing stands above the first separator, so the default section would be empty.'
        );
        self::assertStringNotContainsString('## ---', $body, 'A decorative separator is not a heading.');
        self::assertStringNotContainsString('services.md', $body, 'A separator is not a page and has no link.');
    }

    /**
     * "Legal" is a separator marked "hide in menu", and it still opens a section.
     *
     * On a site whose menu renders separators, that setting is the only way to
     * structure llms.txt without also putting a divider into the menu. It hides
     * the divider; it does not say the group stopped existing. A page marked the
     * same way stays out, as it always has.
     */
    #[Test]
    public function aSeparatorHiddenFromTheMenuStillOpensASection(): void
    {
        $body = $this->body('http://localhost/llms.txt');

        self::assertStringContainsString('## Legal', $body);
        self::assertStringNotContainsString('internal.md', $body, 'A page hidden from the menu stays out.');
    }

    #[Test]
    public function aPageHeadCarriesBothLinkRelations(): void
    {
        $html = $this->body('http://localhost/about');

        self::assertStringContainsString(
            '<link rel="alternate" type="text/markdown" href="http://localhost/about.md">',
            $html
        );
        self::assertStringContainsString('<link rel="describedby" href="http://localhost/llms.txt">', $html);
        self::assertStringNotContainsString('type="text/plain" href="/llms.txt"', $html);
    }

    #[Test]
    public function theResponseHeaderCarriesBothLinkRelations(): void
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest('http://localhost/about'));

        self::assertSame(
            '<http://localhost/about.md>; rel="alternate"; type="text/markdown", '
                . '<http://localhost/llms.txt>; rel="describedby"',
            $response->getHeaderLine('Link')
        );
    }

    #[Test]
    public function aMarkdownDocumentIsDescribedByTheLlmsTxtAndOffersNoAlternateOfItsOwn(): void
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest('http://localhost/about.md'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            '<http://localhost/llms.txt>; rel="describedby"',
            $response->getHeaderLine('Link')
        );
    }

    #[Test]
    public function theLlmsTxtDoesNotDescribeItself(): void
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest('http://localhost/llms.txt'));

        self::assertSame('', $response->getHeaderLine('Link'));
    }

    /**
     * The home page has no file name to put the suffix on, so the spec asks for
     * the index one — and that is the URL the head hands out, so it has to
     * resolve.
     */
    #[Test]
    public function theHomePageMarkdownIsServedUnderTheIndexFileName(): void
    {
        $html = $this->body('http://localhost/');

        self::assertStringContainsString(
            '<link rel="alternate" type="text/markdown" href="http://localhost/index.md">',
            $html
        );
        self::assertStringNotContainsString('href="http://localhost.md"', $html);

        $response = $this->executeFrontendSubRequest(new InternalRequest('http://localhost/index.md'));
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * A single-language site with the shipped endpoint mapping and the llms.txt
     * metadata an editor would maintain on the "AI Bridge" tab.
     */
    private function writeSiteConfiguration(): void
    {
        $configuration = [
            'rootPageId' => 1,
            'base' => 'http://localhost/',
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
            'llmsTxtEnabled' => true,
            'llmsTxtTitle' => 'Example Site',
            'llmsTxtDescription' => 'A site about examples.',
            'llmsTxtKeywords' => 'examples, documentation',
            'llmsTxtContactEmail' => 'info@example.com',
            'llmsTxtAdditionalInfo' => 'Run by two people in Winterthur.',
            'llmsTxtMaxDepth' => 2,
            'routeEnhancers' => [
                'PageTypeSuffix' => [
                    'type' => 'PageType',
                    'default' => '',
                    'index' => 'index',
                    'map' => [
                        'llms.txt' => 1699,
                        '.well-known/llms.txt' => 1699,
                        'llms-full.txt' => 1702,
                        '.well-known/llms-full.txt' => 1702,
                        '.md' => 1701,
                    ],
                ],
            ],
        ];

        $path = Environment::getConfigPath() . '/sites/test';
        GeneralUtility::mkdir_deep($path);
        GeneralUtility::writeFile($path . '/config.yaml', Yaml::dump($configuration, 99, 2), true);
    }

    private function body(string $url): string
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest($url));

        self::assertSame(200, $response->getStatusCode(), $url . ' did not answer with 200.');

        return (string)$response->getBody();
    }
}

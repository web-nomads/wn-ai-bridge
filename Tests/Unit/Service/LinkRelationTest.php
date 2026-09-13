<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use WebNomads\WnAiBridge\Service\LinkRelationService;

/**
 * The link relations llms.txt v2 asks a site to publish.
 *
 * They are the answer to the question v1 left open: given a page, where is its
 * Markdown version, and which llms.txt covers it — without guessing.
 */
final class LinkRelationTest extends TestCase
{
    private LinkRelationService $subject;

    protected function setUp(): void
    {
        $this->subject = new LinkRelationService();
    }

    #[Test]
    #[DataProvider('markdownUrls')]
    public function aPageKnowsWhereItsMarkdownVersionIs(string $url, string $expected): void
    {
        self::assertSame($expected, $this->subject->markdownUrl($this->request($url)));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function markdownUrls(): array
    {
        return [
            'page' => ['https://example.com/about', 'https://example.com/about.md'],
            // A URL without a file name gets the index one, as the spec requires
            // — appending alone would have produced "https://example.com.md".
            'home page' => ['https://example.com/', 'https://example.com/index.md'],
            'language home page' => ['https://example.com/en/', 'https://example.com/en/index.md'],
            'nested page' => ['https://example.com/products/chairs', 'https://example.com/products/chairs.md'],
            'entry point' => ['https://example.com/camino/', 'https://example.com/camino/index.md'],
        ];
    }

    #[Test]
    #[DataProvider('generatedDocuments')]
    public function aGeneratedDocumentIsNotOfferedAMarkdownVersionOfItsOwn(string $url): void
    {
        self::assertSame('', $this->subject->markdownUrl($this->request($url)));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function generatedDocuments(): array
    {
        return [
            'markdown' => ['https://example.com/about.md'],
            'llms.txt' => ['https://example.com/llms.txt'],
            'well-known llms.txt' => ['https://example.com/.well-known/llms.txt'],
            'llms-full.txt' => ['https://example.com/llms-full.txt'],
        ];
    }

    #[Test]
    public function aPageWhoseArgumentsIdentifyItGetsNoMarkdownLink(): void
    {
        // The path alone resolves to the list view, so a link built from it
        // would point at a different page than the one being rendered.
        $request = $this->request('https://example.com/news?tx_news%5Bnews%5D=5&cHash=abc123');

        self::assertSame('', $this->subject->markdownUrl($request));
    }

    #[Test]
    public function thePageIsDescribedByTheLlmsTxtOfItsOwnLanguage(): void
    {
        self::assertSame(
            'https://example.com/en/llms.txt',
            $this->subject->llmsTxtUrl($this->request('https://example.com/en/about', '/en/'))
        );
    }

    #[Test]
    public function aSiteWithoutALanguagePrefixIsDescribedByTheRootFile(): void
    {
        self::assertSame(
            'https://example.com/llms.txt',
            $this->subject->llmsTxtUrl($this->request('https://example.com/about'))
        );
    }

    #[Test]
    public function theHeaderCarriesBothRelationsInTheFormTheSpecShows(): void
    {
        self::assertSame(
            '<https://example.com/about.md>; rel="alternate"; type="text/markdown", '
                . '<https://example.com/llms.txt>; rel="describedby"',
            $this->subject->headerValue($this->request('https://example.com/about'))
        );
    }

    #[Test]
    public function aMarkdownDocumentStillSaysWhichLlmsTxtCoversIt(): void
    {
        self::assertSame(
            '<https://example.com/llms.txt>; rel="describedby"',
            $this->subject->headerValue($this->request('https://example.com/about.md'))
        );
    }

    #[Test]
    public function theLlmsTxtDoesNotDescribeItself(): void
    {
        self::assertSame('', $this->subject->headerValue($this->request('https://example.com/llms.txt')));
    }

    #[Test]
    public function theHeadCarriesTheSamePairAsLinkElements(): void
    {
        $tags = $this->subject->linkTags($this->request('https://example.com/about'));

        self::assertSame(
            '<link rel="alternate" type="text/markdown" href="https://example.com/about.md">' . "\n"
                . '<link rel="describedby" href="https://example.com/llms.txt">',
            $tags
        );
    }

    private function request(string $url, string $languageBase = '/'): ServerRequest
    {
        $site = new Site('test', 1, [
            'base' => 'https://example.com/',
            'languages' => [
                [
                    'languageId' => 0,
                    'title' => 'English',
                    'navigationTitle' => 'English',
                    'base' => $languageBase,
                    'locale' => 'en_US.UTF-8',
                    'flag' => 'us',
                ],
            ],
        ]);

        return (new ServerRequest($url))
            ->withAttribute('site', $site)
            ->withAttribute('language', $site->getDefaultLanguage());
    }
}

<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Service\ConfigurationService;
use WebNomads\WnAiBridge\Service\HtmlCleanerService;
use WebNomads\WnAiBridge\Service\MarkdownConverterService;

class MarkdownConverterServiceTest extends TestCase
{
    private HtmlCleanerService $htmlCleanerService;

    /** @var ConfigurationService&MockObject */
    private ConfigurationService $configurationService;

    private MarkdownConverterService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->htmlCleanerService = new HtmlCleanerService();
        $this->configurationService = $this->createMock(ConfigurationService::class);

        $this->subject = new MarkdownConverterService(
            $this->htmlCleanerService,
            $this->configurationService
        );
    }

    // --- convertHtmlToMarkdown ---

    #[Test]
    public function convertHtmlToMarkdownReturnsEmptyStringForEmptyInput(): void
    {
        $result = $this->subject->convertHtmlToMarkdown('');

        self::assertSame('', $result);
    }

    #[Test]
    public function convertHtmlToMarkdownReturnsEmptyStringForWhitespaceOnlyInput(): void
    {
        $this->configurationService
            ->method('getSiteUrl')
            ->willReturn('https://example.com');

        // The method checks `empty($html)`, whitespace is not empty → passes through
        // but may produce empty markdown after conversion
        $result = $this->subject->convertHtmlToMarkdown('   ');

        // Whitespace-only input should produce empty or whitespace-only markdown
        self::assertSame('', trim($result));
    }

    #[Test]
    public function convertHtmlToMarkdownConvertsBasicHtml(): void
    {
        $this->configurationService
            ->method('getSiteUrl')
            ->willReturn('https://example.com');

        $html = '<h1>Title</h1><p>Some content here.</p>';
        $result = $this->subject->convertHtmlToMarkdown($html);

        self::assertStringContainsString('Title', $result);
        self::assertStringContainsString('Some content here', $result);
    }

    #[Test]
    public function convertHtmlToMarkdownMakesInternalLinksAbsoluteWithMdSuffix(): void
    {
        $this->configurationService
            ->method('getSiteUrl')
            ->willReturn('https://example.com');

        $html = '<p><a href="/about">About us</a></p>';
        $result = $this->subject->convertHtmlToMarkdown($html);

        self::assertStringContainsString('https://example.com/about.md', $result);
        self::assertStringContainsString('About us', $result);
    }

    #[Test]
    public function convertHtmlToMarkdownPreservesExternalLinks(): void
    {
        $this->configurationService
            ->method('getSiteUrl')
            ->willReturn('https://example.com');

        $html = '<p><a href="https://external.com/page">External link</a></p>';
        $result = $this->subject->convertHtmlToMarkdown($html);

        self::assertStringContainsString('https://external.com/page', $result);
        self::assertStringNotContainsString('https://external.com/page.md', $result);
    }

    #[Test]
    public function convertHtmlToMarkdownDoesNotAddMdExtensionToLinksWithExtension(): void
    {
        $this->configurationService
            ->method('getSiteUrl')
            ->willReturn('https://example.com');

        $html = '<p><a href="https://example.com/document.pdf">Download PDF</a></p>';
        $result = $this->subject->convertHtmlToMarkdown($html);

        self::assertStringContainsString('document.pdf', $result);
        self::assertStringNotContainsString('document.pdf.md', $result);
    }

    // --- processMarkdownUrl (via reflection) ---

    #[Test]
    public function processMarkdownUrlMakesRelativeUrlAbsolute(): void
    {
        $result = $this->invokeProcessMarkdownUrl('/about', 'https://example.com');

        self::assertSame('https://example.com/about.md', $result);
    }

    #[Test]
    public function processMarkdownUrlAppendsMdExtensionToInternalUrl(): void
    {
        $result = $this->invokeProcessMarkdownUrl('https://example.com/products', 'https://example.com');

        self::assertSame('https://example.com/products.md', $result);
    }

    #[Test]
    public function processMarkdownUrlDoesNotModifyExternalUrls(): void
    {
        $result = $this->invokeProcessMarkdownUrl('https://other.com/page', 'https://example.com');

        self::assertSame('https://other.com/page', $result);
    }

    #[Test]
    public function processMarkdownUrlDoesNotModifyUrlsWithExistingExtension(): void
    {
        $result = $this->invokeProcessMarkdownUrl('https://example.com/file.pdf', 'https://example.com');

        self::assertSame('https://example.com/file.pdf', $result);
    }

    #[Test]
    public function processMarkdownUrlDoesNotAppendMdTwice(): void
    {
        $result = $this->invokeProcessMarkdownUrl('https://example.com/page.md', 'https://example.com');

        self::assertSame('https://example.com/page.md', $result);
    }

    #[Test]
    public function processMarkdownUrlPreservesQueryString(): void
    {
        $result = $this->invokeProcessMarkdownUrl('https://example.com/search?q=typo3', 'https://example.com');

        self::assertSame('https://example.com/search.md?q=typo3', $result);
    }

    #[Test]
    public function processMarkdownUrlPreservesFragment(): void
    {
        $result = $this->invokeProcessMarkdownUrl('https://example.com/page#section', 'https://example.com');

        self::assertSame('https://example.com/page.md#section', $result);
    }

    #[Test]
    public function processMarkdownUrlPreservesQueryStringAndFragment(): void
    {
        $result = $this->invokeProcessMarkdownUrl('https://example.com/page?q=test#anchor', 'https://example.com');

        self::assertSame('https://example.com/page.md?q=test#anchor', $result);
    }

    #[Test]
    public function processMarkdownUrlPreservesPort(): void
    {
        $result = $this->invokeProcessMarkdownUrl('https://example.com:8080/page', 'https://example.com:8080');

        self::assertSame('https://example.com:8080/page.md', $result);
    }

    #[Test]
    public function processMarkdownUrlDoesNotModifyRootPath(): void
    {
        $result = $this->invokeProcessMarkdownUrl('/', 'https://example.com');

        // Root path "/" becomes "https://example.com/" — no .md appended
        self::assertSame('https://example.com/', $result);
    }

    #[Test]
    public function processMarkdownUrlRemovesTrailingSlashBeforeAppendingMd(): void
    {
        $result = $this->invokeProcessMarkdownUrl('https://example.com/products/', 'https://example.com');

        self::assertSame('https://example.com/products.md', $result);
    }

    #[Test]
    public function processMarkdownUrlHandlesRelativeUrlWithQueryString(): void
    {
        $result = $this->invokeProcessMarkdownUrl('/search?q=foo', 'https://example.com');

        self::assertSame('https://example.com/search.md?q=foo', $result);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function internalUrlDataProvider(): array
    {
        return [
            'simple path' => ['/about', 'https://example.com', 'https://example.com/about.md'],
            'deep path' => ['/products/detail', 'https://example.com', 'https://example.com/products/detail.md'],
            'absolute internal' => ['https://example.com/contact', 'https://example.com', 'https://example.com/contact.md'],
        ];
    }

    #[Test]
    #[DataProvider('internalUrlDataProvider')]
    public function processMarkdownUrlConvertsInternalUrlsCorrectly(
        string $url,
        string $siteUrl,
        string $expectedResult
    ): void {
        $result = $this->invokeProcessMarkdownUrl($url, $siteUrl);

        self::assertSame($expectedResult, $result);
    }

    private function invokeProcessMarkdownUrl(string $url, string $siteUrl): string
    {
        $method = new \ReflectionMethod($this->subject, 'processMarkdownUrl');
        return $method->invoke($this->subject, $url, $siteUrl);
    }
}

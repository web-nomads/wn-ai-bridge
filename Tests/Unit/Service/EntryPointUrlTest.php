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

/**
 * Sites served from an entry point ("base: /camino/").
 *
 * The router returns a path whenever the site base carries no host, and that
 * path already contains the entry point. Prefixing the site URL then repeats it
 * and produces "/camino/camino/faqs.md" — links that go nowhere.
 */
final class EntryPointUrlTest extends TestCase
{
    private const SITE_URL = 'https://example.com/camino';

    private const ORIGIN = 'https://example.com';

    /** @var ConfigurationService&MockObject */
    private ConfigurationService $configurationService;

    private MarkdownConverterService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurationService = $this->createMock(ConfigurationService::class);
        $this->configurationService->method('getSiteUrl')->willReturn(self::SITE_URL);
        $this->configurationService->method('getSiteOrigin')->willReturn(self::ORIGIN);

        $this->subject = new MarkdownConverterService(
            new HtmlCleanerService(),
            $this->configurationService
        );
    }

    #[Test]
    public function aRootRelativeLinkDoesNotRepeatTheEntryPoint(): void
    {
        $markdown = $this->subject->convertHtmlToMarkdown('<p><a href="/camino/faqs">FAQs</a></p>');

        self::assertStringContainsString('https://example.com/camino/faqs.md', $markdown);
        self::assertStringNotContainsString('/camino/camino/', $markdown);
    }

    #[Test]
    public function anAbsoluteInternalLinkIsLeftAlone(): void
    {
        $markdown = $this->subject->convertHtmlToMarkdown(
            '<p><a href="https://example.com/camino/faqs">FAQs</a></p>'
        );

        self::assertStringContainsString('https://example.com/camino/faqs.md', $markdown);
        self::assertStringNotContainsString('/camino/camino/', $markdown);
    }

    #[Test]
    public function aLinkOutsideTheEntryPointGetsNoMarkdownSuffix(): void
    {
        // Same host, different site — internal to the server, not to this site.
        $markdown = $this->subject->convertHtmlToMarkdown('<p><a href="/shop/cart">Cart</a></p>');

        self::assertStringContainsString('https://example.com/shop/cart', $markdown);
        self::assertStringNotContainsString('/shop/cart.md', $markdown);
    }

    #[Test]
    public function anExternalLinkIsUntouched(): void
    {
        $markdown = $this->subject->convertHtmlToMarkdown('<p><a href="https://typo3.org/page">TYPO3</a></p>');

        self::assertStringContainsString('https://typo3.org/page', $markdown);
        self::assertStringNotContainsString('typo3.org/page.md', $markdown);
    }

    #[Test]
    #[DataProvider('preservedSuffixes')]
    public function aLinkThatAlreadyHasAnExtensionKeepsIt(string $href): void
    {
        $markdown = $this->subject->convertHtmlToMarkdown('<p><a href="' . $href . '">File</a></p>');

        self::assertStringContainsString(self::ORIGIN . $href, $markdown);
        self::assertStringNotContainsString($href . '.md', $markdown);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function preservedSuffixes(): array
    {
        return [
            'pdf' => ['/camino/handbook.pdf'],
            'already markdown' => ['/camino/faqs.md'],
        ];
    }

    #[Test]
    public function aSiteWithoutAnEntryPointStillWorks(): void
    {
        $configurationService = $this->createMock(ConfigurationService::class);
        $configurationService->method('getSiteUrl')->willReturn('https://example.com');
        $configurationService->method('getSiteOrigin')->willReturn('https://example.com');

        $subject = new MarkdownConverterService(new HtmlCleanerService(), $configurationService);
        $markdown = $subject->convertHtmlToMarkdown('<p><a href="/faqs">FAQs</a></p>');

        self::assertStringContainsString('https://example.com/faqs.md', $markdown);
    }
}

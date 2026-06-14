<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Service\HtmlCleanerService;

class HtmlCleanerServiceTest extends TestCase
{
    private HtmlCleanerService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new HtmlCleanerService();
    }

    #[Test]
    public function cleanTypo3HtmlReturnsEmptyStringUnchanged(): void
    {
        self::assertSame('', $this->subject->cleanTypo3Html(''));
    }

    #[Test]
    public function cleanTypo3HtmlRemovesScriptTagsWithContent(): void
    {
        $html = '<p>Text</p><script>alert("xss");</script><p>More</p>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringNotContainsString('<script>', $result);
        self::assertStringNotContainsString('alert', $result);
        self::assertStringContainsString('Text', $result);
    }

    #[Test]
    public function cleanTypo3HtmlRemovesMultilineScriptTags(): void
    {
        $html = "<script type=\"text/javascript\">\nfunction test() {\n  return true;\n}\n</script><p>Content</p>";
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringNotContainsString('function test', $result);
        self::assertStringContainsString('Content', $result);
    }

    #[Test]
    public function cleanTypo3HtmlRemovesStyleTagsWithContent(): void
    {
        $html = '<style>.hidden { display: none; }</style><p>Content</p>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringNotContainsString('<style>', $result);
        self::assertStringNotContainsString('display: none', $result);
        self::assertStringContainsString('Content', $result);
    }

    #[Test]
    public function cleanTypo3HtmlRemovesIframeTagsWithContent(): void
    {
        $html = '<p>Before</p><iframe src="video.html">Fallback text</iframe><p>After</p>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringNotContainsString('<iframe', $result);
        self::assertStringNotContainsString('Fallback text', $result);
        self::assertStringContainsString('Before', $result);
        self::assertStringContainsString('After', $result);
    }

    #[Test]
    public function cleanTypo3HtmlRemovesVideoTagsWithContent(): void
    {
        $html = '<video controls><source src="video.mp4" type="video/mp4">Your browser does not support video.</video>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringNotContainsString('<video', $result);
        self::assertStringNotContainsString('video.mp4', $result);
    }

    #[Test]
    public function cleanTypo3HtmlRemovesAudioTagsWithContent(): void
    {
        $html = '<audio controls><source src="audio.mp3">No audio support.</audio>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringNotContainsString('<audio', $result);
        self::assertStringNotContainsString('audio.mp3', $result);
    }

    #[Test]
    public function cleanTypo3HtmlRemovesPictureTagsWithContent(): void
    {
        $html = '<picture><source srcset="img.webp" type="image/webp"><img src="img.jpg" alt="Alt"></picture>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringNotContainsString('<picture>', $result);
        self::assertStringNotContainsString('img.webp', $result);
    }

    #[Test]
    public function cleanTypo3HtmlRemovesNoscriptTagsWithContent(): void
    {
        $html = '<noscript>Please enable JavaScript.</noscript><p>Content</p>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringNotContainsString('<noscript>', $result);
        self::assertStringNotContainsString('Please enable JavaScript', $result);
    }

    #[Test]
    public function cleanTypo3HtmlRemovesEmptyAnchorTags(): void
    {
        $html = '<a href="#top">   </a><p>Content</p>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringNotContainsString('<a href="#top">', $result);
        self::assertStringContainsString('Content', $result);
    }

    #[Test]
    public function cleanTypo3HtmlRemovesEmptyDivTags(): void
    {
        $html = '<div>   </div><p>Real content</p>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringNotContainsString('<div>   </div>', $result);
        self::assertStringContainsString('Real content', $result);
    }

    #[Test]
    public function cleanTypo3HtmlRemovesEmptySpanTags(): void
    {
        $html = '<p>Text<span class="empty">  </span>more text</p>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringNotContainsString('<span class="empty">', $result);
    }

    #[Test]
    public function cleanTypo3HtmlRemovesEmptyParagraphTags(): void
    {
        $html = '<p>Real content</p><p>   </p><p>More content</p>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringNotContainsString('<p>   </p>', $result);
        self::assertStringContainsString('Real content', $result);
        self::assertStringContainsString('More content', $result);
    }

    #[Test]
    public function cleanTypo3HtmlRemovesNbspOnlyParagraphs(): void
    {
        $html = '<p>Content</p><p>&nbsp;</p><p>More</p>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringNotContainsString('<p>&nbsp;</p>', $result);
        self::assertStringContainsString('Content', $result);
    }

    #[Test]
    public function cleanTypo3HtmlRemovesEmptySectionTags(): void
    {
        $html = '<section>   </section><p>Content</p>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringNotContainsString('<section>   </section>', $result);
    }

    #[Test]
    public function cleanTypo3HtmlRemovesContainerClassDivOpeners(): void
    {
        $html = '<div class="container"><p>Content</p></div>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringNotContainsString('<div class="container">', $result);
        self::assertStringContainsString('<p>Content</p>', $result);
    }

    #[Test]
    public function cleanTypo3HtmlRemovesRowClassDivOpeners(): void
    {
        $html = '<div class="row"><p>Content</p></div>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringNotContainsString('<div class="row">', $result);
        self::assertStringContainsString('Content', $result);
    }

    #[Test]
    public function cleanTypo3HtmlRemovesColClassDivOpeners(): void
    {
        $html = '<div class="col-md-6"><p>Column content</p></div>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringNotContainsString('<div class="col-md-6">', $result);
        self::assertStringContainsString('Column content', $result);
    }

    #[Test]
    public function cleanTypo3HtmlRemovesGridClassDivOpeners(): void
    {
        $html = '<div class="grid-container"><p>Grid content</p></div>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringNotContainsString('<div class="grid-container">', $result);
        self::assertStringContainsString('Grid content', $result);
    }

    #[Test]
    public function cleanTypo3HtmlRemovesLayoutClassDivOpeners(): void
    {
        $html = '<div class="page-layout"><p>Main content</p></div>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringNotContainsString('<div class="page-layout">', $result);
    }

    #[Test]
    public function cleanTypo3HtmlRemovesWrapperClassDivOpeners(): void
    {
        $html = '<div class="content-wrapper"><p>Wrapped content</p></div>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringNotContainsString('<div class="content-wrapper">', $result);
    }

    #[Test]
    public function cleanTypo3HtmlRemovesAllClosingDivTags(): void
    {
        $html = '<div class="content"><p>Text</p></div><div class="other"><p>More</p></div>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringNotContainsString('</div>', $result);
        self::assertStringContainsString('Text', $result);
        self::assertStringContainsString('More', $result);
    }

    #[Test]
    public function cleanTypo3HtmlNormalizesMultipleWhitespacesToSingleSpace(): void
    {
        $html = '<p>Word1   Word2	Word3</p>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertMatchesRegularExpression('/<p>Word1 Word2 Word3<\/p>/i', $result);
    }

    #[Test]
    public function cleanTypo3HtmlPreservesHeadings(): void
    {
        $html = '<h1>Page Title</h1><h2>Section Title</h2><h3>Subsection</h3>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringContainsString('<h1>Page Title</h1>', $result);
        self::assertStringContainsString('<h2>Section Title</h2>', $result);
        self::assertStringContainsString('<h3>Subsection</h3>', $result);
    }

    #[Test]
    public function cleanTypo3HtmlPreservesLinksWithContent(): void
    {
        $html = '<p><a href="https://example.com">Link text</a></p>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringContainsString('href="https://example.com"', $result);
        self::assertStringContainsString('Link text', $result);
    }

    #[Test]
    public function cleanTypo3HtmlPreservesParagraphsWithContent(): void
    {
        $html = '<p>First paragraph</p><p>Second paragraph</p>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringContainsString('<p>First paragraph</p>', $result);
        self::assertStringContainsString('<p>Second paragraph</p>', $result);
    }

    #[Test]
    public function cleanTypo3HtmlPreservesUnorderedLists(): void
    {
        $html = '<ul><li>Item 1</li><li>Item 2</li></ul>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringContainsString('<ul>', $result);
        self::assertStringContainsString('<li>Item 1</li>', $result);
        self::assertStringContainsString('<li>Item 2</li>', $result);
    }

    #[Test]
    public function cleanTypo3HtmlPreservesOrderedLists(): void
    {
        $html = '<ol><li>First</li><li>Second</li></ol>';
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringContainsString('<ol>', $result);
        self::assertStringContainsString('<li>First</li>', $result);
    }

    #[Test]
    public function cleanTypo3HtmlHandlesComplexNestedStructure(): void
    {
        $html = <<<HTML
            <div class="container">
                <div class="row">
                    <div class="col-md-8">
                        <h1>Title</h1>
                        <p>Some content</p>
                    </div>
                </div>
                <script>var x = 1;</script>
                <style>.hidden { display: none }</style>
            </div>
        HTML;

        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringNotContainsString('<div class="container">', $result);
        self::assertStringNotContainsString('</div>', $result);
        self::assertStringNotContainsString('var x = 1', $result);
        self::assertStringNotContainsString('display: none', $result);
        self::assertStringContainsString('<h1>Title</h1>', $result);
        self::assertStringContainsString('<p>Some content</p>', $result);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function removedTagsDataProvider(): array
    {
        return [
            'script tag' => ['<script>alert(1);</script>', 'script'],
            'style tag' => ['<style>.x{}</style>', 'style'],
            'iframe tag' => ['<iframe src="x.html">content</iframe>', 'iframe'],
            'noscript tag' => ['<noscript>Enable JS</noscript>', 'noscript'],
            'video tag' => ['<video><source src="v.mp4"></video>', 'video'],
            'audio tag' => ['<audio><source src="a.mp3"></audio>', 'audio'],
        ];
    }

    #[Test]
    #[DataProvider('removedTagsDataProvider')]
    public function cleanTypo3HtmlRemovesTagsWithContent(string $html, string $tagName): void
    {
        $result = $this->subject->cleanTypo3Html($html);

        self::assertStringNotContainsString('<' . $tagName, $result);
    }
}

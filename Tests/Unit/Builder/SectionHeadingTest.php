<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Builder;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Builder\NavigationBuilder;

/**
 * Menu separators in the page tree split the llms.txt link list into sections.
 *
 * A separator is what TYPO3 already has for "the items below this belong
 * together" — it carries a title, it is translated like any page, and it is
 * never requested in the frontend. That is everything an H2 needs.
 */
final class SectionHeadingTest extends TestCase
{
    #[Test]
    public function aSeparatorBecomesAHeadingThatTheFollowingPagesBelongTo(): void
    {
        $lines = $this->subject()->formatAsMarkdown([
            self::section('Services'),
            self::page('Consulting', 'What we advise on.'),
            self::page('Development', 'What we build.'),
            self::section('Legal'),
            self::page('Imprint', 'Who runs this.'),
        ]);

        // A blank line above each heading, none below it — the list belongs to
        // the heading it follows.
        self::assertSame([
            '## Services',
            '- [Consulting](https://example.com/consulting.md): What we advise on.',
            '- [Development](https://example.com/development.md): What we build.',
            '',
            '## Legal',
            '- [Imprint](https://example.com/imprint.md): Who runs this.',
        ], $lines);
    }

    #[Test]
    public function theHeadingIsNotAlsoALink(): void
    {
        $lines = $this->subject()->formatAsMarkdown([
            self::section('Services'),
            self::page('Consulting', 'What we advise on.'),
        ]);

        self::assertSame([
            '## Services',
            '- [Consulting](https://example.com/consulting.md): What we advise on.',
        ], $lines);
    }

    #[Test]
    public function aHeadingWithNothingUnderItIsLeftOut(): void
    {
        // Under a strict language every page of a section can be untranslated
        // while the separator survives.
        $lines = $this->subject()->formatAsMarkdown([
            self::section('Empty'),
            self::section('Legal'),
            self::page('Imprint', 'Who runs this.'),
        ]);

        self::assertSame([
            '## Legal',
            '- [Imprint](https://example.com/imprint.md): Who runs this.',
        ], $lines);
    }

    #[Test]
    public function childPagesStayNestedUnderTheirParentWithinTheSection(): void
    {
        $products = self::page('Products', 'What we sell.');
        $products['pages'] = [self::page('Chairs', 'Four legs.')];

        $lines = $this->subject()->formatAsMarkdown([self::section('Shop'), $products]);

        self::assertSame([
            '## Shop',
            '- [Products](https://example.com/products.md): What we sell.',
            '    - [Chairs](https://example.com/chairs.md): Four legs.',
        ], $lines);
    }

    #[Test]
    #[DataProvider('titles')]
    public function onlyATitleMeantToBeReadBecomesAHeading(string $title, bool $expected): void
    {
        self::assertSame($expected, NavigationBuilder::isSectionTitle($title));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function titles(): array
    {
        return [
            // Separators are widely used as pure decoration in menus. Those must
            // not turn into headings when a site updates.
            'dashes' => ['---', false],
            'em dash' => ['—', false],
            'bullet' => ['•', false],
            'stars' => ['***', false],
            'empty' => ['', false],
            'spaces' => ['   ', false],
            'word' => ['Services', true],
            'number' => ['2026', true],
            'decorated word' => ['— Services —', true],
            'non-latin' => ['Dienstleistungen', true],
        ];
    }

    private function subject(): NavigationBuilder
    {
        return new class () extends NavigationBuilder {
            public function __construct() {}
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function section(string $title): array
    {
        return ['uid' => 99, 'title' => $title, 'section' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private static function page(string $title, string $description): array
    {
        return [
            'uid' => 1,
            'title' => $title,
            'description' => $description,
            'url' => 'https://example.com/' . strtolower($title) . '.md',
            'language' => 'English',
            'pages' => [],
        ];
    }
}

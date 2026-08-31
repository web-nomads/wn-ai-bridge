<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Service\LlmsFullTxtGeneratorService;

/**
 * Nesting of a page's own headings below the section heading it is written
 * under in llms-full.txt.
 *
 * A full document has to keep exactly one H1 and an outline that does not skip
 * a level, while content elements start at whatever level their template uses.
 */
final class HeadingNestingTest extends TestCase
{
    #[Test]
    #[DataProvider('blocks')]
    public function headingsAreShiftedBelowTheSection(string $markdown, int $minLevel, string $expected, string $why): void
    {
        self::assertSame($expected, LlmsFullTxtGeneratorService::nestHeadings($markdown, $minLevel), $why);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string, 3: string}>
     */
    public static function blocks(): array
    {
        return [
            'a page that starts at h1' => [
                "# Page\n\nText\n\n## Part",
                3,
                "### Page\n\nText\n\n#### Part",
                'The shallowest heading lands on the requested level, the rest keeps its distance.',
            ],
            'a page that starts at h2 leaves no gap' => [
                "## Part\n\nText\n\n### Detail",
                3,
                "### Part\n\nText\n\n#### Detail",
                'Content elements usually render h2 — shifting by a fixed amount would skip h3.',
            ],
            'a page that is already deep enough is promoted' => [
                "#### Part\n\n##### Detail",
                3,
                "### Part\n\n#### Detail",
                'Nesting means "directly below the section", not "at least below it".',
            ],
            'nothing to do' => [
                "### Part\n\nText",
                3,
                "### Part\n\nText",
                '',
            ],
            'headings deeper than h6 are clamped' => [
                "# Page\n\n###### Detail",
                5,
                "##### Page\n\n###### Detail",
                'Markdown has six levels; the last ones collapse rather than produce "#######".',
            ],
            'text without headings' => [
                "Just a paragraph.\n\nAnd another one.",
                3,
                "Just a paragraph.\n\nAnd another one.",
                'Nothing to shift, and no heading is invented.',
            ],
            'hashes inside a code fence' => [
                "## Part\n\n```bash\n# not a heading\n```\n\n### Detail",
                3,
                "### Part\n\n```bash\n# not a heading\n```\n\n#### Detail",
                'A comment in a code block is not part of the outline.',
            ],
            'a hash without text is not a heading' => [
                "## Part\n\n#\n\n### Detail",
                3,
                "### Part\n\n#\n\n#### Detail",
                'A bare "#" does not open a section and must not decide the shift.',
            ],
        ];
    }
}

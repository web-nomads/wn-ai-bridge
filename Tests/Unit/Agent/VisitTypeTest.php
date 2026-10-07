<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Agent;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Agent\VisitLogger;

/**
 * What a visit is filed as: llms.txt and llms-full.txt apart, Markdown versions, HTML pages
 */
final class VisitTypeTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function paths(): array
    {
        return [
            'the full document' => ['/llms-full.txt', 'llmsfull'],
            'the full document in its well-known place' => ['/.well-known/llms-full.txt', 'llmsfull'],
            'the full document of a language' => ['/en/llms-full.txt', 'llmsfull'],
            'the full document with a trailing slash' => ['/llms-full.txt/', 'llmsfull'],
            'the link list' => ['/llms.txt', 'llmstxt'],
            'the link list in its well-known place' => ['/.well-known/llms.txt', 'llmstxt'],
            'the link list of a language' => ['/en/llms.txt', 'llmstxt'],
            'the link list with a query' => ['/llms.txt?x=1', 'llmstxt'],
            'a markdown page' => ['/arbeiten/baechli-bergsport.md', 'markdown'],
            'a markdown page at the root' => ['/impressum.md', 'markdown'],
        ];
    }

    #[Test]
    #[DataProvider('paths')]
    public function everyEndpointIsFiledUnderItsOwnType(string $path, string $expected): void
    {
        self::assertSame($expected, VisitLogger::type($path, ''));
        self::assertSame($expected, VisitLogger::type($path, null));
    }

    #[Test]
    public function aPageIsOnlyLoggedWhenItIsAnHtmlDocument(): void
    {
        self::assertSame('page', VisitLogger::type('/arbeiten', 'text/html; charset=utf-8'));
        self::assertNull(VisitLogger::type('/arbeiten', 'application/json'));
    }

    #[Test]
    public function logImportsTakePathsWithoutFileExtensionAsPages(): void
    {
        self::assertSame('page', VisitLogger::type('/arbeiten/', null));
        self::assertSame('page', VisitLogger::type('/', null));
        self::assertSame('page', VisitLogger::type('/index.php?id=1', null));
        self::assertNull(VisitLogger::type('/fileadmin/logo.png', null));
        self::assertNull(VisitLogger::type('/typo3temp/assets/css/style.css', null));
    }

    #[Test]
    public function aPathThatMerelyMentionsTheDocumentIsNotIt(): void
    {
        self::assertSame('page', VisitLogger::type('/about-llms-full.txt.html', 'text/html'));
    }
}

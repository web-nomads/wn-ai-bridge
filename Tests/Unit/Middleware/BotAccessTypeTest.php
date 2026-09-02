<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use WebNomads\WnAiBridge\Middleware\BotAccessLogMiddleware;

/**
 * What the bot access log files a request as.
 *
 * llms.txt and llms-full.txt used to share one type, which left the module
 * unable to say how often the expensive full document was fetched — one is a
 * table of contents, the other is the whole site in a single request.
 */
final class BotAccessTypeTest extends TestCase
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
            'a markdown page' => ['/arbeiten/baechli-bergsport.md', 'markdown'],
            'a markdown page at the root' => ['/impressum.md', 'markdown'],
        ];
    }

    #[Test]
    #[DataProvider('paths')]
    public function everyEndpointIsFiledUnderItsOwnType(string $path, string $expected): void
    {
        self::assertSame($expected, BotAccessLogMiddleware::classify(
            new ServerRequest('https://example.com' . $path),
            new Response()
        ));
    }

    #[Test]
    public function aPageIsOnlyLoggedWhenItIsAnHtmlDocument(): void
    {
        $request = new ServerRequest('https://example.com/arbeiten');

        self::assertSame('page', BotAccessLogMiddleware::classify(
            $request,
            (new Response())->withHeader('Content-Type', 'text/html; charset=utf-8')
        ));

        // Assets, feeds and JSON endpoints are none of the module's business.
        self::assertNull(BotAccessLogMiddleware::classify(
            $request,
            (new Response())->withHeader('Content-Type', 'application/json')
        ));
    }

    #[Test]
    public function aPathThatMerelyMentionsTheDocumentIsNotIt(): void
    {
        // The name has to be the whole last segment.
        self::assertSame('page', BotAccessLogMiddleware::classify(
            new ServerRequest('https://example.com/about-llms-full.txt.html'),
            (new Response())->withHeader('Content-Type', 'text/html')
        ));
    }
}

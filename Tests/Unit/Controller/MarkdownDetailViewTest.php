<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\PageArguments;
use WebNomads\WnAiBridge\Controller\LlmsTxtController;

/**
 * A detail view asked for as Markdown has to answer with that record.
 *
 * Detail views of a plugin all live on the page that hosts it and are told
 * apart by their route arguments alone. Anything derived from the page id
 * therefore describes the list view — which is what ".md" used to return for
 * every one of them, and what the shared cache entry used to keep serving.
 */
final class MarkdownDetailViewTest extends TestCase
{
    private const DETAIL_PATH = '/arbeiten/baechli-bergsport-jubilaeumskampagne-2024';

    #[Test]
    public function theDetailViewKeepsItsOwnUrl(): void
    {
        $url = LlmsTxtController::htmlUrlForMarkdownRequest(
            new Uri('https://example.com' . self::DETAIL_PATH . '.md')
        );

        self::assertSame('https://example.com' . self::DETAIL_PATH, $url);
    }

    #[Test]
    public function aPlainPageLosesNothingButTheSuffix(): void
    {
        $url = LlmsTxtController::htmlUrlForMarkdownRequest(new Uri('https://example.com/arbeiten.md'));

        self::assertSame('https://example.com/arbeiten', $url);
    }

    #[Test]
    public function theQueryStringSurvives(): void
    {
        // Not every detail view is reached through a route enhancer.
        $url = LlmsTxtController::htmlUrlForMarkdownRequest(
            new Uri('https://example.com/arbeiten.md?tx_evoq_projects%5Bproject%5D=5')
        );

        self::assertSame('https://example.com/arbeiten?tx_evoq_projects%5Bproject%5D=5', $url);
    }

    #[Test]
    public function theFragmentIsDropped(): void
    {
        // It never reaches the server anyway, and the URL is fetched, not linked.
        $url = LlmsTxtController::htmlUrlForMarkdownRequest(new Uri('https://example.com/arbeiten.md#top'));

        self::assertSame('https://example.com/arbeiten', $url);
    }

    #[Test]
    public function aUrlWithoutTheSuffixIsNoneOfOurs(): void
    {
        // The caller falls back to the page it was rendering.
        self::assertSame('', LlmsTxtController::htmlUrlForMarkdownRequest(new Uri('https://example.com/arbeiten')));
        self::assertSame('', LlmsTxtController::htmlUrlForMarkdownRequest(new Uri('https://example.com/llms.txt')));
        self::assertSame('', LlmsTxtController::htmlUrlForMarkdownRequest(new Uri('https://example.com/readme.md.html')));
    }

    #[Test]
    public function aPlainPageHasNoCacheVariant(): void
    {
        // Its entry keeps the identifier it always had.
        self::assertSame('', LlmsTxtController::contentVariant(null));
        self::assertSame('', LlmsTxtController::contentVariant(new PageArguments(12, '1701', [])));
    }

    #[Test]
    public function twoRecordsOnOnePageGetTheirOwnCacheEntry(): void
    {
        $first = LlmsTxtController::contentVariant($this->detailArguments('5'));
        $second = LlmsTxtController::contentVariant($this->detailArguments('6'));

        self::assertNotSame('', $first);
        self::assertNotSame($first, $second);
    }

    #[Test]
    public function theSameRecordAlwaysGetsTheSameCacheEntry(): void
    {
        self::assertSame(
            LlmsTxtController::contentVariant($this->detailArguments('5')),
            LlmsTxtController::contentVariant($this->detailArguments('5'))
        );
    }

    #[Test]
    public function thePageTypeAndTheCacheHashAreNoVariant(): void
    {
        // "type" is 1701 for every .md request, and cHash is derived from the
        // arguments that are already accounted for.
        self::assertSame(
            '',
            LlmsTxtController::contentVariant(new PageArguments(12, '1701', ['type' => '1701', 'cHash' => 'abc123']))
        );

        self::assertSame(
            LlmsTxtController::contentVariant($this->detailArguments('5')),
            LlmsTxtController::contentVariant($this->detailArguments('5', ['cHash' => 'abc123']))
        );
    }

    /**
     * @param array<string, string> $extra
     */
    private function detailArguments(string $project, array $extra = []): PageArguments
    {
        return new PageArguments(12, '1701', array_merge([
            'tx_evoq_projects' => [
                'controller' => 'Project',
                'action' => 'show',
                'project' => $project,
            ],
        ], $extra));
    }
}

<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Search;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Search\SearchQuery;

class SearchQueryTest extends TestCase
{
    #[Test]
    public function termsSplitsAndLowercases(): void
    {
        self::assertSame(['studium', 'informatik'], SearchQuery::terms('Studium Informatik'));
    }

    #[Test]
    public function termsRemovesStopWordsAndPunctuation(): void
    {
        // "wie", "ich", "ein" are stop words; punctuation is stripped.
        self::assertSame(['bewerbe', 'stipendium'], SearchQuery::terms('Wie ich bewerbe ein Stipendium?'));
    }

    #[Test]
    public function termsDeduplicates(): void
    {
        self::assertSame(['master', 'programm'], SearchQuery::terms('Master Master Programm'));
    }

    #[Test]
    public function termsIgnoresTooShortTokens(): void
    {
        self::assertSame(['studium'], SearchQuery::terms('a Studium x'));
    }

    #[Test]
    public function termsFallsBackWhenOnlyStopWordsRemain(): void
    {
        // "wo", "ist", "das" are all stop words — fall back so search still works.
        self::assertNotSame([], SearchQuery::terms('Wo ist das?'));
    }

    #[Test]
    public function termsReturnsEmptyForBlankQuery(): void
    {
        self::assertSame([], SearchQuery::terms('   '));
    }

    #[Test]
    public function booleanModeBuildsRequiredPrefixWildcards(): void
    {
        self::assertSame('+studium* +beginn*', SearchQuery::booleanMode(['studium', 'beginn']));
    }

    #[Test]
    public function booleanModeStripsFulltextOperators(): void
    {
        self::assertSame('+studium*', SearchQuery::booleanMode(['+studium*']));
    }

    #[Test]
    public function snippetReturnsShortTextUnchanged(): void
    {
        self::assertSame('Kurzer Text', SearchQuery::snippet('Kurzer Text', ['text']));
    }

    #[Test]
    public function snippetCentresOnTheMatchedKeyword(): void
    {
        $text = str_repeat('bla ', 100) . 'Marcel bietet TYPO3-Upgrades auf die neueste Version an. ' . str_repeat('bla ', 100);
        $snippet = SearchQuery::snippet($text, ['upgrades'], 80);

        // The excerpt shows the relevant passage, not the (repeated) start.
        self::assertStringContainsStringIgnoringCase('upgrades', $snippet);
        self::assertStringStartsWith('…', $snippet);
        self::assertLessThanOrEqual(90, mb_strlen($snippet));
    }

    #[Test]
    public function snippetFallsBackToStartWhenNoTermMatches(): void
    {
        $text = str_repeat('Anfang ', 100);
        $snippet = SearchQuery::snippet($text, ['nichtvorhanden'], 60);

        self::assertStringStartsWith('Anfang', $snippet);
        self::assertStringEndsWith('…', $snippet);
    }

    #[Test]
    public function snippetStripsTagsAndCollapsesWhitespace(): void
    {
        self::assertSame('Hallo Welt', SearchQuery::snippet("<p>Hallo   \n Welt</p>", ['welt']));
    }
}

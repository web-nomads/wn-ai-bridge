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
}

<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Domain\Model\AssistantLearning;

/**
 * Which sites a curated answer applies to.
 *
 * An empty "site_identifier" used to mean the opposite of what it looked like:
 * the lookup asked for the current site's identifier and nothing else, so an
 * entry saved without a site matched no site at all and was never played back —
 * with nothing anywhere to say so. It now means "every site", which is also what
 * a new answer starts as.
 */
final class LearningSiteScopeTest extends TestCase
{
    #[Test]
    public function anAnswerWithoutASiteAppliesEverywhere(): void
    {
        $entry = $this->entry(AssistantLearning::ALL_SITES);

        self::assertTrue($entry->isForAllSites());
        self::assertTrue($entry->appliesToSite('marcelmarty'));
        self::assertTrue($entry->appliesToSite('web-nomads'));
        self::assertTrue($entry->appliesToSite(''));
    }

    #[Test]
    public function anAnswerWrittenForOneSiteStaysThere(): void
    {
        $entry = $this->entry('web-nomads');

        self::assertFalse($entry->isForAllSites());
        self::assertTrue($entry->appliesToSite('web-nomads'));
        self::assertFalse($entry->appliesToSite('marcelmarty'));
    }

    /**
     * The identifier is compared as it is stored — no trimming, no case
     * folding. A site identifier is an exact technical name, and pretending
     * otherwise would make "Web-Nomads" quietly work until it did not.
     */
    #[Test]
    public function theIdentifierIsMatchedExactly(): void
    {
        $entry = $this->entry('web-nomads');

        self::assertFalse($entry->appliesToSite('Web-Nomads'));
        self::assertFalse($entry->appliesToSite(' web-nomads'));
    }

    #[Test]
    public function anEmptyIdentifierIsTheDefaultForANewAnswer(): void
    {
        // The form and the controller both start from this constant, so it is
        // worth pinning that it is the "everything" value rather than a site.
        self::assertSame('', AssistantLearning::ALL_SITES);
    }

    private function entry(string $siteIdentifier): AssistantLearning
    {
        return AssistantLearning::fromRow([
            'uid' => 1,
            'crdate' => 1_800_000_000,
            'tstamp' => 1_800_000_000,
            'site_identifier' => $siteIdentifier,
            'language_uid' => 0,
            'status' => AssistantLearning::STATUS_APPROVED,
            'source' => AssistantLearning::SOURCE_MANUAL,
            'topic' => 'Was kostet das?',
            'wrong_answer' => '',
            'correction' => 'Es kostet nichts.',
            'keywords' => 'kosten',
            'ip_address' => '',
        ]);
    }
}

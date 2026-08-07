<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Domain\Model\AssistantLearning;
use WebNomads\WnAiBridge\Service\LearningService;

/**
 * Covers the "matches by meaning, not by wording" behaviour that decides whether
 * a stored answer is played back.
 */
final class LearningServiceScoreTest extends TestCase
{
    #[Test]
    public function anIdenticalQuestionScoresAtTheTop(): void
    {
        $score = LearningService::score('Was kostet der Versand?', $this->entry('Was kostet der Versand?'));

        self::assertGreaterThanOrEqual(LearningService::DIRECT_ANSWER_THRESHOLD, $score);
    }

    #[Test]
    public function aRephrasedQuestionStillMatches(): void
    {
        $entry = $this->entry('Was kostet der Versand?');

        self::assertGreaterThanOrEqual(
            LearningService::DIRECT_ANSWER_THRESHOLD,
            LearningService::score('Wie viel kostet der Versand?', $entry)
        );
    }

    #[Test]
    public function aDifferentlyIntroducedQuestionStillMatches(): void
    {
        $entry = $this->entry('Was sind die Versandkosten?');

        self::assertGreaterThanOrEqual(
            LearningService::DIRECT_ANSWER_THRESHOLD,
            LearningService::score('Wie hoch sind die Versandkosten?', $entry)
        );
    }

    #[Test]
    public function anUnrelatedQuestionDoesNotMatch(): void
    {
        $score = LearningService::score('Wann haben Sie geöffnet?', $this->entry('Was kostet der Versand?'));

        self::assertLessThan(LearningService::DIRECT_ANSWER_THRESHOLD, $score);
    }

    #[Test]
    public function keywordsWidenWhatMatches(): void
    {
        // "Lieferung" appears in neither the stored question nor its answer, so
        // only the extra keywords can connect it.
        $question = 'Wie teuer ist die Lieferung?';

        $withKeywords = LearningService::score(
            $question,
            $this->entry('Was kostet der Versand?', 'versand lieferung porto paket')
        );
        $withoutKeywords = LearningService::score($question, $this->entry('Was kostet der Versand?'));

        self::assertGreaterThan($withoutKeywords, $withKeywords);
    }

    #[Test]
    public function anEmptyQuestionScoresZero(): void
    {
        self::assertSame(0.0, LearningService::score('   ', $this->entry('Was kostet der Versand?')));
    }

    private function entry(string $topic, string $keywords = ''): AssistantLearning
    {
        return new AssistantLearning(
            1,
            0,
            0,
            'main',
            0,
            AssistantLearning::STATUS_APPROVED,
            AssistantLearning::SOURCE_MANUAL,
            $topic,
            '',
            'Der Versand kostet 7.90 CHF.',
            $keywords,
            '',
        );
    }
}

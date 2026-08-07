<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Service;

use WebNomads\WnAiBridge\Domain\Model\AssistantLearning;
use WebNomads\WnAiBridge\Domain\Repository\AssistantLearningRepository;
use WebNomads\WnAiBridge\Search\SearchQuery;

/**
 * The assistant's local learning source.
 *
 * It feeds on two things: corrections visitors make to an answer in the chat,
 * and question/answer pairs an editor maintains in the "Answers" backend
 * module. Visitor corrections are captured as "pending" and are only ever used
 * once an editor approved them, so a visitor cannot poison the assistant.
 *
 * Approved entries are matched against a new question by meaning rather than by
 * wording: term overlap (with prefix-tolerant matching, so "Versand" also hits
 * "Versandkosten") combined with overall string similarity. A strong match is
 * played back verbatim as the answer; weaker matches are handed to the LLM as
 * binding hints.
 */
final class LearningService
{
    /**
     * Score from which a stored answer is played back verbatim instead of being
     * generated. Tuned so a rephrased version of the stored question still hits,
     * while a merely related question does not.
     */
    public const DIRECT_ANSWER_THRESHOLD = 0.62;

    /**
     * Score from which an entry is passed to the LLM as a binding hint.
     */
    private const HINT_THRESHOLD = 0.22;

    /**
     * How many hints are handed to the LLM at most.
     */
    private const MAX_HINTS = 3;

    /**
     * Markers that indicate the visitor is correcting the previous answer.
     * Matched case-insensitively; German and English.
     */
    private const CORRECTION_MARKERS = [
        'das stimmt nicht', 'das ist falsch', 'ist falsch', 'das ist nicht richtig',
        'nicht korrekt', 'das ist nicht korrekt', 'stimmt nicht', 'falsche antwort',
        'eigentlich ist', 'eigentlich bietet', 'eigentlich', 'korrektur', 'richtig ist',
        'das ist nicht wahr', 'nein, das', 'nein das', 'das ist verkehrt',
        "that's wrong", 'that is wrong', "that's incorrect", 'that is incorrect',
        'not correct', 'not true', 'actually,', 'actually it', 'you are wrong',
        "you're wrong", 'wrong answer',
    ];

    public function __construct(
        private readonly ConfigurationService $configurationService,
        private readonly AssistantLearningRepository $repository,
    ) {}

    public function isEnabled(): bool
    {
        return $this->configurationService->isAssistantLearningEnabled();
    }

    /**
     * Detect and store a correction. The current message is treated as a
     * correction of the previous assistant answer found in the history.
     *
     * @param list<array{role: string, content: string}> $history
     */
    public function captureCorrection(
        string $message,
        array $history,
        int $languageId,
        string $siteIdentifier,
        string $conversationId = '',
        string $ipAddress = '',
    ): void {
        if (!$this->isEnabled() || !$this->looksLikeCorrection($message)) {
            return;
        }

        [$topic, $wrongAnswer] = $this->extractCorrectedTurn($history);
        if ($wrongAnswer === '') {
            // Nothing was answered yet, so there is nothing to correct.
            return;
        }

        $keywords = self::deriveKeywords($topic, $message);
        if ($keywords === '') {
            return;
        }

        try {
            $this->repository->add([
                'pid' => 0,
                'crdate' => time(),
                'tstamp' => time(),
                'site_identifier' => $siteIdentifier,
                'language_uid' => $languageId,
                'status' => AssistantLearning::STATUS_PENDING,
                'source' => AssistantLearning::SOURCE_VISITOR,
                'topic' => mb_substr($topic, 0, 2000),
                'wrong_answer' => mb_substr($wrongAnswer, 0, 2000),
                'correction' => mb_substr(trim($message), 0, 2000),
                'keywords' => $keywords,
                'conversation_id' => $conversationId,
                'ip_address' => $ipAddress,
            ]);
        } catch (\Throwable $e) {
            // Best-effort — never break the assistant response.
        }
    }

    /**
     * The approved entry that answers the given question closely enough to be
     * played back as-is, or null when there is no strong match.
     */
    public function findAnswer(string $question, int $languageId, string $siteIdentifier): ?AssistantLearning
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $ranked = $this->rank($question, $languageId, $siteIdentifier);
        $best = $ranked[0] ?? null;

        if ($best === null || $best['score'] < self::DIRECT_ANSWER_THRESHOLD) {
            return null;
        }

        return $best['entry'];
    }

    /**
     * Approved entries relevant to the given question, formatted as a prompt
     * block, or an empty string when there is nothing to add.
     */
    public function getRelevantLearningsPrompt(string $question, int $languageId, string $siteIdentifier): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        $lines = '';
        foreach (array_slice($this->rank($question, $languageId, $siteIdentifier), 0, self::MAX_HINTS) as $match) {
            $topic = $this->oneLine($match['entry']->topic);
            $answer = $this->oneLine($match['entry']->correction);
            $lines .= $topic !== ''
                ? sprintf("- Frage \"%s\": %s\n", $topic, $answer)
                : '- ' . $answer . "\n";
        }

        if ($lines === '') {
            return '';
        }

        return 'VOM WEBSITE-BETREIBER BESTÄTIGTE HINWEISE (verbindlich; haben Vorrang vor den '
            . "Suchergebnissen, wenn sie zur Frage passen):\n" . $lines;
    }

    /**
     * Search terms condensed into the stored keyword field. Public so the backend
     * module derives keywords exactly the same way for manually created entries.
     */
    public static function deriveKeywords(string $question, string $answer): string
    {
        $terms = SearchQuery::terms($question . ' ' . $answer, 2, 12);

        return $terms === [] ? '' : mb_substr(implode(' ', $terms), 0, 255);
    }

    /**
     * How well a question matches a stored entry, from 0.0 to 1.0.
     *
     * Term overlap dominates (it captures the topic), overall string similarity
     * refines it (it captures rephrasings). The entry is matched against both its
     * question and its keywords, whichever fits better, because a keyword list
     * condensed from a long chat turn scores differently from a short question.
     */
    public static function score(string $question, AssistantLearning $entry): float
    {
        $questionTerms = SearchQuery::terms($question, 2, 24);
        if ($questionTerms === []) {
            return 0.0;
        }

        $overlap = max(
            self::dice($questionTerms, SearchQuery::terms($entry->topic, 2, 24)),
            self::dice($questionTerms, SearchQuery::terms($entry->keywords, 2, 24)),
        );

        $textual = self::textSimilarity($question, $entry->topic);

        return round(0.75 * $overlap + 0.25 * $textual, 4);
    }

    /**
     * Approved entries scored against the question, best first, hints and above.
     *
     * @return list<array{score: float, entry: AssistantLearning}>
     */
    private function rank(string $question, int $languageId, string $siteIdentifier): array
    {
        $matches = [];
        foreach ($this->repository->findApproved($siteIdentifier, $languageId) as $entry) {
            if (trim($entry->correction) === '') {
                continue;
            }
            $score = self::score($question, $entry);
            if ($score >= self::HINT_THRESHOLD) {
                $matches[] = ['score' => $score, 'entry' => $entry];
            }
        }

        usort($matches, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return $matches;
    }

    /**
     * Sørensen–Dice coefficient over two term sets, counting a term as shared
     * when it is equal to or a prefix of a term on the other side (so "versand"
     * matches "versandkosten"). Prefix matching needs at least four characters to
     * avoid accidental hits on short words.
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function dice(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $shared = 0;
        foreach ($a as $termA) {
            foreach ($b as $termB) {
                if (self::termsMatch($termA, $termB)) {
                    $shared++;
                    break;
                }
            }
        }

        return (2 * $shared) / (count($a) + count($b));
    }

    private static function termsMatch(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        $shorter = mb_strlen($a) <= mb_strlen($b) ? $a : $b;
        if (mb_strlen($shorter) < 4) {
            return false;
        }

        return str_starts_with($a, $b) || str_starts_with($b, $a);
    }

    /**
     * Overall similarity of two texts, 0.0 to 1.0. Compared on a normalised form
     * so punctuation and casing do not matter.
     */
    private static function textSimilarity(string $a, string $b): float
    {
        $a = self::normalise($a);
        $b = self::normalise($b);
        if ($a === '' || $b === '') {
            return 0.0;
        }

        // similar_text() is O(n²); the inputs are capped so a long chat turn
        // cannot make the comparison expensive.
        similar_text(mb_substr($a, 0, 300), mb_substr($b, 0, 300), $percent);

        return $percent / 100;
    }

    private static function normalise(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = (string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    private function looksLikeCorrection(string $message): bool
    {
        $normalised = mb_strtolower(trim($message));
        if ($normalised === '') {
            return false;
        }
        foreach (self::CORRECTION_MARKERS as $marker) {
            if (str_contains($normalised, $marker)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Find the previous question/answer pair the visitor is correcting.
     *
     * @param list<array{role: string, content: string}> $history
     * @return array{0: string, 1: string} [topic (previous question), wrong answer]
     */
    private function extractCorrectedTurn(array $history): array
    {
        $wrongAnswer = '';
        $topic = '';

        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') === 'assistant') {
                $wrongAnswer = trim((string)($history[$i]['content'] ?? ''));
                // The user turn just before it is the topic being corrected.
                for ($j = $i - 1; $j >= 0; $j--) {
                    if (($history[$j]['role'] ?? '') === 'user') {
                        $topic = trim((string)($history[$j]['content'] ?? ''));
                        break;
                    }
                }
                break;
            }
        }

        return [$topic, $wrongAnswer];
    }

    private function oneLine(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}

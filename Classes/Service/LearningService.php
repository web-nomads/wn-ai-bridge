<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Service;

use WebNomads\WnAiBridge\Domain\Model\AssistantLearning;
use WebNomads\WnAiBridge\Domain\Repository\AssistantLearningRepository;
use WebNomads\WnAiBridge\Search\SearchQuery;

/**
 * Owner-moderated learning from visitor corrections.
 *
 * When a visitor corrects a previous answer, the correction is captured as a
 * "pending" entry for review in the backend. Only entries an editor has approved
 * are ever fed back into future answers — and only when the new question
 * thematically matches — so a visitor cannot poison the assistant for others.
 */
final class LearningService
{
    /**
     * Markers that indicate the visitor is correcting the previous answer.
     * Matched case-insensitively; German and English.
     */
    private const CORRECTION_MARKERS = [
        'das stimmt nicht', 'das ist falsch', 'ist falsch', 'das ist nicht richtig',
        'nicht korrekt', 'das ist nicht korrekt', 'stimmt nicht', 'falsche antwort',
        'eigentlich ist', 'eigentlich bietet', 'eigentlich', 'korrektur', 'richtig ist',
        'das ist nicht wahr', 'nein, das', 'nein das', 'das ist verkehrt',
        "that's wrong", "that is wrong", "that's incorrect", "that is incorrect",
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

        $keywords = SearchQuery::terms($topic . ' ' . $message);
        if ($keywords === []) {
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
                'topic' => mb_substr($topic, 0, 2000),
                'wrong_answer' => mb_substr($wrongAnswer, 0, 2000),
                'correction' => mb_substr(trim($message), 0, 2000),
                'keywords' => mb_substr(implode(' ', $keywords), 0, 255),
                'conversation_id' => $conversationId,
                'ip_address' => $ipAddress,
            ]);
        } catch (\Throwable $e) {
            // Best-effort — never break the assistant response.
        }
    }

    /**
     * Approved corrections relevant to the given question, formatted as a prompt
     * block, or an empty string when there is nothing to add.
     */
    public function getRelevantLearningsPrompt(string $question, int $languageId, string $siteIdentifier): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        $terms = SearchQuery::terms($question);
        $learnings = $this->repository->findApprovedMatching($terms, $siteIdentifier, $languageId);
        if ($learnings === []) {
            return '';
        }

        $lines = '';
        foreach ($learnings as $learning) {
            $lines .= '- ' . $this->oneLine($learning->correction) . "\n";
        }

        return "VOM WEBSITE-BETREIBER BESTÄTIGTE HINWEISE (verbindlich; haben Vorrang vor den "
            . "Suchergebnissen, wenn sie zur Frage passen):\n" . $lines;
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

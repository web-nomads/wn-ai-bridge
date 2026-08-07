<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Domain\Model;

/**
 * One entry of the local learning source: either a correction a visitor made to
 * an answer, or a question/answer pair an editor maintains in the backend.
 *
 * "topic" holds the question the entry answers, "correction" the answer that is
 * played back once the entry is approved and a new question matches it.
 */
final class AssistantLearning
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';

    /** Captured from a visitor correction in the chat. */
    public const SOURCE_VISITOR = 'visitor';
    /** Created or last edited by an editor in the backend module. */
    public const SOURCE_MANUAL = 'manual';

    public function __construct(
        public readonly int $uid,
        public readonly int $crdate,
        public readonly int $tstamp,
        public readonly string $siteIdentifier,
        public readonly int $languageUid,
        public readonly string $status,
        public readonly string $source,
        public readonly string $topic,
        public readonly string $wrongAnswer,
        public readonly string $correction,
        public readonly string $keywords,
        public readonly string $ipAddress,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int)($row['uid'] ?? 0),
            (int)($row['crdate'] ?? 0),
            (int)($row['tstamp'] ?? 0),
            (string)($row['site_identifier'] ?? ''),
            (int)($row['language_uid'] ?? 0),
            (string)($row['status'] ?? self::STATUS_PENDING),
            (string)($row['source'] ?? self::SOURCE_VISITOR),
            (string)($row['topic'] ?? ''),
            (string)($row['wrong_answer'] ?? ''),
            (string)($row['correction'] ?? ''),
            (string)($row['keywords'] ?? ''),
            (string)($row['ip_address'] ?? ''),
        );
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->setTimestamp($this->crdate);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isManual(): bool
    {
        return $this->source === self::SOURCE_MANUAL;
    }
}

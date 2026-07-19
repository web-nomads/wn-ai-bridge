<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Domain\Model;

/**
 * One captured visitor correction, pending or approved for owner-moderated
 * learning. Read-only view used by the backend module.
 */
final class AssistantLearning
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';

    public function __construct(
        public readonly int $uid,
        public readonly int $crdate,
        public readonly string $siteIdentifier,
        public readonly int $languageUid,
        public readonly string $status,
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
            (string)($row['site_identifier'] ?? ''),
            (int)($row['language_uid'] ?? 0),
            (string)($row['status'] ?? self::STATUS_PENDING),
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
}

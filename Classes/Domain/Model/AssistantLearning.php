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

    /**
     * What "site_identifier" holds for an entry that applies to every site of
     * the installation — the default for anything written by hand.
     *
     * An empty column used to mean the opposite: it matched no site at all,
     * because the lookup asked for the current site's identifier and nothing
     * else. An entry saved without a site was therefore never played back, and
     * nothing said so.
     */
    public const ALL_SITES = '';

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

    /**
     * Whether this entry applies to the given site.
     *
     * The rule the SQL in {@see \WebNomads\WnAiBridge\Domain\Repository\AssistantLearningRepository}
     * expresses, kept here as well so it can be read and exercised without a
     * database — and so both sides can be checked against each other.
     */
    public function appliesToSite(string $siteIdentifier): bool
    {
        return $this->siteIdentifier === self::ALL_SITES
            || $this->siteIdentifier === $siteIdentifier;
    }

    /**
     * Whether this entry is meant for every site rather than one of them.
     *
     * Named so Fluid can reach it: a template asks for "forAllSites", and Fluid
     * resolves object properties only through get…(), is…() and has…().
     */
    public function isForAllSites(): bool
    {
        return $this->siteIdentifier === self::ALL_SITES;
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

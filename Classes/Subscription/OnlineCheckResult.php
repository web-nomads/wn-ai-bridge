<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Subscription;

/**
 * The issuing server's verdict on a subscription, as cached locally.
 *
 * "unknown" is the safe default: it is what an unreachable or unverifiable
 * server yields, and it never disables anything — only an explicit, signed
 * "revoked" does.
 */
final class OnlineCheckResult
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';
    /** The server could not be reached, or its answer could not be verified. */
    public const STATUS_UNKNOWN = 'unknown';

    private function __construct(
        public readonly string $status,
        public readonly int $validUntil,
        public readonly int $checkedAt,
    ) {}

    public static function active(int $validUntil, ?int $checkedAt = null): self
    {
        return new self(self::STATUS_ACTIVE, $validUntil, $checkedAt ?? time());
    }

    public static function revoked(?int $checkedAt = null): self
    {
        return new self(self::STATUS_REVOKED, 0, $checkedAt ?? time());
    }

    public static function unknown(?int $checkedAt = null): self
    {
        return new self(self::STATUS_UNKNOWN, 0, $checkedAt ?? time());
    }

    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED;
    }

    /**
     * Whether this is an answer the server actually gave and we could verify.
     * Only then may its end date override the one inside the key — an
     * unreachable server must never extend a subscription.
     */
    public function isVerified(): bool
    {
        return $this->status !== self::STATUS_UNKNOWN;
    }

    /**
     * @return array{status: string, validUntil: int, checkedAt: int}
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'validUntil' => $this->validUntil, 'checkedAt' => $this->checkedAt];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $status = (string)($data['status'] ?? self::STATUS_UNKNOWN);
        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_REVOKED, self::STATUS_UNKNOWN], true)) {
            $status = self::STATUS_UNKNOWN;
        }

        return new self($status, (int)($data['validUntil'] ?? 0), (int)($data['checkedAt'] ?? 0));
    }
}

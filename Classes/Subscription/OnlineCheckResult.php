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

    /** Not asked at all — no attempt was made, so nothing went wrong. */
    public const FAILURE_NONE = '';
    /** No answer: connection refused, timeout, DNS, TLS. */
    public const FAILURE_UNREACHABLE = 'unreachable';
    /** Answered, but not with 200. */
    public const FAILURE_HTTP = 'http';
    /** Answered with 200, but the body was unusable — malformed, wrong
     *  subscription, replayed nonce, stale timestamp or a bad signature. */
    public const FAILURE_INVALID = 'invalid';

    private function __construct(
        public readonly string $status,
        public readonly int $validUntil,
        public readonly int $checkedAt,
        public readonly string $failureReason = self::FAILURE_NONE,
        /** The server that was asked — shown with the failure, so it can be corrected. */
        public readonly string $serverUrl = '',
        /**
         * The domains the subscription covers according to the server, empty
         * when it named none. This is how a domain added to a licence reaches an
         * installation without anyone pasting a new key.
         *
         * @var list<string>
         */
        public readonly array $domains = [],
    ) {}

    /**
     * @param list<string> $domains
     */
    public static function active(int $validUntil, ?int $checkedAt = null, array $domains = []): self
    {
        return new self(self::STATUS_ACTIVE, $validUntil, $checkedAt ?? time(), self::FAILURE_NONE, '', $domains);
    }

    public static function revoked(?int $checkedAt = null): self
    {
        return new self(self::STATUS_REVOKED, 0, $checkedAt ?? time());
    }

    public static function unknown(
        ?int $checkedAt = null,
        string $failureReason = self::FAILURE_NONE,
        string $serverUrl = '',
    ): self {
        return new self(self::STATUS_UNKNOWN, 0, $checkedAt ?? time(), $failureReason, $serverUrl);
    }

    /**
     * Whether an attempt was actually made and the server did not answer
     * properly. "Unknown" alone does not say that — it is also the state before
     * anyone asked.
     */
    public function hasFailed(): bool
    {
        return $this->failureReason !== self::FAILURE_NONE;
    }

    /**
     * A short English explanation of the failure, empty when there was none.
     */
    public function getFailureMessage(): string
    {
        return match ($this->failureReason) {
            self::FAILURE_UNREACHABLE => 'The issuing server could not be reached.',
            self::FAILURE_HTTP => 'The issuing server answered with an error.',
            self::FAILURE_INVALID => 'The answer of the issuing server could not be verified.',
            default => '',
        };
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
     * @return array{status: string, validUntil: int, checkedAt: int, failureReason: string, serverUrl: string, domains: list<string>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'validUntil' => $this->validUntil,
            'checkedAt' => $this->checkedAt,
            'failureReason' => $this->failureReason,
            'serverUrl' => $this->serverUrl,
            'domains' => $this->domains,
        ];
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

        $failureReason = (string)($data['failureReason'] ?? self::FAILURE_NONE);
        if (!in_array($failureReason, [self::FAILURE_NONE, self::FAILURE_UNREACHABLE, self::FAILURE_HTTP, self::FAILURE_INVALID], true)) {
            $failureReason = self::FAILURE_NONE;
        }

        return new self(
            $status,
            (int)($data['validUntil'] ?? 0),
            (int)($data['checkedAt'] ?? 0),
            $status === self::STATUS_UNKNOWN ? $failureReason : self::FAILURE_NONE,
            (string)($data['serverUrl'] ?? ''),
            // Only an "active" verdict carries a domain list. A cache entry
            // written before this existed simply has none, which reads as "the
            // server said nothing about domains" — the key then stands, exactly
            // as it did before.
            $status === self::STATUS_ACTIVE ? self::stringList($data['domains'] ?? []) : [],
        );
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $list[] = $item;
            }
        }

        return $list;
    }
}

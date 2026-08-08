<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Subscription;

/**
 * The decrypted content of a subscription key: who it was issued to, which
 * domains it covers, when it expires and which features it unlocks.
 *
 * Instances are only ever created from an already decrypted and signature
 * verified payload (see {@see SubscriptionKeyCodec}), so the data in here can be
 * trusted.
 */
final class SubscriptionToken
{
    /**
     * @param list<string> $domains Host patterns, e.g. "example.com" or "*.example.com".
     * @param list<string> $features Feature keys; an empty list means "everything".
     * @param string $checkUrl Base URL of the issuing server for the daily status
     *                         check. Part of the signed payload, so it cannot be
     *                         redirected to a server of the customer's choosing.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $customer,
        public readonly string $email,
        public readonly array $domains,
        public readonly int $issuedAt,
        public readonly int $expiresAt,
        public readonly array $features,
        public readonly string $checkUrl = '',
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            (string)($payload['id'] ?? ''),
            (string)($payload['customer'] ?? ''),
            (string)($payload['email'] ?? ''),
            self::stringList($payload['domains'] ?? []),
            (int)($payload['iat'] ?? 0),
            (int)($payload['exp'] ?? 0),
            self::stringList($payload['features'] ?? []),
            trim((string)($payload['chk'] ?? '')),
        );
    }

    /**
     * Whether the key is inside its final stretch — already expired, or expiring
     * within the given number of seconds.
     *
     * This is what opens the narrow window in which the client asks the issuing
     * server even from a frontend request: a renewal moves the end date on the
     * server, and the key in the customer's configuration does not follow.
     */
    public function isExpiringWithin(int $seconds, ?int $now = null): bool
    {
        if ($this->expiresAt <= 0) {
            return false;
        }

        return ($this->expiresAt - ($now ?? time())) <= $seconds;
    }

    public function isExpired(?int $now = null): bool
    {
        // exp = 0 means "no expiry date"; every generated key carries one, but be
        // lenient so a future key format without an end date keeps working.
        if ($this->expiresAt <= 0) {
            return false;
        }
        return ($now ?? time()) > $this->expiresAt;
    }

    /**
     * Whether the given HTTP host is covered by this key. Comparison ignores the
     * port, a trailing dot and the case; "*.example.com" matches any subdomain of
     * example.com (but not the apex, which has to be listed separately).
     */
    public function matchesHost(string $host): bool
    {
        $host = $this->normaliseHost($host);
        if ($host === '' || $this->domains === []) {
            return false;
        }

        foreach ($this->domains as $pattern) {
            $pattern = $this->normaliseHost($pattern);
            if ($pattern === '') {
                continue;
            }
            if ($pattern === $host) {
                return true;
            }
            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 1); // ".example.com"
                if (str_ends_with($host, $suffix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A key without an explicit feature list unlocks everything, so older keys
     * keep working when new features are introduced.
     */
    public function hasFeature(string $feature): bool
    {
        return $this->features === [] || in_array($feature, $this->features, true);
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        if ($this->expiresAt <= 0) {
            return null;
        }
        return (new \DateTimeImmutable())->setTimestamp($this->expiresAt);
    }

    /**
     * Days left until the key expires; null when it never expires and a negative
     * number once it has expired.
     */
    public function getDaysLeft(?int $now = null): ?int
    {
        if ($this->expiresAt <= 0) {
            return null;
        }
        return (int)floor(($this->expiresAt - ($now ?? time())) / 86400);
    }

    public function getDomainList(): string
    {
        return implode(', ', $this->domains);
    }

    private function normaliseHost(string $host): string
    {
        $host = trim(mb_strtolower($host));
        // Strip an optional scheme, path and port so a pasted URL also works.
        $host = (string)preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host);
        $host = explode('/', $host)[0];
        $host = explode(':', $host)[0];

        return rtrim($host, '.');
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
            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item !== '') {
                $list[] = $item;
            }
        }

        return array_values(array_unique($list));
    }
}

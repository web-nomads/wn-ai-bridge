<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\RateLimiter;

/**
 * Immutable result of a single rate limit check.
 */
final readonly class RateLimitStatus
{
    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public int $retryAfter,
    ) {}

    public function isAllowed(): bool
    {
        return $this->allowed;
    }
}

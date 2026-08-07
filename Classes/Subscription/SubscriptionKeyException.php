<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Subscription;

/**
 * Thrown when a subscription key cannot be decoded or verified. The exception
 * message carries one of the SubscriptionStatus::REASON_* codes so callers can
 * turn it into a precise, translatable message.
 */
final class SubscriptionKeyException extends \RuntimeException
{
    public function __construct(private readonly string $reason, int $code = 0)
    {
        parent::__construct($reason, $code);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}

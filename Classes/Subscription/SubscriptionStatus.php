<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Subscription;

/**
 * Outcome of validating the configured subscription key: valid or not, why not,
 * and — when it could be decoded — the token behind it.
 */
final class SubscriptionStatus
{
    public const REASON_OK = 'ok';
    /** No key is configured at all. */
    public const REASON_MISSING = 'missing';
    /** The key is not in the expected format. */
    public const REASON_MALFORMED = 'malformed';
    /** The signature does not match — the key was edited or is not ours. */
    public const REASON_SIGNATURE = 'signature';
    /** Decryption succeeded structurally but the content is unusable. */
    public const REASON_PAYLOAD = 'payload';
    /** The validity period has ended. */
    public const REASON_EXPIRED = 'expired';
    /** The issuing server reported the subscription as revoked. */
    public const REASON_REVOKED = 'revoked';
    /** The key is valid but not issued for the domain this site runs on. */
    public const REASON_DOMAIN = 'domain';
    /** PHP is missing the sodium extension needed to verify the key. */
    public const REASON_UNSUPPORTED = 'unsupported';

    /**
     * @param int $effectiveValidUntil The end date this installation goes by. It
     *        is the issuing server's date once the daily check confirmed one, and
     *        the date inside the key otherwise — a renewal only ever shows up in
     *        the former. 0 means "no end date".
     */
    private function __construct(
        public readonly bool $valid,
        public readonly string $reason,
        public readonly ?SubscriptionToken $token,
        public readonly string $host,
        public readonly int $effectiveValidUntil = 0,
        public readonly ?OnlineCheckResult $onlineCheck = null,
    ) {}

    public static function valid(
        SubscriptionToken $token,
        string $host,
        int $effectiveValidUntil = 0,
        ?OnlineCheckResult $onlineCheck = null,
    ): self {
        return new self(true, self::REASON_OK, $token, $host, $effectiveValidUntil, $onlineCheck);
    }

    public static function invalid(
        string $reason,
        ?SubscriptionToken $token = null,
        string $host = '',
        int $effectiveValidUntil = 0,
        ?OnlineCheckResult $onlineCheck = null,
    ): self {
        return new self(false, $reason, $token, $host, $effectiveValidUntil, $onlineCheck);
    }

    /**
     * Whether the last attempt to reach the issuing server went wrong. Worth
     * showing: nothing is disabled by it, but a renewal cannot arrive and a
     * revocation cannot take effect while it lasts.
     *
     * Only reported for a valid key. Without one there is nothing to renew or
     * revoke, and the missing key is the message that matters — a second one
     * about its server would only bury it.
     */
    public function hasOnlineCheckFailed(): bool
    {
        return $this->valid && ($this->onlineCheck?->hasFailed() ?? false);
    }

    /**
     * The warning to show alongside the subscription state, empty when the last
     * check was fine or none was made.
     */
    public function getOnlineCheckMessage(): string
    {
        if (!$this->hasOnlineCheckFailed()) {
            return '';
        }

        return $this->onlineCheck?->getFailureMessage() . ' '
            . 'The subscription keeps working according to the date in its key, '
            . 'but a renewal or a revocation will not reach this installation until the server answers again.';
    }

    /**
     * The end date as a date object, or null when the subscription has none.
     */
    public function getValidUntil(): ?\DateTimeImmutable
    {
        if ($this->effectiveValidUntil > 0) {
            return (new \DateTimeImmutable())->setTimestamp($this->effectiveValidUntil);
        }

        return $this->token?->getExpiresAt();
    }

    public function hasFeature(string $feature): bool
    {
        return $this->valid && $this->token !== null && $this->token->hasFeature($feature);
    }

    /**
     * A short, German explanation for the backend. Kept here (rather than in a
     * template) so every module can show the same wording.
     */
    public function getMessage(): string
    {
        return match ($this->reason) {
            self::REASON_OK => sprintf(
                'Subscription active for %s – valid until %s.',
                $this->token?->getDomainList() ?: 'this domain',
                $this->getValidUntil()?->format('Y-m-d') ?? 'unlimited',
            ),
            self::REASON_MISSING => 'No subscription key is configured. '
                . 'Enter the key in the extension configuration of "wn_ai_bridge".',
            self::REASON_MALFORMED => 'The configured subscription key has an invalid format. '
                . 'Copy the key from the e-mail completely and without line breaks.',
            self::REASON_SIGNATURE => 'The signature of the subscription key is invalid. '
                . 'The key was altered, or it was not issued by this vendor.',
            self::REASON_PAYLOAD => 'The contents of the subscription key could not be read. '
                . 'Please request a new key.',
            self::REASON_EXPIRED => sprintf(
                'The subscription expired on %s. Please renew it.',
                $this->getValidUntil()?->format('Y-m-d') ?? '',
            ),
            self::REASON_REVOKED => 'The subscription was revoked by the issuer. '
                . 'Please get in touch.',
            self::REASON_DOMAIN => sprintf(
                'The subscription key is not valid for the domain "%s", but for: %s.',
                $this->host,
                $this->token?->getDomainList() ?: '(no domain stored)',
            ),
            self::REASON_UNSUPPORTED => 'The subscription key cannot be verified '
                . 'because the PHP extension "sodium" is not available.',
            default => 'The subscription is not valid.',
        };
    }
}

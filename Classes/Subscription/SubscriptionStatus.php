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
        /**
         * The domains this installation goes by — the issuing server's list once
         * it confirmed one, the key's otherwise. Empty only when there is no
         * token at all.
         *
         * @var list<string>
         */
        public readonly array $domains = [],
    ) {}

    /**
     * @param list<string> $domains
     */
    public static function valid(
        SubscriptionToken $token,
        string $host,
        int $effectiveValidUntil = 0,
        ?OnlineCheckResult $onlineCheck = null,
        array $domains = [],
    ): self {
        return new self(true, self::REASON_OK, $token, $host, $effectiveValidUntil, $onlineCheck, $domains);
    }

    /**
     * @param list<string> $domains
     */
    public static function invalid(
        string $reason,
        ?SubscriptionToken $token = null,
        string $host = '',
        int $effectiveValidUntil = 0,
        ?OnlineCheckResult $onlineCheck = null,
        array $domains = [],
    ): self {
        return new self(false, $reason, $token, $host, $effectiveValidUntil, $onlineCheck, $domains);
    }

    /**
     * The domains behind this state, as one readable line.
     *
     * Falls back to the key's own list, so a status assembled without a verdict
     * — a malformed key, a test, an older caller — still says something rather
     * than nothing.
     */
    public function getDomainList(): string
    {
        $domains = $this->domains !== [] ? $this->domains : ($this->token?->domains ?? []);

        return implode(', ', $domains);
    }

    /**
     * Whether the domains above were confirmed by the issuing server rather than
     * read out of the key. Worth saying in the backend: only then does a domain
     * added to the licence show up here.
     */
    public function hasServerConfirmedDomains(): bool
    {
        return ($this->onlineCheck?->domains ?? []) !== [];
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
     * Whether the configured key is a trial.
     *
     * Read from the key itself, so it holds even when the issuing server cannot
     * be reached. Worth stating wherever the state is shown: a trial ends on its
     * date and nothing renews it, so "active until" means something different
     * here than it does for a bought subscription.
     */
    public function isTrial(): bool
    {
        return $this->token?->trial ?? false;
    }

    /**
     * A short, German explanation for the backend. Kept here (rather than in a
     * template) so every module can show the same wording.
     */
    public function getMessage(): string
    {
        return match ($this->reason) {
            // A trial says so first and in its own sentence. It expires for good
            // on that date — no renewal moves it — so presenting it in the same
            // words as a bought subscription would set the wrong expectation.
            self::REASON_OK => $this->isTrial()
                ? sprintf(
                    'Trial subscription active for %s – valid until %s. It does not renew; '
                    . 'order a subscription to keep the AI Bridge running afterwards.',
                    $this->getDomainList() ?: 'this domain',
                    $this->getValidUntil()?->format('Y-m-d') ?? 'unlimited',
                )
                : sprintf(
                    'Subscription active for %s – valid until %s.',
                    $this->getDomainList() ?: 'this domain',
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
            self::REASON_EXPIRED => $this->isTrial()
                ? sprintf(
                    'The trial ended on %s. Order a subscription to keep using the AI Bridge.',
                    $this->getValidUntil()?->format('Y-m-d') ?? '',
                )
                : sprintf(
                    'The subscription expired on %s. Please renew it.',
                    $this->getValidUntil()?->format('Y-m-d') ?? '',
                ),
            self::REASON_REVOKED => 'The subscription was revoked by the issuer. '
                . 'Please get in touch.',
            self::REASON_DOMAIN => sprintf(
                'The subscription is not valid for the domain "%s", but for: %s. '
                . 'A domain can be added to the licence by its issuer; the change reaches this '
                . 'installation with the next daily status check, without a new key.',
                $this->host,
                $this->getDomainList() ?: '(no domain stored)',
            ),
            self::REASON_UNSUPPORTED => 'The subscription key cannot be verified '
                . 'because the PHP extension "sodium" is not available.',
            default => 'The subscription is not valid.',
        };
    }
}

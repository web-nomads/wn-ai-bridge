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
    ) {}

    public static function valid(SubscriptionToken $token, string $host, int $effectiveValidUntil = 0): self
    {
        return new self(true, self::REASON_OK, $token, $host, $effectiveValidUntil);
    }

    public static function invalid(
        string $reason,
        ?SubscriptionToken $token = null,
        string $host = '',
        int $effectiveValidUntil = 0,
    ): self {
        return new self(false, $reason, $token, $host, $effectiveValidUntil);
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
                'Subscription aktiv für %s – gültig bis %s.',
                $this->token?->getDomainList() ?: 'diese Domain',
                $this->getValidUntil()?->format('d.m.Y') ?? 'unbegrenzt',
            ),
            self::REASON_MISSING => 'Es ist kein Subscription-Key hinterlegt. '
                . 'Tragen Sie den Key in der Erweiterungskonfiguration von "wn_ai_bridge" ein.',
            self::REASON_MALFORMED => 'Der hinterlegte Subscription-Key hat ein ungültiges Format. '
                . 'Bitte kopieren Sie den Key vollständig und ohne Zeilenumbrüche aus der E-Mail.',
            self::REASON_SIGNATURE => 'Die Signatur des Subscription-Keys ist ungültig. '
                . 'Der Key wurde verändert oder stammt nicht von WebNomads.',
            self::REASON_PAYLOAD => 'Der Inhalt des Subscription-Keys konnte nicht gelesen werden. '
                . 'Bitte fordern Sie einen neuen Key an.',
            self::REASON_EXPIRED => sprintf(
                'Die Subscription ist am %s abgelaufen. Bitte verlängern Sie sie.',
                $this->getValidUntil()?->format('d.m.Y') ?? '',
            ),
            self::REASON_REVOKED => 'Die Subscription wurde von WebNomads widerrufen. '
                . 'Bitte nehmen Sie mit uns Kontakt auf.',
            self::REASON_DOMAIN => sprintf(
                'Der Subscription-Key gilt nicht für die Domain "%s", sondern für: %s.',
                $this->host,
                $this->token?->getDomainList() ?: '(keine Domain hinterlegt)',
            ),
            self::REASON_UNSUPPORTED => 'Der Subscription-Key kann nicht geprüft werden, '
                . 'weil die PHP-Erweiterung "sodium" nicht verfügbar ist.',
            default => 'Die Subscription ist ungültig.',
        };
    }
}

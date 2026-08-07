<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Subscription;

/**
 * Which licence problems look like manipulation rather than an ordinary lapse.
 *
 * Two things this deliberately does not do. It does not treat a missing or
 * expired key as suspicious — those are the everyday states of a customer who
 * has not renewed yet, and reporting them would drown the real findings and
 * accuse honest people. And it makes no attempt to be unremovable: anyone
 * willing to edit this file can delete the reporting, which is why the
 * authoritative detection runs on the issuing server, on the status check every
 * installation performs itself.
 *
 * Pure, so every rule can be exercised without a network or a database.
 */
final class TamperDetection
{
    /** The key was edited, or it was not issued by us. */
    public const REASON_FORGED_SIGNATURE = 'forgedSignature';
    /** A key is in use on a domain it was not issued for. */
    public const REASON_FOREIGN_HOST = 'foreignHost';
    /** Verification runs against a key pair that is not the bundled one. */
    public const REASON_FOREIGN_VERIFICATION_KEY = 'foreignVerificationKey';
    /** The keys shipped with the extension were altered. */
    public const REASON_ALTERED_EXTENSION = 'alteredExtension';

    /**
     * Fingerprint of the keys this extension ships with.
     *
     * Not a security boundary — someone editing the constants can edit this line
     * too. It catches the careless case and makes the deliberate one an
     * unmistakable act rather than a quick edit.
     */
    public const BUNDLED_KEY_FINGERPRINT = '55ed3f1f627c7a234e3bc07c42545458c90ef18cbf5538a6465ff812b1b3c9bd';

    /**
     * The finding behind a rejected subscription, or null when it is an honest
     * state such as "no key yet" or "expired".
     */
    public static function fromStatus(SubscriptionStatus $status): ?string
    {
        return match ($status->reason) {
            SubscriptionStatus::REASON_SIGNATURE => self::REASON_FORGED_SIGNATURE,
            SubscriptionStatus::REASON_DOMAIN => self::REASON_FOREIGN_HOST,
            default => null,
        };
    }

    /**
     * Whether the bundled keys are still the ones that were shipped.
     */
    public static function isExtensionIntact(): bool
    {
        return hash_equals(self::BUNDLED_KEY_FINGERPRINT, self::fingerprint());
    }

    /**
     * Whether verification uses a key pair other than the bundled one.
     *
     * Legitimate after a key rollover, which is why it is reported as an
     * observation and judged on the server, where it is known whether one
     * happened.
     */
    public static function usesForeignVerificationKey(string $configuredPublicKey): bool
    {
        $configured = strtolower(trim($configuredPublicKey));

        return $configured !== '' && $configured !== strtolower(SubscriptionKeyCodec::DEFAULT_PUBLIC_KEY);
    }

    /**
     * The fingerprint of the currently bundled keys.
     */
    public static function fingerprint(): string
    {
        return hash(
            'sha256',
            strtolower(SubscriptionKeyCodec::DEFAULT_PUBLIC_KEY) . '|' . strtolower(SubscriptionKeyCodec::CIPHER_KEY)
        );
    }
}

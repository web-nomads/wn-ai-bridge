<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Subscription;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Subscription\SubscriptionKeyCodec;
use WebNomads\WnAiBridge\Subscription\SubscriptionStatus;
use WebNomads\WnAiBridge\Subscription\SubscriptionToken;
use WebNomads\WnAiBridge\Subscription\TamperDetection;
use WebNomads\WnAiBridge\Subscription\TamperReporter;

/**
 * What counts as manipulation, and — just as important — what does not.
 *
 * A false accusation lands on a paying customer, so the everyday states of an
 * unlicensed installation must never be reported.
 */
final class TamperDetectionTest extends TestCase
{
    #[Test]
    public function anEditedOrForeignKeyIsAFinding(): void
    {
        self::assertSame(
            TamperDetection::REASON_FORGED_SIGNATURE,
            TamperDetection::fromStatus($this->rejected(SubscriptionStatus::REASON_SIGNATURE)),
        );
    }

    #[Test]
    public function aKeyUsedOnAnotherDomainIsAFinding(): void
    {
        self::assertSame(
            TamperDetection::REASON_FOREIGN_HOST,
            TamperDetection::fromStatus($this->rejected(SubscriptionStatus::REASON_DOMAIN)),
        );
    }

    #[Test]
    public function theEverydayStatesAreNeverReported(): void
    {
        // No key yet, an expired one, a mangled copy-paste, a PHP without
        // sodium: all of these happen to honest customers.
        foreach ([
            SubscriptionStatus::REASON_MISSING,
            SubscriptionStatus::REASON_EXPIRED,
            SubscriptionStatus::REASON_MALFORMED,
            SubscriptionStatus::REASON_PAYLOAD,
            SubscriptionStatus::REASON_UNSUPPORTED,
            SubscriptionStatus::REASON_REVOKED,
        ] as $reason) {
            self::assertNull(
                TamperDetection::fromStatus($this->rejected($reason)),
                sprintf('"%s" is an ordinary state and must not be reported.', $reason),
            );
        }
    }

    #[Test]
    public function aValidSubscriptionIsNeverReported(): void
    {
        $token = new SubscriptionToken('sub_abc', 'Acme', '', ['example.com'], 0, 0, []);

        self::assertNull(TamperDetection::fromStatus(SubscriptionStatus::valid($token, 'example.com')));
    }

    #[Test]
    public function theBundledKeysMatchTheirFingerprint(): void
    {
        // Guards against the constants and the fingerprint drifting apart during
        // a key rollover, which would make every installation report itself.
        self::assertTrue(TamperDetection::isExtensionIntact());
        self::assertSame(TamperDetection::BUNDLED_KEY_FINGERPRINT, TamperDetection::fingerprint());
    }

    #[Test]
    public function theFingerprintCoversBothBundledKeys(): void
    {
        $expected = hash(
            'sha256',
            strtolower(SubscriptionKeyCodec::DEFAULT_PUBLIC_KEY) . '|' . strtolower(SubscriptionKeyCodec::CIPHER_KEY)
        );

        self::assertSame($expected, TamperDetection::fingerprint());
    }

    #[Test]
    public function anOverrideMatchingTheBundledKeyIsNotForeign(): void
    {
        self::assertFalse(TamperDetection::usesForeignVerificationKey(''));
        self::assertFalse(TamperDetection::usesForeignVerificationKey(SubscriptionKeyCodec::DEFAULT_PUBLIC_KEY));
        self::assertFalse(
            TamperDetection::usesForeignVerificationKey(strtoupper(SubscriptionKeyCodec::DEFAULT_PUBLIC_KEY))
        );
    }

    #[Test]
    public function averificationKeyOfSomeoneElseIsAFinding(): void
    {
        self::assertTrue(TamperDetection::usesForeignVerificationKey(str_repeat('ab', 32)));
    }

    #[Test]
    public function theReportCarriesNothingAboutTheSiteOrItsVisitors(): void
    {
        $payload = TamperReporter::buildPayload(
            TamperDetection::REASON_FORGED_SIGNATURE,
            'sub_abc',
            'kunde.example.com'
        );

        self::assertSame(
            ['reason', 'id', 'host', 'extensionVersion', 'typo3Version'],
            array_keys($payload),
        );
        self::assertSame(TamperDetection::REASON_FORGED_SIGNATURE, $payload['reason']);
        self::assertSame('sub_abc', $payload['id']);
        self::assertSame('kunde.example.com', $payload['host']);
    }

    #[Test]
    public function eachFindingIsThrottledOnItsOwn(): void
    {
        $one = TamperReporter::throttleKey('a', 'sub_1', 'host_1');

        self::assertSame($one, TamperReporter::throttleKey('a', 'sub_1', 'host_1'));
        self::assertNotSame($one, TamperReporter::throttleKey('b', 'sub_1', 'host_1'));
        self::assertNotSame($one, TamperReporter::throttleKey('a', 'sub_2', 'host_1'));
        self::assertNotSame($one, TamperReporter::throttleKey('a', 'sub_1', 'host_2'));
    }

    private function rejected(string $reason): SubscriptionStatus
    {
        return SubscriptionStatus::invalid($reason, null, 'example.com');
    }
}

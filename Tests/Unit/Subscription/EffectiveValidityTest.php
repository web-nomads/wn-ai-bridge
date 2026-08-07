<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Subscription;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Subscription\OnlineCheckResult;
use WebNomads\WnAiBridge\Subscription\SubscriptionService;
use WebNomads\WnAiBridge\Subscription\SubscriptionToken;

/**
 * How a renewal reaches this installation.
 *
 * The key in the extension configuration never changes on its own, so its expiry
 * date goes stale the moment a subscription is renewed. The signed answer of the
 * daily status check carries the current date, and these are the rules for
 * choosing between the two.
 */
final class EffectiveValidityTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const DAY = 86400;

    #[Test]
    public function aConfirmedRenewalKeepsAnExpiredKeyAlive(): void
    {
        // The exact case: the term was renewed on the server three days ago, the
        // customer never pasted the new key, and the old one has run out.
        $token = $this->token(self::NOW - self::DAY);
        $verdict = OnlineCheckResult::active(self::NOW + (365 * self::DAY), self::NOW);

        self::assertFalse(SubscriptionService::isExpired($token, $verdict, self::NOW));
        self::assertSame(
            self::NOW + (365 * self::DAY),
            SubscriptionService::effectiveValidUntil($token, $verdict)
        );
    }

    #[Test]
    public function aLapsedSubscriptionIsSwitchedOffDespiteAGoodKey(): void
    {
        // The key still carries its grace period, but the server says the term
        // ended — the server wins, so the grace is not free usage.
        $token = $this->token(self::NOW + (20 * self::DAY));
        $verdict = OnlineCheckResult::active(self::NOW - self::DAY, self::NOW);

        self::assertTrue(SubscriptionService::isExpired($token, $verdict, self::NOW));
    }

    #[Test]
    public function withoutAnAnswerTheKeyDecides(): void
    {
        $unknown = OnlineCheckResult::unknown(self::NOW);

        self::assertFalse(SubscriptionService::isExpired($this->token(self::NOW + self::DAY), $unknown, self::NOW));
        self::assertTrue(SubscriptionService::isExpired($this->token(self::NOW - self::DAY), $unknown, self::NOW));
    }

    #[Test]
    public function anUnreachableServerCanNeverExtendASubscription(): void
    {
        $expiredToken = $this->token(self::NOW - self::DAY);

        self::assertSame(
            $expiredToken->expiresAt,
            SubscriptionService::effectiveValidUntil($expiredToken, OnlineCheckResult::unknown(self::NOW))
        );
    }

    #[Test]
    public function aKeyWithoutAnExpiryDateNeverExpires(): void
    {
        self::assertFalse(
            SubscriptionService::isExpired($this->token(0), OnlineCheckResult::unknown(self::NOW), self::NOW)
        );
    }

    #[Test]
    public function theEndDateIsInclusiveOfItsLastSecond(): void
    {
        $token = $this->token(self::NOW);
        $unknown = OnlineCheckResult::unknown(self::NOW);

        self::assertFalse(SubscriptionService::isExpired($token, $unknown, self::NOW));
        self::assertTrue(SubscriptionService::isExpired($token, $unknown, self::NOW + 1));
    }

    #[Test]
    public function theRefreshWindowOpensBeforeTheKeyRunsOut(): void
    {
        $window = SubscriptionService::FRONTEND_REFRESH_WINDOW;

        // Far from the end: no reason to bother the server from a page view.
        self::assertFalse(
            $this->token(self::NOW + $window + self::DAY)->isExpiringWithin($window, self::NOW)
        );
        // Inside the window, and once past the end date, it is worth asking.
        self::assertTrue($this->token(self::NOW + $window - self::DAY)->isExpiringWithin($window, self::NOW));
        self::assertTrue($this->token(self::NOW - self::DAY)->isExpiringWithin($window, self::NOW));
        // A key without an end date has nothing to refresh for.
        self::assertFalse($this->token(0)->isExpiringWithin($window, self::NOW));
    }

    private function token(int $expiresAt): SubscriptionToken
    {
        return new SubscriptionToken(
            'sub_abc',
            'Acme AG',
            'info@example.com',
            ['example.com'],
            self::NOW - (365 * self::DAY),
            $expiresAt,
            [],
            'https://issuer.example.com',
        );
    }
}

<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Subscription;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Subscription\SubscriptionStatus;
use WebNomads\WnAiBridge\Subscription\SubscriptionToken;

/**
 * The trial marker a key can carry, and what the backend makes of it.
 *
 * A trial ends on its date and nothing renews it, so saying "active until" in
 * the same words as for a bought subscription would set the wrong expectation —
 * which is the whole point of the marker travelling inside the key.
 */
final class TrialMarkerTest extends TestCase
{
    private const NOW = 1_800_000_000;

    #[Test]
    public function aKeyWithoutTheMarkerIsAnOrdinarySubscription(): void
    {
        // Every key issued before trials existed, and every paid one since.
        $token = SubscriptionToken::fromPayload([
            'id' => 'sub_1',
            'domains' => ['example.com'],
            'exp' => self::NOW,
        ]);

        self::assertFalse($token->trial);
        self::assertFalse($token->isTrial());
    }

    #[Test]
    public function theMarkerIsReadFromThePayload(): void
    {
        $token = SubscriptionToken::fromPayload([
            'id' => 'sub_1',
            'domains' => ['example.com'],
            'exp' => self::NOW,
            'trial' => true,
        ]);

        self::assertTrue($token->trial);
    }

    #[Test]
    public function anActiveTrialSaysSoAndSaysItDoesNotRenew(): void
    {
        $status = SubscriptionStatus::valid($this->trialToken(), 'example.com', self::NOW);

        self::assertTrue($status->isTrial());
        self::assertStringContainsString('Trial subscription active', $status->getMessage());
        self::assertStringContainsString('does not renew', $status->getMessage());
    }

    #[Test]
    public function anExpiredTrialPointsAtOrderingRatherThanRenewing(): void
    {
        // "Please renew it" is the wrong instruction here: there is nothing to
        // renew, and the customer has to place an order instead.
        $status = SubscriptionStatus::invalid(
            SubscriptionStatus::REASON_EXPIRED,
            $this->trialToken(),
            'example.com',
        );

        self::assertStringContainsString('The trial ended on', $status->getMessage());
        self::assertStringNotContainsString('renew it', $status->getMessage());
    }

    #[Test]
    public function anOrdinarySubscriptionKeepsItsWording(): void
    {
        $token = new SubscriptionToken('sub_1', 'Acme', '', ['example.com'], 0, self::NOW, []);
        $status = SubscriptionStatus::valid($token, 'example.com', self::NOW);

        self::assertFalse($status->isTrial());
        self::assertStringStartsWith('Subscription active for', $status->getMessage());
    }

    #[Test]
    public function aStatusWithoutATokenIsNotATrial(): void
    {
        // No key at all: nothing was decoded, so there is nothing that could say
        // it is a trial.
        $status = SubscriptionStatus::invalid(SubscriptionStatus::REASON_MISSING);

        self::assertFalse($status->isTrial());
    }

    private function trialToken(): SubscriptionToken
    {
        return new SubscriptionToken(
            'sub_1',
            'Acme',
            'info@example.com',
            ['example.com'],
            0,
            self::NOW,
            [],
            '',
            true,
        );
    }
}

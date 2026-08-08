<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Subscription;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Subscription\OnlineCheckResult;
use WebNomads\WnAiBridge\Subscription\SubscriptionStatus;
use WebNomads\WnAiBridge\Subscription\SubscriptionToken;

/**
 * When the backend modules report a failing issuing server.
 *
 * It is an error, not a footnote: the licence has lost contact with its server,
 * so no renewal and no revocation can arrive. But it is only shown for a valid
 * key — without one there is nothing to renew, and the missing key is the
 * message that matters.
 */
final class OnlineCheckReportingTest extends TestCase
{
    private const NOW = 1_800_000_000;

    #[Test]
    #[DataProvider('failures')]
    public function aValidKeyReportsEveryKindOfFailure(string $reason, string $expectedFragment): void
    {
        $status = $this->validStatus(OnlineCheckResult::unknown(self::NOW, $reason));

        self::assertTrue($status->hasOnlineCheckFailed());
        self::assertStringContainsString($expectedFragment, $status->getOnlineCheckMessage());
        // The consequence is always spelled out — that is the point of showing it.
        self::assertStringContainsString('renewal', $status->getOnlineCheckMessage());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function failures(): array
    {
        return [
            'unreachable' => [OnlineCheckResult::FAILURE_UNREACHABLE, 'could not be reached'],
            'http error' => [OnlineCheckResult::FAILURE_HTTP, 'answered with an error'],
            'unverifiable' => [OnlineCheckResult::FAILURE_INVALID, 'could not be verified'],
        ];
    }

    #[Test]
    public function anInvalidKeyStaysSilentAboutItsServer(): void
    {
        $failed = OnlineCheckResult::unknown(self::NOW, OnlineCheckResult::FAILURE_UNREACHABLE);

        foreach ([SubscriptionStatus::REASON_EXPIRED, SubscriptionStatus::REASON_DOMAIN] as $reason) {
            $status = SubscriptionStatus::invalid($reason, $this->token(), 'example.com', 0, $failed);

            self::assertFalse($status->hasOnlineCheckFailed(), $reason . ' should not add a server error');
            self::assertSame('', $status->getOnlineCheckMessage());
        }
    }

    #[Test]
    public function noKeyAtAllStaysSilent(): void
    {
        $status = SubscriptionStatus::invalid(SubscriptionStatus::REASON_MISSING);

        self::assertFalse($status->hasOnlineCheckFailed());
        self::assertSame('', $status->getOnlineCheckMessage());
    }

    #[Test]
    public function aWorkingCheckSaysNothing(): void
    {
        $status = $this->validStatus(OnlineCheckResult::active(self::NOW + 86400, self::NOW));

        self::assertFalse($status->hasOnlineCheckFailed());
        self::assertSame('', $status->getOnlineCheckMessage());
    }

    #[Test]
    public function notHavingAskedYetSaysNothing(): void
    {
        // Every fresh installation is in this state; a warning here would be noise.
        $status = $this->validStatus(OnlineCheckResult::unknown(self::NOW));

        self::assertFalse($status->hasOnlineCheckFailed());
    }

    private function validStatus(OnlineCheckResult $verdict): SubscriptionStatus
    {
        return SubscriptionStatus::valid($this->token(), 'example.com', self::NOW + 86400, $verdict);
    }

    private function token(): SubscriptionToken
    {
        return new SubscriptionToken(
            'sub_abc',
            'Acme AG',
            'info@example.com',
            ['example.com'],
            self::NOW,
            self::NOW + 86400,
            [],
            'https://issuer.example.com',
        );
    }
}

<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Backend;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Module\BeforeModuleCreationEvent;
use TYPO3\CMS\Core\Information\Typo3Version;
use WebNomads\WnAiBridge\Backend\EventListener\SubscriptionModuleGuard;
use WebNomads\WnAiBridge\Subscription\SubscriptionService;
use WebNomads\WnAiBridge\Subscription\SubscriptionStatus;
use WebNomads\WnAiBridge\Subscription\SubscriptionToken;

/**
 * How the subscription-only modules are taken away, per TYPO3 version.
 *
 * v14 has module access gates, v13 does not — and reaching for the gate on v13
 * used to take the whole backend down with "Interface
 * ModuleAccessGateInterface not found", because the class implementing it was
 * loaded anyway.
 */
final class SubscriptionModuleGuardTest extends TestCase
{
    private const MODULE_CONFIGURATION = [
        'parent' => 'wn_ai_bridge',
        'access' => 'user',
        'workspaces' => 'live',
    ];

    #[Test]
    public function theGuardNeverLoadsTheV14OnlyGate(): void
    {
        // The regression this file exists for: the class implements an interface
        // that only ships with v14, so loading it on v13 is a fatal error. The
        // guard has to work off its own constant.
        $event = $this->guard(13, hasFeature: false)('wn_ai_bridge_enquiries');

        self::assertFalse(
            class_exists(\WebNomads\WnAiBridge\Backend\SubscriptionRequiredGate::class, false),
            'The module guard pulled in the v14-only access gate.'
        );
        self::assertSame('user', $event->getConfigurationValue('access'));
    }

    #[Test]
    public function onV14TheModuleIsHandedToTheAccessGate(): void
    {
        $event = $this->guard(14, hasFeature: false)('wn_ai_bridge_enquiries');

        self::assertSame(
            SubscriptionModuleGuard::ACCESS_IDENTIFIER,
            $event->getConfigurationValue('access')
        );
        self::assertNull($event->getConfigurationValue('appearance'));
    }

    #[Test]
    public function onV13TheModuleIsDroppedFromTheModuleMenu(): void
    {
        $event = $this->guard(13, hasFeature: false)('wn_ai_bridge_answers');

        self::assertSame(
            ['renderInModuleMenu' => false],
            $event->getConfigurationValue('appearance')
        );
        // Untouched: the access value decides backend group permissions, and
        // those have to survive the wait for a key.
        self::assertSame('user', $event->getConfigurationValue('access'));
    }

    #[Test]
    public function anExistingAppearanceIsKept(): void
    {
        $event = $this->guard(13, hasFeature: false)(
            'wn_ai_bridge_enquiries',
            ['appearance' => ['renderInModuleMenu' => true, 'foo' => 'bar']]
        );

        self::assertSame(
            ['renderInModuleMenu' => false, 'foo' => 'bar'],
            $event->getConfigurationValue('appearance')
        );
    }

    #[Test]
    public function aValidSubscriptionLeavesTheModuleAlone(): void
    {
        foreach ([13, 14] as $majorVersion) {
            $event = $this->guard($majorVersion, hasFeature: true)('wn_ai_bridge_enquiries');

            self::assertSame(self::MODULE_CONFIGURATION, $event->getConfiguration());
        }
    }

    #[Test]
    public function modulesWithoutASubscriptionFeatureAreLeftAlone(): void
    {
        // The bot access log is part of the free feature set.
        foreach ([13, 14] as $majorVersion) {
            $event = $this->guard($majorVersion, hasFeature: false)('wn_ai_bridge_botaccess');

            self::assertSame(self::MODULE_CONFIGURATION, $event->getConfiguration());
        }
    }

    /**
     * A guard for the given TYPO3 version, returned as the callable the tests
     * use: module identifier and extra configuration in, dispatched event out.
     *
     * @return \Closure(string, array<string, mixed>=): BeforeModuleCreationEvent
     */
    private function guard(int $majorVersion, bool $hasFeature): \Closure
    {
        $typo3Version = $this->createMock(Typo3Version::class);
        $typo3Version->method('getMajorVersion')->willReturn($majorVersion);

        $guard = new SubscriptionModuleGuard($this->subscriptionService($hasFeature), $typo3Version);

        return static function (string $identifier, array $configuration = []) use ($guard): BeforeModuleCreationEvent {
            $event = new BeforeModuleCreationEvent(
                $identifier,
                array_merge(self::MODULE_CONFIGURATION, $configuration)
            );
            $guard($event);

            return $event;
        };
    }

    /**
     * A subscription service with a fixed outcome. The class is final and its
     * dependencies reach out to the issuing server, so the resolved status is
     * placed into it directly — everything the guard asks for is served from
     * there.
     */
    private function subscriptionService(bool $hasFeature): SubscriptionService
    {
        $status = $hasFeature
            ? SubscriptionStatus::valid(
                SubscriptionToken::fromPayload([
                    'id' => 'sub_1',
                    'domains' => ['example.com'],
                    'features' => [SubscriptionService::FEATURE_LOG, SubscriptionService::FEATURE_CORRECTIONS],
                ]),
                'example.com'
            )
            : SubscriptionStatus::invalid(SubscriptionStatus::REASON_MISSING);

        $reflection = new \ReflectionClass(SubscriptionService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('status')->setValue($service, $status);

        return $service;
    }
}

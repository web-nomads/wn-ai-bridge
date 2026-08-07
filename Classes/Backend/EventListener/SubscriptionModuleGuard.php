<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Backend\EventListener;

use TYPO3\CMS\Backend\Module\BeforeModuleCreationEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use WebNomads\WnAiBridge\Backend\SubscriptionRequiredGate;
use WebNomads\WnAiBridge\Subscription\SubscriptionService;

/**
 * Hides the subscription-only backend modules while no valid subscription key is
 * configured.
 *
 * The modules stay registered with their regular "user" access and are only
 * re-flagged as inaccessible at creation time, so nothing about backend group
 * permissions changes once the key is in place — the modules simply reappear.
 */
final class SubscriptionModuleGuard
{
    /**
     * Module identifier => the feature its subscription key has to include.
     */
    private const GUARDED_MODULES = [
        'wn_ai_bridge_enquiries' => SubscriptionService::FEATURE_LOG,
        'wn_ai_bridge_answers' => SubscriptionService::FEATURE_CORRECTIONS,
    ];

    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {}

    #[AsEventListener('wn-ai-bridge/subscription-module-guard')]
    public function __invoke(BeforeModuleCreationEvent $event): void
    {
        $feature = self::GUARDED_MODULES[$event->getIdentifier()] ?? null;
        if ($feature === null) {
            return;
        }

        if (!$this->subscriptionService->hasFeature($feature)) {
            $event->setConfigurationValue('access', SubscriptionRequiredGate::IDENTIFIER);
        }
    }
}

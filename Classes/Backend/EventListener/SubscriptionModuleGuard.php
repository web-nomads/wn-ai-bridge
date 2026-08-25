<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Backend\EventListener;

use TYPO3\CMS\Backend\Module\BeforeModuleCreationEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Information\Typo3Version;
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
     * Access identifier of {@see \WebNomads\WnAiBridge\Backend\SubscriptionRequiredGate}.
     *
     * It is declared here rather than on the gate because the gate exists only
     * on TYPO3 v14: reading a constant off that class would load it, and with it
     * the v14-only interface it implements.
     */
    public const ACCESS_IDENTIFIER = 'wnAiBridgeSubscriptionRequired';

    /**
     * Module identifier => the feature its subscription key has to include.
     */
    private const GUARDED_MODULES = [
        'wn_ai_bridge_enquiries' => SubscriptionService::FEATURE_LOG,
        'wn_ai_bridge_answers' => SubscriptionService::FEATURE_CORRECTIONS,
    ];

    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly Typo3Version $typo3Version,
    ) {}

    #[AsEventListener('wn-ai-bridge/subscription-module-guard')]
    public function __invoke(BeforeModuleCreationEvent $event): void
    {
        $feature = self::GUARDED_MODULES[$event->getIdentifier()] ?? null;
        if ($feature === null) {
            return;
        }

        if ($this->subscriptionService->hasFeature($feature)) {
            return;
        }

        if ($this->typo3Version->getMajorVersion() >= 14) {
            // Module access gates take the module out of the menu and block its
            // routes in one go, admins included.
            $event->setConfigurationValue('access', self::ACCESS_IDENTIFIER);

            return;
        }

        // v13 has no access gates, and an "access" value it does not know still
        // lets every admin through — so the module menu is where the module is
        // dropped. The routes stay reachable through a bookmark or the live
        // search, where the controllers answer with the "subscription required"
        // screen instead of the module.
        $appearance = $event->getConfigurationValue('appearance', []);
        $appearance = is_array($appearance) ? $appearance : [];
        $appearance['renderInModuleMenu'] = false;
        $event->setConfigurationValue('appearance', $appearance);
    }
}

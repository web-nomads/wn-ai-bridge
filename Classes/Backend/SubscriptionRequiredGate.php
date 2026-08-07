<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Backend;

use TYPO3\CMS\Backend\Module\ModuleAccessGateInterface;
use TYPO3\CMS\Backend\Module\ModuleAccessResult;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Core\Attribute\AsModuleAccessGate;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Denies access to every module whose access is set to
 * "wnAiBridgeSubscriptionRequired".
 *
 * {@see EventListener\SubscriptionModuleGuard} assigns that access value to the
 * subscription-only modules while no valid key is configured, which removes them
 * from the module menu and blocks their routes for everyone, including admins.
 * As soon as the subscription is valid the modules keep their regular
 * "user" access, so they can be assigned to backend groups as usual.
 */
#[AsModuleAccessGate(identifier: 'wnAiBridgeSubscriptionRequired')]
final readonly class SubscriptionRequiredGate implements ModuleAccessGateInterface
{
    public const IDENTIFIER = 'wnAiBridgeSubscriptionRequired';

    public function decide(ModuleInterface $module, BackendUserAuthentication $user): ModuleAccessResult
    {
        if ($module->getAccess() !== self::IDENTIFIER) {
            return ModuleAccessResult::Abstain;
        }

        return ModuleAccessResult::Denied;
    }
}

<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Backend;

use TYPO3\CMS\Backend\Module\ModuleAccessGateInterface;
use TYPO3\CMS\Backend\Module\ModuleAccessResult;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Core\Attribute\AsModuleAccessGate;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use WebNomads\WnAiBridge\Backend\EventListener\SubscriptionModuleGuard;

/**
 * Denies access to every module whose access is set to
 * "wnAiBridgeSubscriptionRequired".
 *
 * {@see SubscriptionModuleGuard} assigns that access value to the
 * subscription-only modules while no valid key is configured, which removes them
 * from the module menu and blocks their routes for everyone, including admins.
 * As soon as the subscription is valid the modules keep their regular
 * "user" access, so they can be assigned to backend groups as usual.
 *
 * TYPO3 v14 only — module access gates do not exist on v13, where the guard
 * hides the modules from the module menu instead. The class is therefore kept
 * out of the service container on v13 (see Configuration/Services.php and the
 * exclude in Configuration/Services.yaml), because merely reflecting on it would
 * fail over the interface it implements.
 */
#[AsModuleAccessGate(identifier: SubscriptionModuleGuard::ACCESS_IDENTIFIER)]
final readonly class SubscriptionRequiredGate implements ModuleAccessGateInterface
{
    public function decide(ModuleInterface $module, BackendUserAuthentication $user): ModuleAccessResult
    {
        if ($module->getAccess() !== SubscriptionModuleGuard::ACCESS_IDENTIFIER) {
            return ModuleAccessResult::Abstain;
        }

        return ModuleAccessResult::Denied;
    }
}

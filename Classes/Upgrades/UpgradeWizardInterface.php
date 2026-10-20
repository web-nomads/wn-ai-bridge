<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Upgrades;

// EXT:core since v14, EXT:install interface deprecated there and removed in v15
if (interface_exists(\TYPO3\CMS\Core\Upgrades\UpgradeWizardInterface::class)) {
    interface UpgradeWizardInterface extends \TYPO3\CMS\Core\Upgrades\UpgradeWizardInterface {}
} else {
    interface UpgradeWizardInterface extends \TYPO3\CMS\Install\Updates\UpgradeWizardInterface {}
}

<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Core\Information\Typo3Version;
use WebNomads\WnAiBridge\Backend\SubscriptionRequiredGate;
use WebNomads\WnAiBridge\Configuration\ClassicLibraries;
use WebNomads\WnAiBridge\Upgrades\AimProviderConfigurationUpdate;
use WebNomads\WnAiBridge\Upgrades\AssistantColorSchemeUpdate;
use WebNomads\WnAiBridge\Upgrades\AssistantSettingsToSiteConfigurationUpdate;
use WebNomads\WnAiBridge\Upgrades\BotAccessLogMigrationUpdate;

/**
 * Services that only exist on one TYPO3 version, next to the version-independent
 * ones in Services.yaml.
 *
 * The `use` statements above are aliases and `::class` a compile-time constant,
 * so neither loads the gate on v13 — which is the point of the whole file.
 */

// classic mode: loaded while the container is built, so aim's compiler pass finds the bundled AI bridge
require_once dirname(__DIR__) . '/Classes/Configuration/ClassicLibraries.php';
ClassicLibraries::load();

return static function (ContainerConfigurator $configurator): void {
    // The upgrade wizards are tagged by hand rather than through the
    // #[UpgradeWizard] attribute, because that attribute is in a different
    // namespace on each version: TYPO3\CMS\Install\Attribute on v13,
    // TYPO3\CMS\Core\Attribute on v14. An attribute PHP cannot resolve is not an
    // error — it is simply ignored, so naming the wrong one leaves the wizard
    // unregistered and says nothing about it. The tag itself is the same on both.
    $services = $configurator->services();
    // identifiers as literals: $class::IDENTIFIER autoloads, and the extension
    // is not autoloadable yet while it is being installed in classic mode
    $wizards = [
        'wnAiBridgeAssistantSettingsToSiteConfiguration' => AssistantSettingsToSiteConfigurationUpdate::class,
        'wnAiBridgeAimProviderConfiguration' => AimProviderConfigurationUpdate::class,
        'wnAiBridgeAssistantColorScheme' => AssistantColorSchemeUpdate::class,
        'wnAiBridgeBotAccessLogMigration' => BotAccessLogMigrationUpdate::class,
    ];
    foreach ($wizards as $identifier => $wizard) {
        $services
            ->set($wizard)
            ->autowire()
            ->tag('install.upgradewizard', ['identifier' => $identifier]);
    }

    if ((new Typo3Version())->getMajorVersion() < 14) {
        // Module access gates arrived with v14. On v13 the module guard hides
        // the subscription-only modules from the module menu instead.
        return;
    }

    $services
        ->set(SubscriptionRequiredGate::class)
        ->autowire()
        // Required: the core compiler pass skips gates that are not
        // autoconfigured, and it is what turns #[AsModuleAccessGate] into a tag.
        ->autoconfigure();
};

<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Core\Information\Typo3Version;
use WebNomads\WnAiBridge\Backend\SubscriptionRequiredGate;

/**
 * Services that only exist on one TYPO3 version, next to the version-independent
 * ones in Services.yaml.
 *
 * The `use` statement above is an alias and `::class` a compile-time constant, so
 * neither loads the gate on v13 — which is the point of the whole file.
 */
return static function (ContainerConfigurator $configurator): void {
    if ((new Typo3Version())->getMajorVersion() < 14) {
        // Module access gates arrived with v14. On v13 the module guard hides
        // the subscription-only modules from the module menu instead.
        return;
    }

    $configurator->services()
        ->set(SubscriptionRequiredGate::class)
        ->autowire()
        // Required: the core compiler pass skips gates that are not
        // autoconfigured, and it is what turns #[AsModuleAccessGate] into a tag.
        ->autoconfigure();
};

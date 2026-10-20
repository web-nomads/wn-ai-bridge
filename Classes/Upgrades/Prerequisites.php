<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Upgrades;

/**
 * Upgrade wizard prerequisites of the running TYPO3 version.
 */
final class Prerequisites
{
    /**
     * @return class-string
     */
    public static function databaseUpdated(): string
    {
        // EXT:core since v14, EXT:install class deprecated there and removed in v15
        return class_exists(\TYPO3\CMS\Core\Upgrades\DatabaseUpdatedPrerequisite::class)
            ? \TYPO3\CMS\Core\Upgrades\DatabaseUpdatedPrerequisite::class
            : \TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite::class;
    }
}

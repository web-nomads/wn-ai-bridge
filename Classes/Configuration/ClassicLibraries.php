<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Configuration;

use TYPO3\CMS\Core\Core\Environment;

/**
 * Libraries the extension brings along for classic mode (TER, ZIP): the Symfony AI Perplexity bridge
 *
 * In Composer mode Composer installs them and nothing is loaded from here.
 */
final class ClassicLibraries
{
    public const AUTOLOAD = 'Resources/Private/PHP/vendor/autoload.php';

    /**
     * Registers the bundled Composer autoloader once; true when it is active
     */
    public static function load(): bool
    {
        static $loaded = null;
        if ($loaded !== null) {
            return $loaded;
        }
        $autoload = dirname(__DIR__, 2) . '/' . self::AUTOLOAD;
        $loaded = !Environment::isComposerMode() && is_file($autoload);
        if ($loaded) {
            require_once $autoload;
        }

        return $loaded;
    }
}

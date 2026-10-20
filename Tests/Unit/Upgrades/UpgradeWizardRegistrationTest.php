<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Upgrades;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use TYPO3\CMS\Core\Information\Typo3Version;
use WebNomads\WnAiBridge\Upgrades\Prerequisites;
use WebNomads\WnAiBridge\Upgrades\UpgradeWizardInterface;

final class UpgradeWizardRegistrationTest extends TestCase
{
    /**
     * @return array<string, string> identifier => class
     */
    private static function registeredWizards(): array
    {
        $container = new ContainerBuilder();
        (new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../../Configuration')))->load('Services.php');

        $wizards = [];
        foreach ($container->findTaggedServiceIds('install.upgradewizard') as $class => $tags) {
            $wizards[(string)$tags[0]['identifier']] = $class;
        }
        return $wizards;
    }

    #[Test]
    public function everyTaggedIdentifierMatchesTheConstantOfItsWizard(): void
    {
        foreach (self::registeredWizards() as $identifier => $class) {
            self::assertSame($class::IDENTIFIER, $identifier, $class);
        }
    }

    #[Test]
    public function everyWizardInTheUpgradesFolderIsRegistered(): void
    {
        $found = [];
        foreach (glob(__DIR__ . '/../../../Classes/Upgrades/*.php') ?: [] as $file) {
            $class = 'WebNomads\\WnAiBridge\\Upgrades\\' . basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, UpgradeWizardInterface::class)) {
                $found[] = $class;
            }
        }
        $registered = array_values(self::registeredWizards());
        sort($found);
        sort($registered);

        self::assertNotEmpty($found);
        self::assertSame($found, $registered);
    }

    #[Test]
    public function wizardsImplementTheInterfaceOfTheRunningCoreVersion(): void
    {
        $expected = (new Typo3Version())->getMajorVersion() >= 14
            ? 'TYPO3\\CMS\\Core\\Upgrades\\UpgradeWizardInterface'
            : 'TYPO3\\CMS\\Install\\Updates\\UpgradeWizardInterface';

        foreach (self::registeredWizards() as $class) {
            self::assertTrue(is_subclass_of($class, $expected), $class);
        }
    }

    #[Test]
    public function databaseUpdatedPrerequisiteIsTheOneOfTheRunningCoreVersion(): void
    {
        $expected = (new Typo3Version())->getMajorVersion() >= 14
            ? 'TYPO3\\CMS\\Core\\Upgrades\\DatabaseUpdatedPrerequisite'
            : 'TYPO3\\CMS\\Install\\Updates\\DatabaseUpdatedPrerequisite';

        self::assertSame($expected, Prerequisites::databaseUpdated());
    }
}

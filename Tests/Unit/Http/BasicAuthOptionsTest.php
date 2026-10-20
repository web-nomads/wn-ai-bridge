<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Http\BasicAuthOptions;

/**
 * Basic Authentication for the requests the extension makes to its own site.
 */
final class BasicAuthOptionsTest extends TestCase
{
    private const LANGUAGE_DIR = __DIR__ . '/../../../Resources/Private/Language/';

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge']);
        parent::tearDown();
    }

    #[Test]
    public function noUserMeansNoAuthOption(): void
    {
        self::assertSame([], BasicAuthOptions::forOwnSite([]));
        self::assertSame([], BasicAuthOptions::forOwnSite(['basicAuthUsername' => '  ', 'basicAuthPassword' => 'secret']));
    }

    #[Test]
    public function userAndPasswordBecomeTheGuzzleAuthOption(): void
    {
        self::assertSame(
            ['auth' => ['staging', 'secret']],
            BasicAuthOptions::forOwnSite(['basicAuthUsername' => ' staging ', 'basicAuthPassword' => 'secret'])
        );
    }

    #[Test]
    public function thePasswordIsKeptAsEntered(): void
    {
        self::assertSame(
            ['auth' => ['staging', ' pass word ']],
            BasicAuthOptions::forOwnSite(['basicAuthUsername' => 'staging', 'basicAuthPassword' => ' pass word '])
        );
    }

    #[Test]
    public function aUserWithoutPasswordSendsAnEmptyPassword(): void
    {
        self::assertSame(['auth' => ['staging', '']], BasicAuthOptions::forOwnSite(['basicAuthUsername' => 'staging']));
    }

    #[Test]
    public function readsTheExtensionConfigurationByDefault(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] = [
            'basicAuthUsername' => 'staging',
            'basicAuthPassword' => 'secret',
        ];

        self::assertSame(['auth' => ['staging', 'secret']], BasicAuthOptions::forOwnSite());
    }

    #[Test]
    public function theSettingsAreDeclaredAndLabelledInEveryLanguage(): void
    {
        $template = (string)file_get_contents(__DIR__ . '/../../../ext_conf_template.txt');

        foreach (['basicAuthUsername', 'basicAuthPassword'] as $key) {
            self::assertMatchesRegularExpression('/^' . $key . ' =$/m', $template);

            foreach (['locallang.xlf', 'de.locallang.xlf'] as $file) {
                $xml = simplexml_load_file(self::LANGUAGE_DIR . $file);
                self::assertNotFalse($xml);
                $units = $xml->xpath('//trans-unit[@id="config.' . $key . '"]');
                self::assertCount(1, $units, $file . ' is missing config.' . $key);
                if ($file !== 'locallang.xlf') {
                    self::assertNotSame('', trim((string)$units[0]->target), $file . ' has no target for config.' . $key);
                }
            }
        }
    }
}

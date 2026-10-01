<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Upgrades;

use B13\Aim\Crypto\ApiKeyEncryption;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use WebNomads\WnAiBridge\Upgrades\AimProviderConfigurationUpdate;

/**
 * What the wizard writes into AiM is checked here; that it lands in the
 * database, encrypted and only once, in the functional test of the same name.
 */
final class AimProviderConfigurationUpdateTest extends TestCase
{
    #[Test]
    public function theRowCarriesProviderKeyModelAndConvertedPrices(): void
    {
        $row = AimProviderConfigurationUpdate::buildRow(
            [
                'assistantProvider' => 'anthropic',
                'assistantApiKey' => 'sk-ant-secret',
                'assistantModel' => 'claude-sonnet-4-5',
                'assistantUsdConversionRate' => '0.80',
                'assistantCurrency' => 'EUR',
            ],
            'aim:enc:v1:cipher',
            true,
            1700000000,
        );

        self::assertSame('anthropic', $row['ai_provider']);
        self::assertSame('aim:enc:v1:cipher', $row['api_key']);
        self::assertSame('claude-sonnet-4-5', $row['model']);
        self::assertSame(1, $row['default']);
        self::assertSame(0, $row['pid']);
        self::assertSame(1700000000, $row['crdate']);
        self::assertSame(2.4, $row['input_token_cost']);
        self::assertSame(12.0, $row['output_token_cost']);
        self::assertSame('EUR', $row['cost_currency']);
    }

    /**
     * An installation that never touched these settings ran on the defaults
     * the extension configuration shipped with, so those are what it gets.
     */
    #[Test]
    public function unsetSettingsFallBackToTheFormerDefaults(): void
    {
        $row = AimProviderConfigurationUpdate::buildRow(['assistantApiKey' => 'sk'], 'enc', false, 1);

        self::assertSame('anthropic', $row['ai_provider']);
        self::assertSame('claude-haiku-4-5', $row['model']);
        self::assertSame(0, $row['default']);
        self::assertSame(0.9, $row['input_token_cost']);
        self::assertSame(4.5, $row['output_token_cost']);
        self::assertSame('CHF', $row['cost_currency']);
    }

    #[Test]
    public function theFormerRateNameIsStillRead(): void
    {
        $row = AimProviderConfigurationUpdate::buildRow(['assistantUsdToChfRate' => '2,0'], 'enc', false, 1);

        self::assertSame(2.0, $row['input_token_cost']);
    }

    /**
     * @return array<string, array{string, float, array{0: float, 1: float}}>
     */
    public static function prices(): array
    {
        return [
            'known model' => ['claude-haiku-4-5', 1.0, [1.0, 5.0]],
            'dated model id' => ['claude-haiku-4-5-20251001', 1.0, [1.0, 5.0]],
            'converted' => ['claude-opus-4-1', 0.5, [7.5, 37.5]],
            'unknown model costs like Opus' => ['some-new-model', 1.0, [5.0, 25.0]],
        ];
    }

    /**
     * @param array{0: float, 1: float} $expected
     */
    #[DataProvider('prices')]
    #[Test]
    public function pricesAreTakenFromTheFormerTable(string $model, float $rate, array $expected): void
    {
        self::assertSame($expected, AimProviderConfigurationUpdate::pricesFor($model, $rate));
    }

    #[Test]
    public function nothingToDoWithoutTheFormerSettings(): void
    {
        self::assertFalse($this->wizard(['llmsFullTxt' => '1'])->updateNecessary());
    }

    #[Test]
    public function anyFormerSettingMakesTheWizardNecessary(): void
    {
        self::assertTrue($this->wizard(['assistantCurrency' => 'CHF'])->updateNecessary());
    }

    /**
     * Without a key there is nothing to hand over, but the settings still go:
     * the database is never touched.
     */
    #[Test]
    public function withoutAKeyOnlyTheFormerSettingsAreRemoved(): void
    {
        $written = null;
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([
            'assistantProvider' => 'anthropic',
            'assistantApiKey' => '',
            'assistantModel' => 'claude-haiku-4-5',
            'assistantUsdConversionRate' => '0.90',
            'assistantCurrency' => 'CHF',
            'assistantMaxTokens' => '1024',
        ]);
        $extensionConfiguration->method('set')->willReturnCallback(
            static function (string $extension, array $value) use (&$written): void {
                $written = $value;
            }
        );
        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->expects(self::never())->method('getConnectionForTable');

        $wizard = new AimProviderConfigurationUpdate($extensionConfiguration, $connectionPool, new ApiKeyEncryption());

        self::assertTrue($wizard->executeUpdate());
        self::assertSame(['assistantMaxTokens' => '1024'], $written);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function wizard(array $configuration): AimProviderConfigurationUpdate
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($configuration);

        return new AimProviderConfigurationUpdate(
            $extensionConfiguration,
            $this->createMock(ConnectionPool::class),
            new ApiKeyEncryption(),
        );
    }
}

<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Functional;

use B13\Aim\Crypto\ApiKeyEncryption;
use B13\Aim\Domain\Model\ProviderConfiguration;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use WebNomads\WnAiBridge\Upgrades\AimProviderConfigurationUpdate;

/**
 * The Claude API key leaves the extension configuration and arrives in AiM:
 * encrypted the way AiM reads it, once, and only then removed here.
 */
final class AimProviderConfigurationUpdateTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['b13/aim', 'web-nomads/wn-ai-bridge'];

    private const LEGACY_SETTINGS = [
        'assistantEnabled' => '1',
        'assistantProvider' => 'anthropic',
        'assistantApiKey' => 'sk-ant-api03-test-key',
        'assistantModel' => 'claude-haiku-4-5',
        'assistantMaxTokens' => '1024',
        'assistantUsdConversionRate' => '0.90',
        'assistantCurrency' => 'CHF',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        GeneralUtility::makeInstance(ExtensionConfiguration::class)->set('wn_ai_bridge', self::LEGACY_SETTINGS);
    }

    #[Test]
    public function theKeyArrivesInAimEncryptedAndReadable(): void
    {
        $wizard = $this->wizard();

        self::assertTrue($wizard->updateNecessary());
        self::assertTrue($wizard->executeUpdate());

        $rows = $this->aimRows();
        self::assertCount(1, $rows);
        self::assertStringStartsWith(ApiKeyEncryption::PREFIX_ANY, (string)$rows[0]['api_key']);
        self::assertSame('anthropic', $rows[0]['ai_provider']);
        self::assertSame('claude-haiku-4-5', $rows[0]['model']);
        self::assertSame(1, (int)$rows[0]['default']);
        self::assertSame('CHF', $rows[0]['cost_currency']);
        self::assertEqualsWithDelta(0.9, (float)$rows[0]['input_token_cost'], 0.000001);
        self::assertEqualsWithDelta(4.5, (float)$rows[0]['output_token_cost'], 0.000001);

        // decrypts to the original key, and AiM sees an enabled configuration
        self::assertSame('sk-ant-api03-test-key', (new ApiKeyEncryption())->decrypt((string)$rows[0]['api_key']));
        self::assertFalse((new ProviderConfiguration($rows[0]))->disabled);
    }

    #[Test]
    public function theFormerSettingsAreRemovedAndTheRestIsKept(): void
    {
        $this->wizard()->executeUpdate();

        $configuration = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('wn_ai_bridge');
        foreach (AimProviderConfigurationUpdate::OBSOLETE_SETTINGS as $setting) {
            self::assertArrayNotHasKey($setting, $configuration);
        }
        self::assertSame('1', $configuration['assistantEnabled']);
        self::assertSame('1024', $configuration['assistantMaxTokens']);
        self::assertFalse($this->wizard()->updateNecessary());
    }

    #[Test]
    public function aKeyAimAlreadyHoldsIsNotAddedTwice(): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(AimProviderConfigurationUpdate::TABLE)->insert(
            AimProviderConfigurationUpdate::TABLE,
            [
                'pid' => 0,
                'ai_provider' => 'anthropic',
                'title' => 'Existing',
                'default' => 1,
                'model' => 'claude-sonnet-4-5',
                'api_key' => (new ApiKeyEncryption())->encrypt('sk-ant-api03-test-key'),
            ],
        );

        self::assertTrue($this->wizard()->executeUpdate());

        $rows = $this->aimRows();
        self::assertCount(1, $rows);
        self::assertSame('Existing', $rows[0]['title']);
    }

    #[Test]
    public function anotherDefaultInAimStaysTheDefault(): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(AimProviderConfigurationUpdate::TABLE)->insert(
            AimProviderConfigurationUpdate::TABLE,
            [
                'pid' => 0,
                'ai_provider' => 'openai',
                'title' => 'OpenAI',
                'default' => 1,
                'model' => 'gpt-4o',
                'api_key' => (new ApiKeyEncryption())->encrypt('sk-proj-other'),
            ],
        );

        $this->wizard()->executeUpdate();

        $rows = $this->aimRows();
        self::assertCount(2, $rows);
        self::assertSame(0, (int)$rows[1]['default']);
        self::assertSame('anthropic', $rows[1]['ai_provider']);
    }

    private function wizard(): AimProviderConfigurationUpdate
    {
        return new AimProviderConfigurationUpdate(
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
            GeneralUtility::makeInstance(ConnectionPool::class),
            new ApiKeyEncryption(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aimRows(): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(AimProviderConfigurationUpdate::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from(AimProviderConfigurationUpdate::TABLE)
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}

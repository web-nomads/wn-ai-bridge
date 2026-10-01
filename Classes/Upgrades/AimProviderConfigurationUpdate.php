<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Upgrades;

use B13\Aim\Crypto\ApiKeyEncryption;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Moves the LLM connection of the assistant into an AiM provider configuration.
 *
 * Provider, API key and model used to be set in this extension's configuration,
 * the token prices were a table in the code, converted with a rate and labelled
 * with a currency of its own. AiM keeps all of that per provider configuration,
 * so this creates one from what was set here: the key encrypted the way AiM
 * stores it, the model, and the prices of that model in the currency the log
 * module used to show.
 *
 * A key that AiM already holds for the same provider is not added a second time.
 * The old settings are only removed once the configuration has been written, so
 * a failure leaves the key where it was.
 */
final class AimProviderConfigurationUpdate implements UpgradeWizardInterface
{
    public const IDENTIFIER = 'wnAiBridgeAimProviderConfiguration';

    public const TABLE = 'tx_aim_configuration';

    private const EXTENSION_KEY = 'wn_ai_bridge';

    private const DEFAULT_PROVIDER = 'anthropic';

    private const DEFAULT_MODEL = 'claude-haiku-4-5';

    private const DEFAULT_RATE = 0.90;

    private const DEFAULT_CURRENCY = 'CHF';

    /**
     * Settings that AiM replaces.
     *
     * @var list<string>
     */
    public const OBSOLETE_SETTINGS = [
        'assistantProvider',
        'assistantApiKey',
        'assistantModel',
        'assistantUsdConversionRate',
        'assistantUsdToChfRate',
        'assistantCurrency',
    ];

    /**
     * USD per 1M tokens as [input, output], the table the cost estimate used.
     *
     * @var array<string, array{0: float, 1: float}>
     */
    private const PRICES = [
        'claude-fable-5' => [10.0, 50.0],
        'claude-opus-4-8' => [5.0, 25.0],
        'claude-opus-4-7' => [5.0, 25.0],
        'claude-opus-4-6' => [5.0, 25.0],
        'claude-opus-4-5' => [5.0, 25.0],
        'claude-opus-4-1' => [15.0, 75.0],
        'claude-opus-4-0' => [15.0, 75.0],
        'claude-sonnet-4-6' => [3.0, 15.0],
        'claude-sonnet-4-5' => [3.0, 15.0],
        'claude-sonnet-4-0' => [3.0, 15.0],
        'claude-haiku-4-5' => [1.0, 5.0],
    ];

    /** @var array{0: float, 1: float} */
    private const FALLBACK_PRICE = [5.0, 25.0];

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly ConnectionPool $connectionPool,
        private readonly ApiKeyEncryption $encryption,
    ) {}

    public function getTitle(): string
    {
        return 'AI Bridge: move the Claude API key into an AiM provider configuration';
    }

    public function getDescription(): string
    {
        return 'The AI assistant now sends its requests through the AiM extension, which holds provider, API key, '
            . 'model and token prices in one place for every extension. This creates an AiM provider configuration '
            . 'from the API key and model set in the AI Bridge extension configuration, with the token prices of '
            . 'that model converted into the currency the "Enquiries" module used to show, and then removes those '
            . 'settings from the AI Bridge configuration. A key AiM already holds is not added twice. '
            . 'Anthropic requires the package symfony/ai-anthropic-platform.';
    }

    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class,
        ];
    }

    public function updateNecessary(): bool
    {
        $configuration = $this->extensionConfiguration();
        foreach (self::OBSOLETE_SETTINGS as $setting) {
            if (array_key_exists($setting, $configuration)) {
                return true;
            }
        }

        return false;
    }

    public function executeUpdate(): bool
    {
        $configuration = $this->extensionConfiguration();

        $apiKey = trim((string)($configuration['assistantApiKey'] ?? ''));
        if ($apiKey !== '') {
            try {
                $provider = self::provider($configuration);
                if (!$this->holdsKey($provider, $apiKey)) {
                    $this->connectionPool->getConnectionForTable(self::TABLE)->insert(
                        self::TABLE,
                        self::buildRow($configuration, $this->encryption->encrypt($apiKey), !$this->hasDefault(), time()),
                    );
                }
            } catch (\Throwable $e) {
                // key stays in the extension configuration, wizard can run again
                return false;
            }
        }

        foreach (self::OBSOLETE_SETTINGS as $setting) {
            unset($configuration[$setting]);
        }

        try {
            $this->extensionConfiguration->set(self::EXTENSION_KEY, $configuration);
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * The tx_aim_configuration row for the given legacy settings.
     *
     * @param array<string, mixed> $configuration
     * @return array<string, int|string|float>
     */
    public static function buildRow(array $configuration, string $encryptedApiKey, bool $isDefault, int $now): array
    {
        $model = trim((string)($configuration['assistantModel'] ?? ''));
        $model = $model !== '' ? $model : self::DEFAULT_MODEL;

        [$inputPrice, $outputPrice] = self::pricesFor($model, self::rate($configuration));

        return [
            'pid' => 0,
            'tstamp' => $now,
            'crdate' => $now,
            'ai_provider' => self::provider($configuration),
            'title' => 'AI Bridge assistant (' . $model . ')',
            'description' => 'Migrated from the AI Bridge (wn_ai_bridge) extension configuration.',
            'default' => $isDefault ? 1 : 0,
            'api_key' => $encryptedApiKey,
            'model' => $model,
            'input_token_cost' => $inputPrice,
            'output_token_cost' => $outputPrice,
            'cost_currency' => self::currency($configuration),
        ];
    }

    /**
     * Price per 1M tokens as [input, output], converted with the given rate.
     *
     * @return array{0: float, 1: float}
     */
    public static function pricesFor(string $model, float $rate): array
    {
        $price = self::PRICES[$model] ?? null;
        if ($price === null) {
            // dated model ids like "claude-haiku-4-5-20251001"
            foreach (self::PRICES as $known => $knownPrice) {
                if (str_starts_with($model, $known)) {
                    $price = $knownPrice;
                    break;
                }
            }
        }
        $price ??= self::FALLBACK_PRICE;

        return [round($price[0] * $rate, 6), round($price[1] * $rate, 6)];
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private static function provider(array $configuration): string
    {
        $provider = trim((string)($configuration['assistantProvider'] ?? ''));

        return $provider !== '' ? $provider : self::DEFAULT_PROVIDER;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private static function rate(array $configuration): float
    {
        $raw = trim((string)($configuration['assistantUsdConversionRate'] ?? ''));
        if ($raw === '') {
            $raw = trim((string)($configuration['assistantUsdToChfRate'] ?? ''));
        }
        $rate = (float)str_replace(',', '.', $raw);

        return $rate > 0 ? $rate : self::DEFAULT_RATE;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private static function currency(array $configuration): string
    {
        $currency = mb_substr(trim((string)($configuration['assistantCurrency'] ?? '')), 0, 10);

        return $currency !== '' ? $currency : self::DEFAULT_CURRENCY;
    }

    private function holdsKey(string $provider, string $apiKey): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $storedKeys = $queryBuilder
            ->select('api_key')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('ai_provider', $queryBuilder->createNamedParameter($provider)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchFirstColumn();

        foreach ($storedKeys as $storedKey) {
            try {
                if ($this->encryption->decrypt((string)$storedKey) === $apiKey) {
                    return true;
                }
            } catch (\Throwable $e) {
                // unreadable with the current encryption key, cannot be ours
            }
        }

        return false;
    }

    private function hasDefault(): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('default', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne() > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function extensionConfiguration(): array
    {
        try {
            $configuration = $this->extensionConfiguration->get(self::EXTENSION_KEY);
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($configuration) ? $configuration : [];
    }
}

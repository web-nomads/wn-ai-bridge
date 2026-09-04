<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Upgrades;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Renames "assistantUsdToChfRate" to "assistantUsdConversionRate" and records
 * the currency that rate was always meant for.
 *
 * The old name decided for every installation that it bills in Swiss francs,
 * and the log module printed "CHF" whatever the rate had been set to. The rate
 * is now named after what it does, and the currency beside it is a setting of
 * its own.
 *
 * The currency is set to CHF rather than left empty, because that is what the
 * figures were labelled with before — an installation that has been converting
 * to euros all along gets the same wrong label it already had, and can correct
 * it in one place. A fresh installation starts from the same default.
 */
final class AssistantCostSettingsUpdate implements UpgradeWizardInterface
{
    public const IDENTIFIER = 'wnAiBridgeAssistantCostSettings';

    private const EXTENSION_KEY = 'wn_ai_bridge';

    private const OLD_RATE = 'assistantUsdToChfRate';
    private const NEW_RATE = 'assistantUsdConversionRate';
    private const CURRENCY = 'assistantCurrency';

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function getTitle(): string
    {
        return 'AI Bridge: rename the USD conversion rate and record the currency it converts to';
    }

    public function getDescription(): string
    {
        return 'The estimated LLM cost in the "Enquiries" module was converted with a setting called '
            . '"USD to CHF rate" and always labelled "CHF". The rate is now called "Conversion rate from USD" '
            . 'and the currency it produces is a setting of its own, so an installation billing in another '
            . 'currency no longer reads the wrong label. This carries the configured rate over and sets the '
            . 'currency to CHF — which is what the figures said before. Adjust it afterwards if that is not '
            . 'what you bill in.';
    }

    public function updateNecessary(): bool
    {
        $configuration = $this->extensionConfiguration();

        return array_key_exists(self::OLD_RATE, $configuration)
            || !array_key_exists(self::CURRENCY, $configuration);
    }

    public function executeUpdate(): bool
    {
        $configuration = $this->extensionConfiguration();

        $rate = trim((string)($configuration[self::OLD_RATE] ?? ''));
        // A rate already set under the new name was a deliberate decision and
        // wins over whatever the old setting still holds.
        if ($rate !== '' && trim((string)($configuration[self::NEW_RATE] ?? '')) === '') {
            $configuration[self::NEW_RATE] = $rate;
        }
        unset($configuration[self::OLD_RATE]);

        if (trim((string)($configuration[self::CURRENCY] ?? '')) === '') {
            $configuration[self::CURRENCY] = 'CHF';
        }

        try {
            $this->extensionConfiguration->set(self::EXTENSION_KEY, $configuration);
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    public function getPrerequisites(): array
    {
        return [];
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

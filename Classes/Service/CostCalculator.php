<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Estimates the (approximate) cost of logged LLM usage.
 *
 * Model prices are quoted per 1M tokens in USD and converted with a rate and a
 * currency label taken from the extension configuration — both of which say
 * "whatever this installation bills in" rather than naming one country's money.
 * Costs are estimates: prices change over time and this table is a snapshot, so
 * the figures are meant for rough budgeting only.
 */
final class CostCalculator
{
    /**
     * USD price per 1,000,000 tokens as [input, output] for known Claude models.
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

    /**
     * Conservative fallback (Opus-tier) for unknown models.
     *
     * @var array{0: float, 1: float}
     */
    private const FALLBACK = [5.0, 25.0];

    private readonly ConfigurationService $configurationService;

    public function __construct(?ConfigurationService $configurationService = null)
    {
        $this->configurationService = $configurationService
            ?? GeneralUtility::makeInstance(ConfigurationService::class);
    }

    /**
     * Estimated cost of one interaction, in the configured currency.
     */
    public function cost(string $model, int $inputTokens, int $outputTokens): float
    {
        [$inputPrice, $outputPrice] = $this->priceFor($model);

        $usd = ($inputTokens / 1_000_000) * $inputPrice
            + ($outputTokens / 1_000_000) * $outputPrice;

        return $usd * $this->configurationService->getAssistantUsdConversionRate();
    }

    /**
     * Sum the estimated cost for per-model token totals.
     *
     * @param list<array{model: string, inputTokens: int, outputTokens: int}> $modelTotals
     */
    public function totalCost(array $modelTotals): float
    {
        $total = 0.0;
        foreach ($modelTotals as $row) {
            $total += $this->cost($row['model'], $row['inputTokens'], $row['outputTokens']);
        }
        return $total;
    }

    /**
     * Format an amount for display: the configured currency, and 4 decimals so
     * small per-turn amounts stay meaningful.
     */
    public function format(float $amount): string
    {
        return $this->configurationService->getAssistantCurrency()
            . ' ' . number_format($amount, 4, '.', "'");
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function priceFor(string $model): array
    {
        $model = trim($model);
        if ($model === '') {
            return [0.0, 0.0];
        }
        if (isset(self::PRICES[$model])) {
            return self::PRICES[$model];
        }
        // Match ignoring a trailing date/suffix, e.g. "claude-haiku-4-5-20251001".
        foreach (self::PRICES as $known => $price) {
            if (str_starts_with($model, $known)) {
                return $price;
            }
        }
        return self::FALLBACK;
    }
}

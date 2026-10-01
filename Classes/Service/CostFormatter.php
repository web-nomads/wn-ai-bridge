<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Service;

/**
 * Formats the cost AiM reported for logged answers.
 *
 * The amount is whatever AiM computed from the token prices of the provider
 * configuration that answered, in that configuration's currency. Four decimals,
 * so small per-turn amounts stay meaningful.
 */
final class CostFormatter
{
    public function format(float $amount, string $currency = ''): string
    {
        $currency = trim($currency);

        return ($currency !== '' ? $currency . ' ' : '') . number_format($amount, 4, '.', "'");
    }

    /**
     * @param array<string, float> $totals currency => amount
     */
    public function formatTotals(array $totals): string
    {
        if ($totals === []) {
            return $this->format(0.0);
        }

        ksort($totals);
        $parts = [];
        foreach ($totals as $currency => $amount) {
            $parts[] = $this->format($amount, (string)$currency);
        }

        return implode(' · ', $parts);
    }
}

<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Service\CostFormatter;

/**
 * The cost in the Enquiries module is what AiM reported, in the currency of the
 * provider configuration that answered.
 */
final class CostFormatterTest extends TestCase
{
    #[Test]
    public function anAmountIsShownWithItsCurrencyAndFourDecimals(): void
    {
        self::assertSame('CHF 0.0123', (new CostFormatter())->format(0.01234, 'CHF'));
    }

    #[Test]
    public function thousandsAreSeparatedTheSwissWay(): void
    {
        self::assertSame('USD 1\'234.5000', (new CostFormatter())->format(1234.5, 'USD'));
    }

    #[Test]
    public function anAmountWithoutCurrencyHasNoLabel(): void
    {
        self::assertSame('0.0000', (new CostFormatter())->format(0.0));
    }

    #[Test]
    public function totalsInSeveralCurrenciesAreNotAddedUp(): void
    {
        self::assertSame(
            'EUR 0.5000 · USD 1.2500',
            (new CostFormatter())->formatTotals(['USD' => 1.25, 'EUR' => 0.5]),
        );
    }

    #[Test]
    public function noTotalsIsZero(): void
    {
        self::assertSame('0.0000', (new CostFormatter())->formatTotals([]));
    }
}

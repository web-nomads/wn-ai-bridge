<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Domain\Model\LogFilter;

/**
 * Reading the "Enquiries" filter form, and echoing it back.
 *
 * The site is the one that matters here: on an installation serving several
 * websites the log is one list of everything, and every link the module builds —
 * pagination, reset, the AJAX reload — has to carry the chosen site or the list
 * silently reverts to all of them on the second page.
 */
final class LogFilterTest extends TestCase
{
    #[Test]
    public function theChosenSiteIsRead(): void
    {
        $filter = LogFilter::fromQueryParams(['site' => ' web-nomads ']);

        self::assertSame('web-nomads', $filter->site);
    }

    #[Test]
    public function noSiteMeansAllOfThem(): void
    {
        self::assertSame('', LogFilter::fromQueryParams([])->site);
        self::assertSame('', LogFilter::fromQueryParams(['site' => '   '])->site);
    }

    #[Test]
    public function theChosenSiteSurvivesIntoEveryLinkTheModuleBuilds(): void
    {
        $values = LogFilter::fromQueryParams(['site' => 'web-nomads', 'mode' => 'llm'])->toFormValues();

        self::assertSame('web-nomads', $values['site']);
        self::assertSame('llm', $values['mode']);
    }

    #[Test]
    public function theOtherCriteriaAreUnaffected(): void
    {
        $filter = LogFilter::fromQueryParams([
            'dateFrom' => '2026-01-01',
            'dateTo' => '2026-01-31',
            'ip' => '10.0.0.1',
            'provider' => 'anthropic',
            'mode' => 'llm',
            'search' => 'preise',
            'page' => '3',
        ]);

        self::assertSame('10.0.0.1', $filter->ip);
        self::assertSame('anthropic', $filter->provider);
        self::assertSame('preise', $filter->search);
        self::assertSame(3, $filter->page);
        self::assertSame(50, $filter->getOffset());
        self::assertNotNull($filter->dateFrom);
        self::assertNotNull($filter->dateTo);
    }
}

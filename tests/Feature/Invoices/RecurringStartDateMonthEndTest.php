<?php

namespace Tests\Feature\Invoices;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * "Create Recurring" pre-fills the start date from the invoice date through
 * Invoices/Ajax::get_recur_start_date(). An invoice dated 10/31 with a monthly frequency got 12/01,
 * so November had no invoice at all.
 */
#[Group('invoices')]
final class RecurringStartDateMonthEndTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $this->databaseUpdate('ip_settings', ['setting_value' => 'Y-m-d'], ['setting_key' => 'date_format']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}> invoice date, frequency, expected start date
     */
    public static function startDates(): array
    {
        return [
            'oct 31 monthly'        => ['2026-10-31', '1M', '2026-11-30'],
            'jan 31 monthly'        => ['2027-01-31', '1M', '2027-02-28'],
            'jan 30 monthly'        => ['2027-01-30', '1M', '2027-02-28'],
            'mar 31 quarterly'      => ['2027-03-31', '3M', '2027-06-30'],
            'feb 29 yearly'         => ['2028-02-29', '1Y', '2029-02-28'],
            'mid month is unchanged' => ['2026-10-15', '1M', '2026-11-15'],
            'weekly is unchanged'   => ['2026-10-31', '1W', '2026-11-07'],
        ];
    }

    #[Test]
    #[DataProvider('startDates')]
    public function it_prefills_a_start_date_inside_the_next_period(string $invoiceDate, string $frequency, string $expected): void
    {
        /* Arrange: see the data provider */

        /* Act */
        $response = $this->ajax('POST', '/invoices/ajax/get_recur_start_date', [
            'invoice_date'    => $invoiceDate,
            'recur_frequency' => $frequency,
        ]);

        /* Assert */
        self::assertSame(200, $response->statusCode());
        self::assertSame($expected, trim($response->body()));
    }
}

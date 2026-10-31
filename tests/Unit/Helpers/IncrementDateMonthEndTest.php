<?php

namespace Tests\Unit\Helpers;

use DateInterval;
use DateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * increment_date() advances recur_next_date after every generated recurring invoice. DateTime::add()
 * rolls a day the target month lacks into the month after (10/31 + 1 month = 12/01), so a monthly
 * schedule skipped November.
 */
final class IncrementDateMonthEndTest extends TestCase
{
    protected function setUp(): void
    {
        require_once APPPATH . 'helpers/date_helper.php';
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}> start, interval, expected
     */
    public static function increments(): array
    {
        return [
            'oct 31 + 1 month stops at nov 30'         => ['2026-10-31', '1M', '2026-11-30'],
            'jan 31 + 1 month stops at feb 28'         => ['2027-01-31', '1M', '2027-02-28'],
            'jan 31 + 1 month in a leap year'          => ['2028-01-31', '1M', '2028-02-29'],
            'jan 30 + 1 month stops at feb 28'         => ['2027-01-30', '1M', '2027-02-28'],
            'mar 31 + 3 months stops at jun 30'        => ['2027-03-31', '3M', '2027-06-30'],
            'aug 31 + 6 months stops at feb 28'        => ['2026-08-31', '6M', '2027-02-28'],
            'feb 29 + 1 year stops at feb 28'          => ['2028-02-29', '1Y', '2029-02-28'],
            'dec 31 + 1 month crosses the year'        => ['2026-12-31', '1M', '2027-01-31'],
            'jan 15 + 1 month is unchanged'            => ['2026-01-15', '1M', '2026-02-15'],
            'oct 31 + 2 months exists in december'     => ['2026-10-31', '2M', '2026-12-31'],
            'jan 31 + 1 week is plain day arithmetic'  => ['2027-01-31', '1W', '2027-02-07'],
            'oct 31 + 1 day is plain day arithmetic'   => ['2026-10-31', '1D', '2026-11-01'],
            'feb 28 + 1 year keeps the day'            => ['2027-02-28', '1Y', '2028-02-28'],
        ];
    }

    #[Test]
    #[DataProvider('increments')]
    public function it_advances_the_date_without_skipping_a_month(string $start, string $interval, string $expected): void
    {
        /* Arrange: see the data provider */

        /* Act */
        $result = increment_date($start, $interval);

        /* Assert */
        self::assertSame($expected, $result);
    }

    #[Test]
    public function it_returns_an_empty_string_for_a_null_date(): void
    {
        /* Arrange */
        $date = null;

        /* Act */
        $result = increment_date($date, '1M');

        /* Assert */
        self::assertSame('', $result);
    }

    #[Test]
    public function it_clamps_the_given_date_object_to_the_last_day_of_the_target_month(): void
    {
        /* Arrange */
        $date = new DateTime('2026-10-31');

        /* Act */
        $result = add_interval_clamped($date, new DateInterval('P1M'));

        /* Assert */
        self::assertSame('2026-11-30', $result->format('Y-m-d'));
    }

    #[Test]
    public function it_keeps_the_original_day_when_a_later_step_lands_in_a_longer_month(): void
    {
        /* Arrange: stepping from the original date, not from an already clamped one */
        $start = '2026-10-31';

        /* Act */
        $november = increment_date($start, '1M');
        $december = increment_date($start, '2M');

        /* Assert */
        self::assertSame('2026-11-30', $november);
        self::assertSame('2026-12-31', $december);
    }
}

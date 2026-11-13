<?php

namespace Tests\Unit\Payments\Libraries\Gateways;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the amount arithmetic behind the Stripe checkout flow.
 *
 * guest/gateways/Stripe.php sends the invoice balance to Stripe as
 *   stripe_amount_to_minor_units($balance, $currency)
 * and reads a callback amount back with
 *   amount_from_minor_units($minor, stripe_minor_unit_multiplier($currency)).
 * All four helpers are pure; this pins three buckets per
 * https://docs.stripe.com/currencies:
 *   - zero-decimal currencies (JPY et al.): multiplier 1, no rounding step;
 *   - everything else, multiplier 100, including the two special-case currencies
 *     (UGX, ISK) that are zero-decimal but must be expressed as a two-decimal
 *     value whose decimal part is always "00" — i.e. the minor-unit amount must
 *     additionally be a multiple of 100;
 *   - three-decimal currencies (BHD, JOD, KWD, OMR, TND): multiplier 1000, and
 *     the minor-unit amount must be a multiple of 10.
 *
 * GHSA/issue: https://github.com/InvoicePlane/InvoicePlane/issues/1677 — a ¥100
 * invoice sent `unit_amount: 10000` (×100 regardless of currency), charging the
 * customer 100× the invoice total for every zero-decimal currency. Reported by
 *
 * @snajafov; the three-decimal and UGX/ISK gap in the first fix was reported by
 *
 * @catidegla on the same issue.
 *
 * The end-to-end behaviour is exercised in
 * tests/Feature/Payments/StripeFlowTest.php.
 */
class StripeMinorUnitTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 5) . '/application/helpers/stripe_helper.php';
        require_once dirname(__DIR__, 5) . '/application/helpers/currency_helper.php';
    }

    /**
     * Every currency stripe_minor_unit_multiplier() treats as zero-decimal.
     * Keep this in lock-step with the helper's list.
     *
     * @return array<string, array{0: string}>
     */
    public static function zeroDecimalCurrencyProvider(): array
    {
        $codes = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

        $cases = [];
        foreach ($codes as $code) {
            $cases[$code] = [$code];
        }
        $cases['lowercase jpy'] = ['jpy'];

        return $cases;
    }

    /** @return array<string, array{0: string}> */
    public static function decimalCurrencyProvider(): array
    {
        return [
            'EUR'           => ['EUR'],
            'USD'           => ['USD'],
            'GBP'           => ['GBP'],
            'lowercase eur' => ['eur'],
            'unknown code'  => ['ZZZ'],
            'empty string'  => [''],
        ];
    }

    /**
     * UGX and ISK: Stripe's "special case" currencies. Zero-decimal in spirit, but Stripe still
     * wants a ×100 minor-unit amount whose decimal part is always "00".
     *
     * @return array<string, array{0: string}>
     */
    public static function specialCaseCurrencyProvider(): array
    {
        return [
            'UGX'           => ['UGX'],
            'ISK'           => ['ISK'],
            'lowercase ugx' => ['ugx'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function threeDecimalCurrencyProvider(): array
    {
        return [
            'BHD'           => ['BHD'],
            'JOD'           => ['JOD'],
            'KWD'           => ['KWD'],
            'OMR'           => ['OMR'],
            'TND'           => ['TND'],
            'lowercase bhd' => ['bhd'],
        ];
    }

    // -------------------------------------------------------------------------
    // stripe_minor_unit_multiplier
    // -------------------------------------------------------------------------

    #[Test]
    #[DataProvider('zeroDecimalCurrencyProvider')]
    public function it_uses_a_multiplier_of_one_for_zero_decimal_currencies(string $currency): void
    {
        self::assertSame(1, stripe_minor_unit_multiplier($currency));
    }

    #[Test]
    #[DataProvider('decimalCurrencyProvider')]
    public function it_uses_a_multiplier_of_one_hundred_for_decimal_currencies(string $currency): void
    {
        self::assertSame(100, stripe_minor_unit_multiplier($currency));
    }

    #[Test]
    #[DataProvider('specialCaseCurrencyProvider')]
    public function it_uses_a_multiplier_of_one_hundred_for_the_special_case_currencies(string $currency): void
    {
        // UGX/ISK are zero-decimal by nature but Stripe still wants them ×100 (CWE class: this is
        // exactly the bug this issue reports, just in the opposite direction — treating them as
        // zero-decimal would undercharge by 100x).
        self::assertSame(100, stripe_minor_unit_multiplier($currency));
    }

    #[Test]
    #[DataProvider('threeDecimalCurrencyProvider')]
    public function it_uses_a_multiplier_of_one_thousand_for_three_decimal_currencies(string $currency): void
    {
        self::assertSame(1000, stripe_minor_unit_multiplier($currency));
    }

    // -------------------------------------------------------------------------
    // stripe_minor_unit_rounding_step
    // -------------------------------------------------------------------------

    #[Test]
    #[DataProvider('zeroDecimalCurrencyProvider')]
    #[DataProvider('decimalCurrencyProvider')]
    public function it_has_no_rounding_step_outside_the_special_cases(string $currency): void
    {
        self::assertSame(1, stripe_minor_unit_rounding_step($currency));
    }

    #[Test]
    #[DataProvider('specialCaseCurrencyProvider')]
    public function it_requires_a_rounding_step_of_one_hundred_for_the_special_case_currencies(string $currency): void
    {
        self::assertSame(100, stripe_minor_unit_rounding_step($currency));
    }

    #[Test]
    #[DataProvider('threeDecimalCurrencyProvider')]
    public function it_requires_a_rounding_step_of_ten_for_three_decimal_currencies(string $currency): void
    {
        self::assertSame(10, stripe_minor_unit_rounding_step($currency));
    }

    // -------------------------------------------------------------------------
    // stripe_amount_to_minor_units — what the checkout session line item actually sends
    // -------------------------------------------------------------------------

    #[Test]
    public function it_sends_a_decimal_currency_balance_as_plain_cents(): void
    {
        self::assertSame(10_000, stripe_amount_to_minor_units('100.00', 'EUR'));
        self::assertSame(1_999, stripe_amount_to_minor_units('19.99', 'EUR'));
    }

    #[Test]
    public function it_sends_a_zero_decimal_currency_checkout_amount_unscaled(): void
    {
        // The bug this issue reports: a ¥100 invoice must send unit_amount: 100, not 10000.
        self::assertSame(100, stripe_amount_to_minor_units('100.00', 'JPY'));
    }

    #[Test]
    public function it_rounds_a_special_case_currency_amount_to_the_nearest_hundred(): void
    {
        // 5.01 UGX -> 501 minor units -> rounded down to the nearest multiple of 100 Stripe requires.
        self::assertSame(500, stripe_amount_to_minor_units('5.01', 'UGX'));
        // 5.99 UGX -> 599 minor units -> rounded up to the nearest multiple of 100.
        self::assertSame(600, stripe_amount_to_minor_units('5.99', 'UGX'));
        // A whole-number invoice is already a clean multiple of 100 and is untouched.
        self::assertSame(500, stripe_amount_to_minor_units('5.00', 'UGX'));
        self::assertSame(500, stripe_amount_to_minor_units('5.00', 'ISK'));
    }

    #[Test]
    public function it_rounds_a_three_decimal_currency_amount_to_the_nearest_ten(): void
    {
        // 1.234 KWD -> 1234 minor units -> rounded down to the nearest multiple of 10.
        self::assertSame(1230, stripe_amount_to_minor_units('1.234', 'KWD'));
        // 1.236 KWD -> 1236 minor units -> rounded up to the nearest multiple of 10.
        self::assertSame(1240, stripe_amount_to_minor_units('1.236', 'KWD'));
        // A whole-thousandth invoice is already a clean multiple of 10 and is untouched.
        self::assertSame(1230, stripe_amount_to_minor_units('1.230', 'KWD'));
    }

    // -------------------------------------------------------------------------
    // stripe_minor_unit_rounding_tolerance — how far a capture may miss the invoice balance
    // -------------------------------------------------------------------------

    #[Test]
    #[DataProvider('zeroDecimalCurrencyProvider')]
    #[DataProvider('decimalCurrencyProvider')]
    public function it_uses_the_plain_float_tolerance_outside_the_special_cases(string $currency): void
    {
        self::assertSame(0.0001, stripe_minor_unit_rounding_tolerance($currency));
    }

    #[Test]
    public function it_tolerates_half_the_rounding_step_for_the_special_case_currencies(): void
    {
        // Step 100, multiplier 100 -> half a major unit either side of the invoice balance.
        self::assertSame(0.5, stripe_minor_unit_rounding_tolerance('UGX'));
        self::assertSame(0.5, stripe_minor_unit_rounding_tolerance('ISK'));
    }

    #[Test]
    public function it_tolerates_half_the_rounding_step_for_three_decimal_currencies(): void
    {
        // Step 10, multiplier 1000 -> half a thousandth (0.005) either side of the invoice balance.
        self::assertSame(0.005, stripe_minor_unit_rounding_tolerance('KWD'));
    }

    #[Test]
    public function it_matches_amount_to_minor_units_when_there_is_no_rounding_step(): void
    {
        self::assertSame(
            amount_to_minor_units('42.50', stripe_minor_unit_multiplier('EUR')),
            stripe_amount_to_minor_units('42.50', 'EUR')
        );
        self::assertSame(
            amount_to_minor_units('42.50', stripe_minor_unit_multiplier('JPY')),
            stripe_amount_to_minor_units('42.50', 'JPY')
        );
    }

    // -------------------------------------------------------------------------
    // amount_to_minor_units — the checkout session line-item amount
    // -------------------------------------------------------------------------

    #[Test]
    public function it_converts_a_decimal_currency_balance_to_integer_cents(): void
    {
        self::assertSame(10_000, amount_to_minor_units('100.00', stripe_minor_unit_multiplier('EUR')));
        self::assertSame(1_999, amount_to_minor_units('19.99', stripe_minor_unit_multiplier('EUR')));
    }

    #[Test]
    public function it_sends_a_zero_decimal_currency_balance_unscaled(): void
    {
        self::assertSame(100, amount_to_minor_units('100.00', stripe_minor_unit_multiplier('JPY')));
    }

    #[Test]
    public function it_rounds_to_the_nearest_minor_unit(): void
    {
        self::assertSame(2_000, amount_to_minor_units('19.999', stripe_minor_unit_multiplier('EUR')));
    }

    #[Test]
    public function it_rejects_a_multiplier_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        amount_to_minor_units('10.00', 0);
    }

    // -------------------------------------------------------------------------
    // amount_from_minor_units — the callback amount check
    // -------------------------------------------------------------------------

    #[Test]
    public function it_converts_stripe_cents_back_to_a_major_amount(): void
    {
        self::assertSame(50.0, amount_from_minor_units(5_000, stripe_minor_unit_multiplier('EUR')));
    }

    #[Test]
    public function it_leaves_a_zero_decimal_currency_amount_unscaled_on_the_way_back(): void
    {
        self::assertSame(100.0, amount_from_minor_units(100, stripe_minor_unit_multiplier('JPY')));
    }

    #[Test]
    public function it_round_trips_a_decimal_currency_balance(): void
    {
        $multiplier = stripe_minor_unit_multiplier('EUR');

        self::assertSame(19.99, amount_from_minor_units(amount_to_minor_units('19.99', $multiplier), $multiplier));
    }

    #[Test]
    public function it_rejects_a_multiplier_below_one_on_the_way_back(): void
    {
        $this->expectException(InvalidArgumentException::class);

        amount_from_minor_units(1_000, 0);
    }
}

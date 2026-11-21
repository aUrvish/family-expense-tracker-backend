<?php

namespace Tests\Unit;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Services\CurrencyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CurrencyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CurrencyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CurrencyService::class);

        Currency::create([
            'code' => 'USD',
            'name' => 'United States Dollar',
            'symbol' => '$',
            'decimal_places' => 2,
            'is_active' => true,
        ]);

        Currency::create([
            'code' => 'EUR',
            'name' => 'Euro',
            'symbol' => '€',
            'decimal_places' => 2,
            'is_active' => true,
        ]);

        Currency::create([
            'code' => 'JPY',
            'name' => 'Japanese Yen',
            'symbol' => '¥',
            'decimal_places' => 0,
            'is_active' => true,
        ]);

        Currency::create([
            'code' => 'KWD',
            'name' => 'Kuwaiti Dinar',
            'symbol' => 'KD',
            'decimal_places' => 3,
            'is_active' => true,
        ]);
    }

    public function test_convert_for_same_base_currency_returns_original_cents(): void
    {
        $converted = $this->service->convert(5000, 'USD', Carbon::parse('2026-11-20'));

        $this->assertSame(5000, $converted);
    }

    public function test_convert_uses_exact_exchange_rate_for_date(): void
    {
        ExchangeRate::create([
            'currency' => 'EUR',
            'rate' => 0.95000000,
            'date' => '2026-11-20',
        ]);

        $converted = $this->service->convert(1000, 'EUR', Carbon::parse('2026-11-20'));

        $this->assertEquals(950, $converted);
    }

    public function test_convert_falls_back_to_latest_available_date_on_or_before_requested_date(): void
    {
        ExchangeRate::create([
            'currency' => 'EUR',
            'rate' => 0.92000000,
            'date' => '2026-11-10',
        ]);
        ExchangeRate::create([
            'currency' => 'EUR',
            'rate' => 0.95000000,
            'date' => '2026-11-18',
        ]);

        // Rate on a weekend or missing date (2026-11-20) should fall back to 2026-11-18
        $converted = $this->service->convert(1000, 'EUR', Carbon::parse('2026-11-20'));

        $this->assertEquals(950, $converted);
    }

    public function test_convert_throws_exception_in_strict_mode_when_no_prior_rate_exists(): void
    {
        ExchangeRate::create([
            'currency' => 'EUR',
            'rate' => 0.95000000,
            'date' => '2026-11-20',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Exchange rate not found for currency [EUR] on or before [2026-11-15].');

        $this->service->convert(1000, 'EUR', Carbon::parse('2026-11-15'), strict: true);
    }

    public function test_convert_safely_falls_back_to_subsequent_or_latest_rate_when_not_strict(): void
    {
        ExchangeRate::create([
            'currency' => 'EUR',
            'rate' => 0.95000000,
            'date' => '2026-11-20',
        ]);

        // Prior date 2026-11-15 has no prior rate, but should safely fall back to 2026-11-20 rate without throwing
        $converted = $this->service->convert(1000, 'EUR', Carbon::parse('2026-11-15'), strict: false);

        $this->assertSame(950, $converted);
    }

    public function test_convert_for_standard_two_decimal_currency(): void
    {
        ExchangeRate::create([
            'currency' => 'EUR',
            'rate' => 0.90000000,
            'date' => '2026-11-20',
        ]);

        // 1000 USD cents ($10.00) * 0.90 = 900 EUR cents (€9.00)
        $converted = $this->service->convert(1000, 'EUR', Carbon::parse('2026-11-20'));

        $this->assertSame(900, $converted);
    }

    public function test_convert_for_zero_decimal_currency(): void
    {
        ExchangeRate::create([
            'currency' => 'JPY',
            'rate' => 155.50000000,
            'date' => '2026-11-20',
        ]);

        // 1000 USD cents ($10.00) * 155.50 * 10^(0 - 2) = 1555 JPY units (0 decimals)
        $converted = $this->service->convert(1000, 'JPY', Carbon::parse('2026-11-20'));

        $this->assertSame(1555, $converted);
    }

    public function test_convert_for_three_decimal_currency(): void
    {
        ExchangeRate::create([
            'currency' => 'KWD',
            'rate' => 0.30800000,
            'date' => '2026-11-20',
        ]);

        // 1000 USD cents ($10.00) * 0.308 * 10^(3 - 2) = 3080 KWD fils (3 decimals)
        $converted = $this->service->convert(1000, 'KWD', Carbon::parse('2026-11-20'));

        $this->assertSame(3080, $converted);
    }

    public function test_convert_to_base_cents_for_standard_and_multi_decimal_currencies(): void
    {
        ExchangeRate::create([
            'currency' => 'EUR',
            'rate' => 0.95000000,
            'date' => '2026-11-20',
        ]);
        ExchangeRate::create([
            'currency' => 'JPY',
            'rate' => 155.50000000,
            'date' => '2026-11-20',
        ]);

        $date = Carbon::parse('2026-11-20');

        // 950 EUR cents (€9.50) / 0.95 = 1000 USD cents ($10.00)
        $baseCentsEur = $this->service->convertToBase(950, 'EUR', $date);
        $this->assertSame(1000, $baseCentsEur);

        // 1555 JPY units (¥1555) / 155.50 = 1000 USD cents ($10.00)
        $baseCentsJpy = $this->service->convertToBase(1555, 'JPY', $date);
        $this->assertSame(1000, $baseCentsJpy);

        // Passthrough USD
        $baseCentsUsd = $this->service->convertToBase(1000, 'USD', $date);
        $this->assertSame(1000, $baseCentsUsd);
    }

    public function test_convert_to_base_amount_float_helper(): void
    {
        ExchangeRate::create([
            'currency' => 'EUR',
            'rate' => 0.95000000,
            'date' => '2026-11-20',
        ]);

        $baseAmount = $this->service->convertToBaseAmount(95.00, 'EUR', '2026-11-20');
        $this->assertEquals(100.00, $baseAmount);
    }

    public function test_format_with_display_structure_and_symbols(): void
    {
        ExchangeRate::create([
            'currency' => 'EUR',
            'rate' => 0.92000000,
            'date' => '2026-11-20',
        ]);

        $display = $this->service->formatWithDisplay(2000, 'EUR', Carbon::parse('2026-11-20'));

        $this->assertSame([
            'amount_cents' => 1840,
            'currency' => 'EUR',
            'symbol' => '€',
            'formatted' => '€18.40',
        ], $display);

        $usdDisplay = $this->service->formatWithDisplay(1050, 'USD', Carbon::parse('2026-11-20'));
        $this->assertSame('$10.50', $usdDisplay['formatted']);
    }
}

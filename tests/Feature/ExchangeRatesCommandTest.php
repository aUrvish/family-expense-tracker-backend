<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExchangeRatesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_rates_fetch_command_fetches_and_stores_rates_from_frankfurter(): void
    {
        Http::fake([
            'https://api.frankfurter.dev/v1/latest?base=USD' => Http::response([
                'amount' => 1.0,
                'base' => 'USD',
                'date' => '2026-11-21',
                'rates' => [
                    'EUR' => 0.9521,
                    'GBP' => 0.7934,
                    'JPY' => 155.62,
                ],
            ], 200),
        ]);

        $this->artisan('rates:fetch')
            ->expectsOutputToContain('Fetching latest exchange rates with base [USD]...')
            ->expectsOutputToContain('Successfully stored [4] exchange rates for date [2026-11-21].')
            ->assertSuccessful();

        // USD base rate = 1.0
        $this->assertDatabaseHas('exchange_rates', [
            'currency' => 'USD',
            'date' => '2026-11-21',
            'rate' => '1.00000000',
        ]);

        // EUR rate
        $this->assertDatabaseHas('exchange_rates', [
            'currency' => 'EUR',
            'date' => '2026-11-21',
            'rate' => '0.95210000',
        ]);

        // JPY rate
        $this->assertDatabaseHas('exchange_rates', [
            'currency' => 'JPY',
            'date' => '2026-11-21',
            'rate' => '155.62000000',
        ]);
    }

    public function test_rates_fetch_falls_back_to_exchangerate_api_when_frankfurter_fails(): void
    {
        Config::set('services.exchangerate_api.key', 'mock_api_key_123');

        Http::fake([
            'https://api.frankfurter.dev/*' => Http::response(null, 500),
            'https://v6.exchangerate-api.com/v6/mock_api_key_123/latest/USD' => Http::response([
                'result' => 'success',
                'base_code' => 'USD',
                'conversion_rates' => [
                    'USD' => 1.0,
                    'EUR' => 0.9485,
                    'CAD' => 1.4012,
                ],
            ], 200),
        ]);

        $this->artisan('rates:fetch')
            ->expectsOutputToContain('Primary provider (Frankfurter) failed. Attempting fallback to ExchangeRate-API...')
            ->assertSuccessful();

        $today = now()->toDateString();

        $this->assertDatabaseHas('exchange_rates', [
            'currency' => 'USD',
            'date' => $today,
            'rate' => '1.00000000',
        ]);

        $this->assertDatabaseHas('exchange_rates', [
            'currency' => 'EUR',
            'date' => $today,
            'rate' => '0.94850000',
        ]);
    }
}

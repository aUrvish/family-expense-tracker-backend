<?php

namespace App\Console\Commands;

use App\Models\ExchangeRate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Command to fetch and store daily exchange rates for the base currency.
 */
class FetchExchangeRatesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rates:fetch';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch latest exchange rates from Frankfurter API with fallback to ExchangeRate-API';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $base = strtoupper((string) config('app.base_currency', 'USD'));
        $this->info("Fetching latest exchange rates with base [{$base}]...");

        $date = null;
        $rates = null;

        // 1. Try primary provider: Frankfurter API (free, no API key required)
        try {
            $response = Http::timeout(10)->get("https://api.frankfurter.dev/v1/latest?base={$base}");
            if ($response->successful()) {
                $data = $response->json();
                $date = $data['date'] ?? null;
                $rates = $data['rates'] ?? null;
            }
        } catch (Throwable $e) {
            Log::warning('Frankfurter API fetch error: '.$e->getMessage());
        }

        // 2. Try fallback provider: ExchangeRate-API if primary failed
        if (! $rates) {
            $this->warn('Primary provider (Frankfurter) failed. Attempting fallback to ExchangeRate-API...');
            $apiKey = config('services.exchangerate_api.key');

            if ($apiKey) {
                try {
                    $response = Http::timeout(10)->get("https://v6.exchangerate-api.com/v6/{$apiKey}/latest/{$base}");
                    if ($response->successful()) {
                        $data = $response->json();
                        $date = now()->toDateString();
                        $rates = $data['conversion_rates'] ?? null;
                    }
                } catch (Throwable $e) {
                    Log::warning('ExchangeRate-API fetch error: '.$e->getMessage());
                }
            }
        }

        if (! $rates || ! $date) {
            $this->error('Failed to fetch exchange rates from all configured providers.');
            Log::error('rates:fetch failed: all providers unreachable or returned errors.');

            return self::FAILURE;
        }

        // 3. Store base currency rate as 1.0
        ExchangeRate::updateOrCreate(
            ['currency' => $base, 'date' => $date],
            ['rate' => 1.0]
        );

        $storedCount = 1;
        foreach ($rates as $currency => $rate) {
            ExchangeRate::updateOrCreate(
                ['currency' => strtoupper($currency), 'date' => $date],
                ['rate' => (float) $rate]
            );
            $storedCount++;
        }

        $this->info("Successfully stored [{$storedCount}] exchange rates for date [{$date}].");

        return self::SUCCESS;
    }
}

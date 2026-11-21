<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\ExchangeRate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Service handling currency exchange rate lookup, conversion, and formatting.
 */
class CurrencyService
{
    /**
     * In-memory cache for currency models to prevent redundant queries during request lifecycle.
     *
     * @var array<string, Currency>
     */
    protected array $currencyCache = [];

    /**
     * Convert an amount stored in base currency cents into target currency cents.
     * Supports currencies with different decimal places (e.g. JPY = 0, USD = 2, KWD = 3).
     *
     * @param  int  $cents  Amount in base currency cents
     * @param  string  $to  Target ISO 4217 currency code
     * @param  Carbon  $date  Transaction date for historical exchange rate lookup
     * @param  bool  $strict  Whether to throw an exception if no exact/prior rate exists
     * @return int Converted amount in target currency cents
     *
     * @throws RuntimeException If strict is true and no exchange rate is found on or before the date.
     */
    public function convert(int $cents, string $to, Carbon $date, bool $strict = false): int
    {
        $to = strtoupper(trim($to));
        $baseCurrency = strtoupper((string) config('app.base_currency', 'USD'));

        if ($cents === 0 || $to === $baseCurrency) {
            return $cents;
        }

        $rate = $this->getRate($to, $date, $strict);

        $baseModel = $this->getCurrencyModel($baseCurrency);
        $targetModel = $this->getCurrencyModel($to);

        $baseDecimals = $baseModel ? $baseModel->decimal_places : 2;
        $targetDecimals = $targetModel ? $targetModel->decimal_places : 2;

        $decimalDifference = $targetDecimals - $baseDecimals;

        return (int) round($cents * $rate * (10 ** $decimalDifference));
    }

    /**
     * Convert an amount from target currency cents back into base currency cents.
     *
     * @param  int  $targetCents  Amount in target currency cents/units
     * @param  string  $from  Source ISO 4217 currency code
     * @param  Carbon  $date  Transaction date for historical rate lookup
     * @param  bool  $strict  Whether to throw if rate missing
     * @return int Amount in base currency cents
     */
    public function convertToBase(int $targetCents, string $from, Carbon $date, bool $strict = false): int
    {
        $from = strtoupper(trim($from));
        $baseCurrency = strtoupper((string) config('app.base_currency', 'USD'));

        if ($targetCents === 0 || $from === $baseCurrency) {
            return $targetCents;
        }

        $rate = $this->getRate($from, $date, $strict);

        if ($rate <= 0) {
            return $targetCents;
        }

        $baseModel = $this->getCurrencyModel($baseCurrency);
        $fromModel = $this->getCurrencyModel($from);

        $baseDecimals = $baseModel ? $baseModel->decimal_places : 2;
        $fromDecimals = $fromModel ? $fromModel->decimal_places : 2;

        $decimalDifference = $fromDecimals - $baseDecimals;

        return (int) round(($targetCents / $rate) / (10 ** $decimalDifference));
    }

    /**
     * Convert a decimal amount from user/family currency into base currency units for database storage.
     */
    public function convertToBaseAmount(float $amount, string $from, mixed $date = null): float
    {
        $from = strtoupper(trim($from));
        $baseCurrency = strtoupper((string) config('app.base_currency', 'USD'));

        if ($from === $baseCurrency || $amount == 0) {
            return round($amount, 2);
        }

        $fromModel = $this->getCurrencyModel($from);
        $fromDecimals = $fromModel ? $fromModel->decimal_places : 2;
        $targetCents = (int) round($amount * (10 ** $fromDecimals));

        $lookupDate = $date ? Carbon::parse($date) : now();
        $baseCents = $this->convertToBase($targetCents, $from, $lookupDate);

        return round($baseCents / 100, 2);
    }

    /**
     * Convert a decimal amount from base currency into target currency units.
     */
    public function convertAmount(float $baseAmount, string $to, mixed $date = null): float
    {
        $to = strtoupper(trim($to));
        $baseCurrency = strtoupper((string) config('app.base_currency', 'USD'));

        if ($to === $baseCurrency || $baseAmount == 0) {
            return round($baseAmount, 2);
        }

        $baseCents = (int) round($baseAmount * 100);
        $lookupDate = $date ? Carbon::parse($date) : now();
        $convertedCents = $this->convert($baseCents, $to, $lookupDate);

        $targetModel = $this->getCurrencyModel($to);
        $targetDecimals = $targetModel ? $targetModel->decimal_places : 2;

        return round($convertedCents / (10 ** $targetDecimals), $targetDecimals);
    }

    /**
     * Lookup exchange rate for a currency on or before the given date with safe fallback.
     */
    public function getRate(string $currency, Carbon $date, bool $strict = false): float
    {
        $currency = strtoupper(trim($currency));
        $baseCurrency = strtoupper((string) config('app.base_currency', 'USD'));

        if ($currency === $baseCurrency) {
            return 1.0;
        }

        $dateString = $date->toDateString();

        // 1. Look for exchange rate on or before requested date
        $record = ExchangeRate::query()
            ->where('currency', $currency)
            ->where('date', '<=', $dateString)
            ->orderByDesc('date')
            ->first();

        if ($record) {
            return (float) $record->rate;
        }

        if ($strict) {
            throw new RuntimeException("Exchange rate not found for currency [{$currency}] on or before [{$dateString}].");
        }

        // 2. Safe Fallback: Look for earliest rate on or after requested date
        $fallbackAfter = ExchangeRate::query()
            ->where('currency', $currency)
            ->where('date', '>=', $dateString)
            ->orderBy('date')
            ->first();

        if ($fallbackAfter) {
            return (float) $fallbackAfter->rate;
        }

        // 3. Safe Fallback: Latest available rate in the database for that currency
        $latest = ExchangeRate::query()
            ->where('currency', $currency)
            ->orderByDesc('date')
            ->first();

        if ($latest) {
            return (float) $latest->rate;
        }

        Log::warning("No exchange rate found for currency [{$currency}] in database. Using 1.0 fallback.");

        return 1.0;
    }

    /**
     * Format an already converted cent amount into structured display metadata.
     *
     * @param  int  $convertedCents  Amount in target currency cents/units
     * @param  string  $targetCurrency  Target ISO 4217 currency code
     * @return array{amount_cents: int, currency: string, symbol: string, formatted: string}
     */
    public function formatConverted(int $convertedCents, string $targetCurrency): array
    {
        $targetCurrency = strtoupper(trim($targetCurrency));
        $currencyModel = $this->getCurrencyModel($targetCurrency);

        $decimals = $currencyModel ? $currencyModel->decimal_places : 2;
        $symbol = $currencyModel ? $currencyModel->symbol : '$';

        $units = $convertedCents / (10 ** $decimals);
        $formattedNumber = number_format($units, $decimals);

        $formatted = (mb_strlen($symbol) > 1 && ! str_ends_with($symbol, '$'))
            ? "{$symbol} {$formattedNumber}"
            : "{$symbol}{$formattedNumber}";

        return [
            'amount_cents' => $convertedCents,
            'currency' => $targetCurrency,
            'symbol' => $symbol,
            'formatted' => $formatted,
        ];
    }

    /**
     * Convert base cents and generate structured display metadata.
     *
     * @param  int  $baseCents  Amount in base currency cents
     * @param  string  $targetCurrency  Target ISO 4217 currency code
     * @param  Carbon  $date  Transaction date
     * @return array{amount_cents: int, currency: string, symbol: string, formatted: string}
     */
    public function formatWithDisplay(int $baseCents, string $targetCurrency, Carbon $date): array
    {
        $convertedCents = $this->convert($baseCents, $targetCurrency, $date);

        return $this->formatConverted($convertedCents, $targetCurrency);
    }

    /**
     * Retrieve and memoize an active Currency model by its ISO code.
     *
     * @param  string  $code  ISO 4217 currency code
     */
    public function getCurrencyModel(string $code): ?Currency
    {
        $code = strtoupper(trim($code));

        if (! isset($this->currencyCache[$code])) {
            $currency = Currency::where('code', $code)->first();
            if ($currency) {
                $this->currencyCache[$code] = $currency;
            } else {
                return null;
            }
        }

        return $this->currencyCache[$code];
    }
}

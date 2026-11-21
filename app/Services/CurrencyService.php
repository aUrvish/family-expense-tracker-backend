<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\ExchangeRate;
use Carbon\Carbon;
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
     * @return int Converted amount in target currency cents
     *
     * @throws RuntimeException If no exchange rate is found on or before the date.
     */
    public function convert(int $cents, string $to, Carbon $date): int
    {
        $to = strtoupper(trim($to));
        $baseCurrency = strtoupper((string) config('app.base_currency', 'USD'));

        if ($cents === 0 || $to === $baseCurrency) {
            return $cents;
        }

        $dateString = $date->toDateString();

        $rateRecord = ExchangeRate::query()
            ->where('currency', $to)
            ->where('date', '<=', $dateString)
            ->orderByDesc('date')
            ->first();

        if (! $rateRecord) {
            throw new RuntimeException("Exchange rate not found for currency [{$to}] on or before [{$dateString}].");
        }

        $rate = (float) $rateRecord->rate;

        $baseModel = $this->getCurrencyModel($baseCurrency);
        $targetModel = $this->getCurrencyModel($to);

        $baseDecimals = $baseModel ? $baseModel->decimal_places : 2;
        $targetDecimals = $targetModel ? $targetModel->decimal_places : 2;

        $decimalDifference = $targetDecimals - $baseDecimals;

        return (int) round($cents * $rate * (10 ** $decimalDifference));
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
        $targetCurrency = strtoupper(trim($targetCurrency));
        $convertedCents = $this->convert($baseCents, $targetCurrency, $date);
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

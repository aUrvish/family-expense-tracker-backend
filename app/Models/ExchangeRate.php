<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Exchange rate model storing daily rates relative to the base currency.
 *
 * @property int $id
 * @property string $currency Target currency ISO code
 * @property float $rate Units of target currency per 1 unit of base currency
 * @property Carbon $date Effective date of exchange rate
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'currency',
    'rate',
    'date',
])]
class ExchangeRate extends Model
{
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'float',
            'date' => 'date:Y-m-d',
        ];
    }

    /**
     * Get the currency definition for this exchange rate.
     *
     * @return BelongsTo<Currency, $this>
     */
    public function currencyModel(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency', 'code');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Currency model representing supported ISO 4217 currencies.
 *
 * @property int $id
 * @property string $code ISO 4217 currency code (e.g. USD, EUR, JPY)
 * @property string $name Full currency name (e.g. US Dollar)
 * @property string $symbol Currency symbol (e.g. $, €, ¥)
 * @property int $decimal_places Decimal places used by the currency (e.g. 2 for USD, 0 for JPY, 3 for KWD)
 * @property bool $is_active Whether the currency is active and selectable in settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'code',
    'name',
    'symbol',
    'decimal_places',
    'is_active',
])]
class Currency extends Model
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
            'decimal_places' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope query to active currencies only.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Get exchange rates recorded for this currency.
     *
     * @return HasMany<ExchangeRate, $this>
     */
    public function exchangeRates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class, 'currency', 'code');
    }
}

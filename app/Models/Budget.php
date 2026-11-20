<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'family_id',
    'category_id',
    'amount',
    'month',
    'year',
])]
class Budget extends Model
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
            'amount' => 'decimal:2',
            'month' => 'integer',
            'year' => 'integer',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }

    /**
     * Get the family that owns the budget.
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * Get the category for this budget.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}

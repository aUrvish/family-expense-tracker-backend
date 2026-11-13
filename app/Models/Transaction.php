<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'family_id',
    'user_id',
    'category_id',
    'type',
    'amount',
    'date',
    'note',
    'payment_method',
    'visibility',
])]
class Transaction extends Model
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
            'date' => 'date:Y-m-d',
        ];
    }

    /**
     * Get the family that owns the transaction.
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * Get the user who recorded the transaction.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the category of the transaction.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Scope query to expenses only.
     */
    public function scopeExpenses(Builder $query): Builder
    {
        return $query->where('type', 'expense');
    }

    /**
     * Scope query to income only.
     */
    public function scopeIncomes(Builder $query): Builder
    {
        return $query->where('type', 'income');
    }

    /**
     * Scope query to shared transactions only.
     */
    public function scopeShared(Builder $query): Builder
    {
        return $query->where('visibility', 'shared');
    }

    /**
     * Scope query to personal transactions only.
     */
    public function scopePersonal(Builder $query): Builder
    {
        return $query->where('visibility', 'personal');
    }

    /**
     * Scope query to transactions visible to the given user:
     * shared transactions OR the user's own personal transactions.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('visibility', 'shared')
                ->orWhere(function (Builder $personal) use ($user) {
                    $personal->where('visibility', 'personal')
                        ->where('user_id', $user->id);
                });
        });
    }
}

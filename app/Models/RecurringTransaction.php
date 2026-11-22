<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $family_id
 * @property int $user_id
 * @property int $category_id
 * @property string $type 'expense' or 'income'
 * @property string $amount Decimal base amount
 * @property string $frequency 'daily', 'weekly', 'monthly', 'yearly'
 * @property int $interval
 * @property int|string $start_date
 * @property int|string|null $end_date
 * @property int|string $next_due_date
 * @property int|string|null $last_generated_at
 * @property string $status 'active', 'paused', 'completed'
 * @property string|null $note
 * @property string|null $payment_method
 * @property string $visibility 'shared' or 'personal'
 * @property int|string|null $created_at
 * @property int|string|null $updated_at
 * @property int|string|null $deleted_at
 */
#[Fillable([
    'family_id',
    'user_id',
    'category_id',
    'type',
    'amount',
    'frequency',
    'interval',
    'start_date',
    'end_date',
    'next_due_date',
    'last_generated_at',
    'status',
    'note',
    'payment_method',
    'visibility',
])]
class RecurringTransaction extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'interval' => 'integer',
            'start_date' => 'timestamp',
            'end_date' => 'timestamp',
            'next_due_date' => 'timestamp',
            'last_generated_at' => 'timestamp',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
            'deleted_at' => 'timestamp',
        ];
    }

    /**
     * Get the family that owns the recurring transaction template.
     *
     * @return BelongsTo<Family, $this>
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * Get the user who created the recurring transaction template.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the category for the recurring transaction.
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the generated transactions from this recurring template.
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Scope query to visible records for given user.
     *
     * @param  Builder<RecurringTransaction>  $query
     * @return Builder<RecurringTransaction>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('visibility', 'shared')
                ->orWhere('user_id', $user->id);
        });
    }

    /**
     * Scope query to active recurring templates.
     *
     * @param  Builder<RecurringTransaction>  $query
     * @return Builder<RecurringTransaction>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}

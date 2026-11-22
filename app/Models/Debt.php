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
 * @property string $person_name
 * @property string $type 'lent' or 'borrowed'
 * @property string $amount Decimal amount
 * @property string $settled_amount Decimal amount settled so far
 * @property int|string $date
 * @property int|string|null $due_date
 * @property string|null $note
 * @property string $visibility 'shared' or 'personal'
 * @property string $status 'pending', 'partially_settled', or 'settled'
 * @property int|string|null $settled_at
 * @property int|null $transaction_id
 * @property int|null $settlement_transaction_id
 * @property int|string|null $created_at
 * @property int|string|null $updated_at
 * @property int|string|null $deleted_at
 * @property-read float $remaining_amount
 */
#[Fillable([
    'family_id',
    'user_id',
    'person_name',
    'type',
    'amount',
    'settled_amount',
    'date',
    'due_date',
    'note',
    'visibility',
    'status',
    'settled_at',
    'transaction_id',
    'settlement_transaction_id',
])]
class Debt extends Model
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
            'settled_amount' => 'decimal:2',
            'date' => 'timestamp',
            'due_date' => 'timestamp',
            'settled_at' => 'timestamp',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
            'deleted_at' => 'timestamp',
        ];
    }

    /**
     * Get the family that owns the debt record.
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * Get the user who recorded the debt.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the initial transaction generated when the debt was logged.
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    /**
     * Get the settlement transaction generated when the debt was settled.
     */
    public function settlementTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'settlement_transaction_id');
    }

    /**
     * Get the settlement records generated for this debt.
     *
     * @return HasMany<DebtSettlement, $this>
     */
    public function settlements(): HasMany
    {
        return $this->hasMany(DebtSettlement::class, 'debt_id');
    }

    /**
     * Calculate remaining unsettled balance in base amount.
     */
    public function getRemainingAmountAttribute(): float
    {
        return max(0.0, round((float) $this->amount - (float) $this->settled_amount, 2));
    }

    /**
     * Scope query to partially settled records.
     */
    public function scopePartiallySettled(Builder $query): Builder
    {
        return $query->where('status', 'partially_settled');
    }

    /**
     * Scope query to lent records (money given to someone).
     */
    public function scopeLent(Builder $query): Builder
    {
        return $query->where('type', 'lent');
    }

    /**
     * Scope query to borrowed records (money received from someone).
     */
    public function scopeBorrowed(Builder $query): Builder
    {
        return $query->where('type', 'borrowed');
    }

    /**
     * Scope query to pending records.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope query to settled records.
     */
    public function scopeSettled(Builder $query): Builder
    {
        return $query->where('status', 'settled');
    }

    /**
     * Scope query to shared debts only.
     */
    public function scopeShared(Builder $query): Builder
    {
        return $query->where('visibility', 'shared');
    }

    /**
     * Scope query to personal debts only.
     */
    public function scopePersonal(Builder $query): Builder
    {
        return $query->where('visibility', 'personal');
    }

    /**
     * Scope query to debts visible to the given user:
     * shared debts OR user's own personal debts.
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

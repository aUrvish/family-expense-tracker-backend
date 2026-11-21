<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * Family tenant model representing a family unit within the application.
 *
 * Each family acts as an isolated tenant holding member users, transactions,
 * debts, and budgets.
 *
 * @property string $id Unique UUID string identifying the family tenant
 * @property string $name Display name of the family
 * @property string $currency Preferred family currency code
 * @property array<string, mixed>|null $data Additional tenant attributes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, User> $users
 * @property-read Collection<int, Transaction> $transactions
 * @property-read Collection<int, Debt> $debts
 * @property-read Collection<int, Budget> $budgets
 */
class Family extends Tenant
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'families';

    /**
     * Columns stored as real database columns.
     * Any other attribute is saved in the package's JSON `data` column.
     *
     * @return array<int, string>
     */
    public static function getCustomColumns(): array
    {
        return ['id', 'name', 'currency'];
    }

    /**
     * Get the effective currency code for this family.
     */
    public function effectiveCurrency(): string
    {
        return $this->currency ?: (string) config('app.base_currency', 'USD');
    }

    /**
     * Get the users that belong to this family.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the transactions for this family.
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Get the debts for this family.
     *
     * @return HasMany<Debt, $this>
     */
    public function debts(): HasMany
    {
        return $this->hasMany(Debt::class);
    }

    /**
     * Get the budgets for this family.
     *
     * @return HasMany<Budget, $this>
     */
    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }
}

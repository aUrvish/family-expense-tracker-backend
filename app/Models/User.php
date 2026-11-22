<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Tenant user model representing mobile and web application users.
 *
 * Belongs to a family tenancy context and authenticates via Sanctum API tokens
 * or the default `web` guard.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property string $role 'owner' or 'member'
 * @property string|null $family_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Family|null $family
 * @property-read Collection<int, Family> $families
 * @property-read Collection<int, Transaction> $transactions
 * @property-read Collection<int, Debt> $debts
 */
#[Fillable(['name', 'email', 'password', 'family_id', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Determine if this user is a family owner.
     *
     * Falls back to checking if the user is the earliest member in the family
     * to preserve backwards compatibility with existing families.
     */
    public function isOwner(): bool
    {
        if ($this->role === 'owner') {
            return true;
        }

        if (! $this->family_id) {
            return false;
        }

        return $this->id === (int) self::where('family_id', $this->family_id)->min('id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }

    /**
     * Get the family that the user belongs to.
     *
     * @return BelongsTo<Family, $this>
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * Get all families associated with this user's email.
     *
     * @return HasManyThrough<Family, self, $this>
     */
    public function families(): HasManyThrough
    {
        return $this->hasManyThrough(
            Family::class,
            self::class,
            'email',
            'id',
            'email',
            'family_id'
        )->orderBy('name');
    }

    /**
     * Get the transactions recorded by this user.
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Get the debts recorded by this user.
     *
     * @return HasMany<Debt, $this>
     */
    public function debts(): HasMany
    {
        return $this->hasMany(Debt::class);
    }
}

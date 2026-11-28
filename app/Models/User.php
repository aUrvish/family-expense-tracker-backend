<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Global user model representing mobile and web application users.
 *
 * A single user account (unique by email) may belong to multiple families via
 * the `family_user` pivot table, with a per-family `role` (`owner` or `member`).
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Family> $families
 * @property-read Collection<int, Transaction> $transactions
 * @property-read Collection<int, Debt> $debts
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Determine if this user is an owner in the active tenant family.
     */
    public function isOwner(): bool
    {
        $familyId = tenant('id');

        if (! $familyId) {
            return false;
        }

        return $this->families()
            ->where('families.id', $familyId)
            ->wherePivot('role', 'owner')
            ->exists();
    }

    /**
     * Get the user's role (`owner` or `member`) in the given family.
     */
    public function roleInFamily(string $familyId): ?string
    {
        $family = $this->families()
            ->where('families.id', $familyId)
            ->first();

        return $family?->pivot?->role;
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
     * Get all families this user belongs to.
     *
     * @return BelongsToMany<Family, $this>
     */
    public function families(): BelongsToMany
    {
        return $this->belongsToMany(Family::class, 'family_user')
            ->withPivot('role')
            ->withTimestamps()
            ->orderBy('name');
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

<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $family_id
 * @property int|null $invited_by_user_id
 * @property string $code
 * @property string $status 'active' or 'expired'
 * @property Carbon|int|string|null $created_at
 * @property Carbon|int|string|null $updated_at
 */
#[Fillable([
    'family_id',
    'invited_by_user_id',
    'code',
])]
class FamilyInvitation extends Model
{
    use HasFactory;

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = ['status'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }

    /**
     * Family that the invitation grants access to.
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * User who generated the invitation.
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * Check if the invitation has expired (hardcoded 48-hour window from creation).
     */
    public function isExpired(): bool
    {
        if ($this->created_at === null) {
            return false;
        }

        $createdAt = is_numeric($this->created_at)
            ? Carbon::createFromTimestamp($this->created_at)
            : Carbon::parse($this->created_at);

        return $createdAt->addHours(48)->isPast();
    }

    /**
     * Get computed status ('active' or 'expired').
     */
    public function getStatusAttribute(): string
    {
        return $this->isExpired() ? 'expired' : 'active';
    }

    /**
     * Check if the invitation is active and valid for registration.
     */
    public function isValid(): bool
    {
        return ! $this->isExpired();
    }

    /**
     * Scope query to active invitations.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('created_at', '>=', now()->subHours(48));
    }
}

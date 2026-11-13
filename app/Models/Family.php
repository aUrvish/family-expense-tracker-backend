<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Models\Tenant;

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
        return ['id', 'name'];
    }

    /**
     * Get the users that belong to this family.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the transactions for this family.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}

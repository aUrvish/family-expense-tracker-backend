<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\ListRecords;

/**
 * List page for viewing users in the Filament admin panel.
 */
class ListUsers extends ListRecords
{
    /**
     * The resource associated with the page.
     */
    protected static string $resource = UserResource::class;

    /**
     * Get the header actions available on the list page.
     *
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}

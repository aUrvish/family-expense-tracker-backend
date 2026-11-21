<?php

namespace App\Filament\Resources\Families\Pages;

use App\Filament\Resources\Families\FamilyResource;
use Filament\Resources\Pages\ListRecords;

/**
 * List page for viewing families in the Filament admin panel.
 */
class ListFamilies extends ListRecords
{
    /**
     * The resource associated with the page.
     */
    protected static string $resource = FamilyResource::class;

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

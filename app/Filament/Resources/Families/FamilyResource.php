<?php

namespace App\Filament\Resources\Families;

use App\Filament\Resources\Families\Pages\ListFamilies;
use App\Models\Family;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Filament resource for viewing all families and their member lists across tenants.
 */
class FamilyResource extends Resource
{
    /**
     * The model associated with the resource.
     */
    protected static ?string $model = Family::class;

    /**
     * The navigation icon for the resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    /**
     * The display label in the sidebar navigation.
     */
    protected static ?string $navigationLabel = 'Families';

    /**
     * Determine if a new record can be created.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Determine if the given record can be edited.
     */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Determine if the given record can be deleted.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Configure the form schema for the view modal.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Family Name')
                    ->readOnly(),
                TextInput::make('id')
                    ->label('Family UUID')
                    ->readOnly(),
                TextInput::make('created_at')
                    ->label('Registered At')
                    ->readOnly(),
                Repeater::make('users')
                    ->relationship('users')
                    ->label('Family Members')
                    ->schema([
                        TextInput::make('name')
                            ->label('Member Name')
                            ->readOnly(),
                        TextInput::make('email')
                            ->label('Email Address')
                            ->readOnly(),
                        TextInput::make('created_at')
                            ->label('Joined At')
                            ->readOnly(),
                    ])
                    ->columns(3)
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false),
            ]);
    }

    /**
     * Configure the data table for listing families.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Family Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('id')
                    ->label('Family UUID')
                    ->copyable()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('users_count')
                    ->counts('users')
                    ->label('Members')
                    ->badge()
                    ->color('primary')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Registered At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    /**
     * Get the relationship managers for the resource.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [];
    }

    /**
     * Get the navigation routes and pages for the resource.
     *
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListFamilies::route('/'),
        ];
    }
}

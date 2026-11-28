<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Filament resource for viewing users and their associated families across tenants.
 */
class UserResource extends Resource
{
    /**
     * The model associated with the resource.
     */
    protected static ?string $model = User::class;

    /**
     * The navigation icon for the resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    /**
     * The display label in the sidebar navigation.
     */
    protected static ?string $navigationLabel = 'Users';

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
                    ->label('User Name')
                    ->readOnly(),
                TextInput::make('email')
                    ->label('Email Address')
                    ->readOnly(),
                TextInput::make('created_at')
                    ->label('Registered At')
                    ->readOnly(),
            ]);
    }

    /**
     * Configure the data table for listing users.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('families'))
            ->columns([
                TextColumn::make('name')
                    ->label('User Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('families.name')
                    ->label('Families')
                    ->badge()
                    ->searchable()
                    ->placeholder('No Family'),
                TextColumn::make('created_at')
                    ->label('Registered At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('families')
                    ->relationship('families', 'name')
                    ->searchable()
                    ->preload()
                    ->label('Filter by Family'),
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
            'index' => ListUsers::route('/'),
        ];
    }
}

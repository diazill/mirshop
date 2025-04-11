<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryBarangResource\Pages;
use App\Filament\Resources\CategoryBarangResource\RelationManagers;
use App\Models\CategoryBarang;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\Section;

class CategoryBarangResource extends Resource
{
    protected static ?string $model = CategoryBarang::class;

    protected static ?string $navigationIcon = 'heroicon-o-folder';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([

                //card
                Section::make()
                    ->schema([

                        //name
                        Forms\Components\TextInput::make('category_name')
                            ->label('Category Name')
                            ->placeholder('Category Name')
                            ->required(),

                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('category_name')->searchable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategoryBarangs::route('/'),
            'create' => Pages\CreateCategoryBarang::route('/create'),
            'edit' => Pages\EditCategoryBarang::route('/{record}/edit'),
        ];
    }
}

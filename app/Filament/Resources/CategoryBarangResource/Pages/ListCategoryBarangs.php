<?php

namespace App\Filament\Resources\CategoryBarangResource\Pages;

use App\Filament\Resources\CategoryBarangResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCategoryBarangs extends ListRecords
{
    protected static string $resource = CategoryBarangResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

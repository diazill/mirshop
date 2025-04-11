<?php

namespace App\Filament\Resources\CategoryBarangResource\Pages;

use App\Filament\Resources\CategoryBarangResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCategoryBarang extends EditRecord
{
    protected static string $resource = CategoryBarangResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

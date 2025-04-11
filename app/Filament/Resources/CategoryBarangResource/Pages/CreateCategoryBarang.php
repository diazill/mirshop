<?php

namespace App\Filament\Resources\CategoryBarangResource\Pages;

use App\Filament\Resources\CategoryBarangResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateCategoryBarang extends CreateRecord
{
    protected static string $resource = CategoryBarangResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

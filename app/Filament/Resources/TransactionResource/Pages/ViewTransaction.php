<?php

namespace App\Filament\Resources\TransactionResource\Pages;

use App\Filament\Resources\TransactionResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewTransaction extends ViewRecord
{
    protected static string $resource = TransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TransactionResource::reviewAction(Actions\Action::class)
                ->after(fn () => $this->redirect(TransactionResource::getUrl('view', ['record' => $this->record]))),
            TransactionResource::cancelAction(Actions\Action::class)
                ->after(fn () => $this->redirect(TransactionResource::getUrl('view', ['record' => $this->record]))),
        ];
    }
}

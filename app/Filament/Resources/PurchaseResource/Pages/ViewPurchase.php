<?php

namespace App\Filament\Resources\PurchaseResource\Pages;

use App\Filament\Actions\PurchaseActions;
use App\Filament\Resources\PurchaseResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchase extends ViewRecord
{
    protected static string $resource = PurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PurchaseActions::receive(Actions\Action::class)->after(fn () => $this->reloadPage()),
            Actions\EditAction::make(),
            PurchaseActions::cancel(Actions\Action::class)->after(fn () => $this->reloadPage()),
        ];
    }

    private function reloadPage(): void
    {
        $this->redirect(PurchaseResource::getUrl('view', ['record' => $this->record]));
    }
}

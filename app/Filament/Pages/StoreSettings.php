<?php

namespace App\Filament\Pages;

use App\Support\StoreProfile;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Info toko untuk kepala struk kasir (GET /v1/store) dan zona waktu toko.
 * Hanya owner.
 *
 * @property Form $form
 */
class StoreSettings extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?string $navigationGroup = 'Pengaturan';
    protected static ?string $navigationLabel = 'Pengaturan Toko';
    protected static ?string $title = 'Pengaturan Toko';
    protected static ?string $slug = 'pengaturan-toko';
    protected static ?int $navigationSort = -1;
    protected static string $view = 'filament.pages.store-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('owner');
    }

    public function mount(): void
    {
        $this->form->fill(StoreProfile::all());
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\Section::make('Kepala struk')
                    ->description('Dicetak di struk kasir. Aplikasi HP mengambilnya saat online/sinkron.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('store_name')->label('Nama toko')->required()->maxLength(100),
                        Forms\Components\TextInput::make('store_phone')->label('Telepon / WhatsApp')->tel()->maxLength(30),
                        Forms\Components\Textarea::make('store_address')->label('Alamat')->rows(2)->maxLength(300)->columnSpanFull(),
                        Forms\Components\TextInput::make('receipt_footer')
                            ->label('Catatan kaki struk')
                            ->maxLength(200)
                            ->placeholder('Terima kasih')
                            ->columnSpanFull(),
                        Forms\Components\FileUpload::make('store_logo')
                            ->label('Logo (opsional)')
                            ->image()
                            ->disk('s3')
                            ->directory('toko')
                            ->maxSize(1024)
                            ->helperText('Disarankan hitam putih, lebar ±384 px, agar jelas di printer struk.'),
                    ]),
                Forms\Components\Section::make('Waktu')
                    ->schema([
                        Forms\Components\Select::make('store_timezone')
                            ->label('Zona waktu toko')
                            ->options(StoreProfile::TIMEZONES)
                            ->required()
                            ->selectablePlaceholder(false)
                            ->helperText('Menentukan "hari ini" pada ringkasan penjualan kasir.'),
                    ]),
            ]);
    }

    public function save(): void
    {
        StoreProfile::save($this->form->getState());

        Notification::make()->success()->title('Pengaturan toko disimpan.')->send();
    }
}

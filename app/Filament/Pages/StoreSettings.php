<?php

namespace App\Filament\Pages;

use App\Models\StoreClosure;
use App\Models\StoreSchedule;
use App\Support\AuditLogger;
use App\Support\StoreProfile;
use Carbon\CarbonImmutable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Info toko: kepala struk kasir (GET /v1/store), toko online (GET /v1/store/public),
 * jam buka mingguan, libur khusus, dan zona waktu. Hanya owner.
 *
 * @property Form $form
 */
class StoreSettings extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    public const DAYS = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

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
        $saved = StoreSchedule::query()->orderBy('id')->get()->unique('day_of_week')->keyBy('day_of_week');

        // Jadwal belum pernah diatur: isi form dengan Senin–Sabtu 09.00–17.00, Minggu tutup (belum disimpan).
        $schedule = collect(self::DAYS)->mapWithKeys(fn (string $name, int $day): array => [$day => $saved->isEmpty()
            ? ['is_open' => $day !== 7, 'open_time' => '09:00', 'close_time' => '17:00']
            : [
                'is_open' => (bool) ($saved->get($day) && ! $saved->get($day)->is_closed),
                'open_time' => substr((string) ($saved->get($day)?->open_time ?? '09:00'), 0, 5),
                'close_time' => substr((string) ($saved->get($day)?->close_time ?? '17:00'), 0, 5),
            ]])->all();

        $closures = StoreClosure::query()
            ->whereDate('date', '>=', CarbonImmutable::now(StoreProfile::timezone())->toDateString())
            ->orderBy('date')
            ->get()
            ->map(fn (StoreClosure $c): array => ['date' => $c->date->toDateString(), 'reason' => $c->reason])
            ->all();

        $this->form->fill(StoreProfile::all() + ['schedule' => $schedule, 'closures' => $closures]);
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
                        Forms\Components\TextInput::make('store_phone')->label('Telepon')->tel()->maxLength(30),
                        Forms\Components\Textarea::make('store_address')->label('Alamat di struk')->rows(2)->maxLength(300)->columnSpanFull(),
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

                Forms\Components\Section::make('Toko online')
                    ->description('Tampil di website toko (header, footer, halaman Lokasi dan Kontak, tombol WhatsApp). Kosongkan bila tidak ingin ditampilkan.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('store_whatsapp')
                            ->label('Nomor WhatsApp')
                            ->tel()
                            ->maxLength(30)
                            ->helperText('Mis. 0851 5542 8611. Dipakai untuk tombol "Pesan via WhatsApp".'),
                        Forms\Components\TextInput::make('store_email')->label('Email')->email()->maxLength(100),
                        Forms\Components\TextInput::make('store_street')->label('Jalan')->maxLength(200)->columnSpanFull(),
                        Forms\Components\TextInput::make('store_landmark')->label('Patokan')->maxLength(100)->placeholder('Sebelah …'),
                        Forms\Components\TextInput::make('store_city')->label('Kota/kabupaten')->maxLength(100),
                        Forms\Components\TextInput::make('store_province')->label('Provinsi')->maxLength(100),
                        Forms\Components\TextInput::make('store_postal_code')->label('Kode pos')->maxLength(10),
                        Forms\Components\TextInput::make('store_maps_url')->label('Link Google Maps')->url()->maxLength(300)->columnSpanFull(),
                        Forms\Components\TextInput::make('store_latitude')->label('Latitude')->numeric()->minValue(-90)->maxValue(90),
                        Forms\Components\TextInput::make('store_longitude')->label('Longitude')->numeric()->minValue(-180)->maxValue(180),
                    ]),

                Forms\Components\Section::make('Jam buka')
                    ->description('Status "Buka sekarang" di toko online dihitung dari jadwal ini dan zona waktu toko.')
                    ->schema([
                        ...collect(self::DAYS)->map(fn (string $name, int $day) => Forms\Components\Grid::make(3)->schema([
                            Forms\Components\Toggle::make("schedule.{$day}.is_open")->label($name)->inline(false)->live(),
                            Forms\Components\TimePicker::make("schedule.{$day}.open_time")
                                ->label('Buka')
                                ->seconds(false)
                                ->required(fn (Get $get): bool => (bool) $get("schedule.{$day}.is_open"))
                                ->visible(fn (Get $get): bool => (bool) $get("schedule.{$day}.is_open")),
                            Forms\Components\TimePicker::make("schedule.{$day}.close_time")
                                ->label('Tutup')
                                ->seconds(false)
                                ->required(fn (Get $get): bool => (bool) $get("schedule.{$day}.is_open"))
                                ->visible(fn (Get $get): bool => (bool) $get("schedule.{$day}.is_open"))
                                ->after("schedule.{$day}.open_time")
                                ->validationMessages(['after' => 'Jam tutup harus setelah jam buka.']),
                        ]))->values()->all(),
                        Forms\Components\Select::make('store_timezone')
                            ->label('Zona waktu toko')
                            ->options(StoreProfile::TIMEZONES)
                            ->required()
                            ->selectablePlaceholder(false)
                            ->helperText('Juga menentukan "hari ini" pada ringkasan penjualan kasir.'),
                    ]),

                Forms\Components\Section::make('Libur khusus')
                    ->description('Tanggal toko tutup di luar jadwal mingguan (mis. hari raya). Tampil di toko online 60 hari sebelumnya.')
                    ->schema([
                        Forms\Components\Repeater::make('closures')
                            ->hiddenLabel()
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Tambah tanggal libur')
                            ->schema([
                                Forms\Components\DatePicker::make('date')
                                    ->label('Tanggal')
                                    ->required()
                                    ->native(false)
                                    ->minDate(fn () => CarbonImmutable::now(StoreProfile::timezone())->startOfDay())
                                    ->distinct(),
                                Forms\Components\TextInput::make('reason')->label('Keterangan')->maxLength(200)->placeholder('Libur Idul Fitri'),
                            ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        DB::transaction(function () use ($state): void {
            $before = $this->hoursSnapshot();
            StoreProfile::save($state); // tiap key yang berubah tercatat sendiri di audit log (model Setting)

            foreach (self::DAYS as $day => $name) {
                $row = $state['schedule'][$day] ?? [];
                $open = (bool) ($row['is_open'] ?? false);

                StoreSchedule::query()->where('day_of_week', $day)->delete();
                StoreSchedule::query()->create([
                    'day_of_week' => $day,
                    'open_time' => $open ? substr((string) $row['open_time'], 0, 5) : '00:00',
                    'close_time' => $open ? substr((string) $row['close_time'], 0, 5) : '00:00',
                    'is_closed' => ! $open,
                ]);
            }

            // Libur yang sudah lewat disimpan sebagai riwayat; yang akan datang mengikuti isi form.
            $today = CarbonImmutable::now(StoreProfile::timezone())->toDateString();
            StoreClosure::query()->whereDate('date', '>=', $today)->delete();

            foreach ($state['closures'] ?? [] as $closure) {
                StoreClosure::query()->create([
                    'date' => CarbonImmutable::parse($closure['date'])->toDateString(),
                    'reason' => blank($closure['reason'] ?? null) ? null : $closure['reason'],
                ]);
            }

            // Jadwal disimpan ulang seluruhnya; audit cukup satu baris berisi yang benar-benar berubah.
            $after = $this->hoursSnapshot();
            $changes = collect($after)
                ->filter(fn (string $value, string $key): bool => ($before[$key] ?? null) !== $value)
                ->mapWithKeys(fn (string $value, string $key): array => [$key => [$before[$key] ?? null, $value]])
                ->all();

            if ($changes !== []) {
                AuditLogger::record(action: 'updated', subjectType: 'store_hours', subjectLabel: 'Jam buka & libur', changes: $changes);
            }
        });

        Notification::make()->success()->title('Pengaturan toko disimpan.')->send();
    }

    /** @return array<string, string> {"Senin": "09:00–17:00", …, "Libur": "2026-12-25 Natal, …"} */
    private function hoursSnapshot(): array
    {
        $rows = StoreSchedule::query()->orderBy('id')->get()->unique('day_of_week')->keyBy('day_of_week');
        $today = CarbonImmutable::now(StoreProfile::timezone())->toDateString();

        $snapshot = collect(self::DAYS)->mapWithKeys(function (string $name, int $day) use ($rows): array {
            $row = $rows->get($day);

            return [$name => match (true) {
                $row === null => 'belum diatur',
                $row->is_closed => 'tutup',
                default => substr((string) $row->open_time, 0, 5) . '–' . substr((string) $row->close_time, 0, 5),
            }];
        })->all();

        $snapshot['Libur'] = StoreClosure::query()
            ->whereDate('date', '>=', $today)
            ->orderBy('date')
            ->get()
            ->map(fn (StoreClosure $c): string => trim($c->date->toDateString() . ' ' . $c->reason))
            ->implode(', ') ?: '-';

        return $snapshot;
    }
}

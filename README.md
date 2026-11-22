# MirShop Dashboard

Dashboard admin dan API untuk toko pakan ternak, unggas, dan makanan hewan peliharaan. Proyek ini adalah **sumber data tunggal** untuk tiga aplikasi:

| Aplikasi | Pengguna | Memakai |
|---|---|---|
| **Dashboard admin** (proyek ini, Filament) | Owner, admin | Panel web di `/admin` |
| **Aplikasi HP staf** (Flutter, offline-first) | Kasir, staf gudang | API `/api/v1` dengan token login |
| **Toko online** (Next.js) | Pembeli | API publik `/api/v1/products` |

Aplikasi lain tidak boleh terhubung langsung ke database. Semua akses data lewat API ini.

## Fitur

**Produk dan stok**
- Satuan dinamis per produk (Pcs, Kg, Karung, Bungkus, Dus, …) dengan konversi ke satuan dasar. Stok disimpan satu angka dalam satuan dasar dan boleh desimal (mis. jual 2,5 kg dari karung).
- Harga eceran/grosir per satuan dengan minimal jumlah, barcode per satuan, foto produk.
- Riwayat mutasi stok yang tidak bisa diubah, koreksi stok dengan alasan, stok opname, dan perintah cek kecocokan stok.

**Pembelian**
- Pembelian ke pemasok, penerimaan barang, HPP rata-rata bergerak, pembatalan yang mengembalikan stok.

**Penjualan kasir**
- Nota online dengan harga dihitung server, idempotensi (`Idempotency-Key`) agar kiriman ulang tidak tercatat dua kali.
- Pembayaran tunai (dengan kembalian), transfer, QRIS. Pembatalan hanya oleh owner, dan stok dikembalikan.
- Pelanggan (opsional di nota), info toko untuk kepala struk, ringkasan penjualan harian per metode dan per jam.

**Aplikasi HP offline-first**
- Perangkat terdaftar; token login terikat ke perangkat dan bisa dicabut.
- Sinkron katalog (lengkap atau perubahan sejak sinkron terakhir, termasuk data terhapus).
- Kirim banyak nota offline sekaligus. Nota yang harganya beda, di bawah HPP, membuat stok minus, terlambat dikirim, atau pelanggannya sudah nonaktif tetap diterima tetapi ditandai untuk ditinjau owner.

**Gudang (aplikasi HP)**
- Daftarkan barcode, opname, koreksi stok, upload foto produk.

**Keamanan**
- Peran: `owner`, `admin`, `kasir`, `gudang`. Hanya owner/admin yang bisa masuk panel.
- Login dengan username atau email, user/perangkat nonaktif langsung ditolak, rate limit per endpoint.
- Format error API seragam berbahasa Indonesia.

## Teknologi

- PHP 8.3, Laravel 12, Filament 3, Livewire
- Laravel Sanctum (token API), Scramble (dokumentasi OpenAPI)
- PostgreSQL (produksi), SQLite in-memory (tes)
- Penyimpanan file S3-compatible untuk foto produk
- `brick/math` untuk perhitungan jumlah desimal

## Menjalankan di lokal

Kebutuhan: PHP 8.3 dengan ekstensi `pdo_pgsql` (atau `pdo_sqlite`), Composer, Node.js.

```bash
composer install
npm install && npm run build

cp .env.example .env
php artisan key:generate
# isi koneksi database dan penyimpanan di .env (lihat bagian Konfigurasi)

php artisan migrate
php artisan serve
```

Buat akun pertama lalu jadikan owner:

```bash
php artisan make:filament-user
php artisan users:assign-role <email> owner
```

Panel admin: `http://localhost:8000/admin`.

## Konfigurasi

Variabel penting di `.env` (isi sesuai lingkungan masing-masing; **jangan pernah commit `.env`**):

| Variabel | Keterangan |
|---|---|
| `APP_URL` | URL dashboard/API |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Database (PostgreSQL di produksi) |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET` | Penyimpanan foto (disk `s3`) |
| `AWS_ENDPOINT`, `AWS_URL`, `AWS_USE_PATH_STYLE_ENDPOINT` | Wajib untuk penyimpanan S3-compatible selain AWS. `AWS_URL` = URL publik bucket untuk menampilkan foto |
| `CACHE_STORE` | Disarankan `file` atau `redis` bila database jauh dari server (rate limiter memakai cache) |

Zona waktu aplikasi tetap UTC. Zona waktu toko (WIB/WITA/WIT) diatur owner di **Pengaturan → Pengaturan Toko**.

## Tes

```bash
php artisan test
```

Tes memakai SQLite in-memory (`RefreshDatabase`), jadi tidak menyentuh database asli.

## API

Semua endpoint di bawah prefix `/api/v1`. Dokumentasi lengkap (Scramble) tersedia di `/docs/api` untuk owner/admin yang sudah login di panel.

| Grup | Endpoint | Akses |
|---|---|---|
| Katalog publik | `GET /products`, `GET /products/{slug}`, `GET /categories`, `GET /brands`, `GET /store/public` | Publik (di-cache 60 detik) |
| Login | `POST /auth/login`, `POST /auth/logout`, `GET /me` | Staf |
| Kasir | `GET /barcode/{barcode}`, `GET/POST /pos/transactions`, `GET /pos/transactions/{id}`, `POST /pos/transactions/{id}/cancel`, `GET /pos/summary`, `GET /store` | Owner, admin, kasir (batal: owner) |
| Pelanggan | `GET/POST /customers`, `GET /customers/{id}` | Owner, admin, kasir |
| Sinkron HP | `GET /sync/catalog`, `POST /sync/transactions` | Token dari perangkat terdaftar |
| Gudang | `PUT /product-units/{id}/barcode`, `POST /stock/opname`, `POST /stock/adjust`, `POST /products/{id}/images` | Owner, admin, gudang |

Format error:

```json
{ "code": "STOK_TIDAK_CUKUP", "message": "…", "details": { } }
```

Endpoint yang membuat data (nota, pelanggan, opname, koreksi stok) wajib membawa header `Idempotency-Key` (UUID unik per kiriman).

## Perintah artisan

| Perintah | Fungsi |
|---|---|
| `php artisan stock:verify [--product=ID]` | Cek apakah stok produk cocok dengan riwayat mutasinya |
| `php artisan users:assign-role <email> <role>` | Ubah peran user (`owner`, `admin`, `kasir`, `gudang`) |

## Struktur kode

```
app/
  Enums/               Peran, jenis mutasi, metode bayar, status
  Exceptions/          Aturan bisnis → error API {code, message, details}
  Filament/            Panel admin (resource, halaman, aksi, widget)
  Http/Controllers/Api/V1/   Endpoint API
  Http/Middleware/     User aktif, peran, perangkat terdaftar
  Models/
  Services/            Logika bisnis: StockService, PurchaseService,
                       TransactionService, PricingService, CustomerService, …
  Support/             Helper (format rupiah, URL foto, profil toko)
database/migrations/
tests/Feature/         Tes alur bisnis dan API
```

Aturan utama: stok hanya berubah lewat `StockService` (selalu tercatat di riwayat mutasi), dan penjualan/pembelian hanya lewat service-nya masing-masing, dalam satu transaksi database.

## Deploy

1. `git pull`
2. `composer install --no-dev --optimize-autoloader`
3. `php artisan migrate --force`
4. `php artisan optimize`
5. `npm ci && npm run build` bila ada perubahan aset

Pastikan `APP_ENV=production` dan `APP_DEBUG=false` di server.

## Lisensi

Proyek privat. Tidak untuk didistribusikan.

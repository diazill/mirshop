# PRD 01: Admin dan Backend (Laravel + Filament) — Checklist Implementasi

Dokumen ini adalah penyesuaian PRD pada kondisi proyek yang ada saat ini di repositori ini. Tujuannya adalah memetakan fitur yang sudah ada, yang masih parsial, dan yang belum dibuat berdasarkan PRD.

Status umum proyek:
- Status: `fondasi awal` / `sebagian siap`; audit database selesai, fondasi auth/API mulai diimplementasikan.
- Repositori saat ini sudah memiliki aplikasi Laravel + Filament dengan model dan migrasi dasar.
- Fitur admin master data sudah mulai dibangun, tetapi belum memenuhi banyak kebutuhan PRD seperti API, peran, stok atomik, pesanan online, kasir, dan pembayaran.

## 1. Ringkasan status

| Area | Status | Catatan |
|---|---|---|
| Laravel + Filament foundation | ✅ Sudah ada | Aplikasi bisa dijalankan sebagai admin panel Laravel + Filament |
| Master data dasar | ✅ Sudah ada | Brand, category, product, supplier, user |
| Struktur transaksi dasar | ✅ Sudah ada | `transactions`, `transaction_items`, `purchases`, `purchase_items` |
| Inventory base | ✅ Sudah ada | `inventory_movements` dan stok dasar tersedia |
| API Laravel | 🟡 Fondasi | API v1 mulai disiapkan untuk login token, profil, dan logout; endpoint bisnis belum ada |
| Peran/izin role-based access | 🟡 Fondasi | Role owner/admin/kasir/gudang dan pembatasan panel dasar disiapkan; izin per fitur belum lengkap |
| Order / payment flow | ❌ Belum ada | Tidak ada service/order lifecycle |
| POS & kasir workflow | ❌ Belum ada | Belum ada transaksi POS yang sesuai PRD |
| Customer / receivable / subscription | ❌ Belum ada | Belum ada modul yang sesuai |
| Dashboard laporan & analytics | ❌ Belum ada | Belum ada dashboard PRD |

## 2. Bukti implementasi yang sudah ada

### 2.1 Aplikasi admin panel
- `app/Providers/Filament/AdminPanelProvider.php`
- `app/Filament/Pages/Auth/Login.php`

Catatan:
- Panel admin aktif di path `/admin`.
- Login Filament sudah disiapkan.
- Dashboard default Filament ada.

### 2.2 Master data
- `app/Filament/Resources/BrandResource.php`
- `app/Filament/Resources/CategoryResource.php`
- `app/Filament/Resources/ProductResource.php`
- `app/Filament/Resources/SupplierResource.php`
- `app/Filament/Resources/UserResource.php`

Catatan:
- CRUD dasar untuk brand, category, product, supplier, user sudah ada.
- Produk memiliki field seperti sku, title, category, brand, price, cost_price, stock, is_active, is_best_product.

### 2.3 Model dan migrasi dasar
- `app/Models/Product.php`
- `app/Models/Brand.php`
- `app/Models/Category.php`
- `app/Models/Supplier.php`
- `app/Models/Transaction.php`
- `app/Models/TransactionItem.php`
- `app/Models/Purchase.php`
- `app/Models/PurchaseItem.php`
- `app/Models/InventoryMovement.php`
- `app/Models/StoreSchedule.php`

Database migration yang tersedia:
- `database/migrations/2026_05_31_151742_create_categories_table.php`
- `database/migrations/2026_05_31_151744_create_brands_table.php`
- `database/migrations/2026_05_31_151744_create_suppliers_table.php`
- `database/migrations/2026_05_31_151745_create_products_table.php`
- `database/migrations/2026_05_31_151746_create_purchases_table.php`
- `database/migrations/2026_05_31_151747_create_purchase_items_table.php`
- `database/migrations/2026_05_31_151747_create_transactions_table.php`
- `database/migrations/2026_05_31_151748_create_transaction_items_table.php`
- `database/migrations/2026_05_31_151749_create_inventory_movements_table.php`
- `database/migrations/2026_05_31_151750_create_store_schedules_table.php`

Catatan:
- Struktur dasar untuk produk, pembelian, transaksi, mutasi stok, dan jadwal toko sudah dibuat.
- Namun belum terlihat implementasi untuk fitur lanjutan yang disebut dalam PRD seperti `channel`, `status`, `payments`, `customers`, `shipments`, dan `idempotency_key`.

### 2.4 Audit skema database aktual

Audit dilakukan pada database yang dikonfigurasi untuk proyek ini; `php artisan migrate:status` menunjukkan semua migrasi proyek yang ada sudah berjalan. Tabel berikut diperiksa langsung:

| Tabel | Kolom/kemampuan yang terkonfirmasi | Gap utama terhadap PRD |
|---|---|---|
| `public.users` | `id`, `name`, `email`, `email_verified_at`, `password`, `remember_token`, timestamps, `username` | Belum ada `role` dan status aktif sebelum migrasi fondasi baru |
| `products` | SKU, judul, deskripsi, harga jual, harga beli, berat, stok saat ini, aktif, brand, kategori, soft delete | Belum ada satuan/harga bertingkat, barcode, stok minimum, atau slug |
| `transactions` | Nomor invoice, nama kasir, total, metode pembayaran | Belum ada kanal, status, pelanggan, kunci idempotensi |
| `transaction_items` | Jumlah, harga/HPP saat transaksi, subtotal, produk dan transaksi | Belum ada satuan/konversi |
| `inventory_movements` | Jenis mutasi, reference ID, jumlah perubahan, stok setelah, produk | Belum ada alasan wajib, pelaku, dan referensi yang kuat ke dokumen sumber |
| `purchases` / `purchase_items` | Supplier, nomor/tanggal pembelian, total, item, jumlah diterima, harga beli | Belum ada status penerimaan; belum ada alur otomatis mutasi stok saat diterima |
| `store_schedules` | Hari, jam buka/tutup, status tutup | Info toko, pembayaran, area/biaya pengiriman belum tersedia |

Database juga memiliki tabel `auth.users` pada schema autentikasi terpisah. Aplikasi Laravel tetap menggunakan `public.users`; jangan menghubungkan model Laravel ke `auth.users` atau bergantung pada autentikasi eksternal.

Fondasi yang dibuat setelah audit:
- Migrasi additive untuk `users.role` dan `users.is_active`; role lama/default diisi `admin`, akun tetap aktif.
- Sanctum untuk token API; API v1 menyediakan login, profil, dan logout.
- Akses Filament dibatasi untuk owner/admin aktif; tersedia middleware role dan status aktif untuk API.
- Owner awal harus ditetapkan operator setelah migrasi dengan `php artisan users:assign-role {email} owner`.
- Migrasi role dan token sudah diterapkan ke database yang dikonfigurasi.

## 3. Checklist berdasarkan PRD

### 3.1 Fase 0: Fondasi
- [x] Laravel app base
- [x] Filament admin panel
- [x] Basic database schema foundation
- [x] Users table + basic user resource
- [x] Audit skema aktual untuk tabel inti
- [ ] Tambah seluruh kolom/table bisnis yang belum sesuai PRD
- [x] Fondasi peran owner/admin/kasir/gudang dan status aktif (migrasi perlu dijalankan)
- [x] Laravel Sanctum dan API auth dasar (login, profil, logout)
- [ ] Service layer (PricingService, StockService, OrderService, PaymentService) — StockService selesai
- [x] Struktur API v1 untuk autentikasi dasar
- [ ] Endpoint produk, pesanan, kasir, stok, dan pelanggan

### 3.2 ADM-01: Produk
- [x] CRUD brand
- [x] CRUD category
- [x] CRUD supplier
- [x] CRUD product
- [x] Product images
- [x] Product price field
- [x] Product cost_price
- [x] Product current_stock
- [x] Satuan produk dinamis dan harga bertingkat (`units`, `product_units`, `product_prices`) — skema + form admin; logika harga menunggu PricingService
- [x] Barcode per satuan produk (unik)
- [x] Stok minimum (`min_stock`)
- [ ] Akses publik vs internal sesuai harga beli rahasia
- [ ] Fitur aktif/nonaktif untuk toko online yang konsisten dengan API publik

### 3.3 ADM-02: Stok
- [x] Tabel inventory movements ada
- [x] Produk punya stok dasar
- [x] Riwayat mutasi stok lengkap dan auditable (menu Stok → Riwayat Stok; mutasi tidak bisa diubah/dihapus)
- [x] Koreksi manual harus ada alasan wajib (aksi Koreksi stok & Opname di tabel produk)
- [ ] Peringatan stok menipis (sementara: kolom stok di tabel produk berwarna merah bila stok <= `min_stock`)
- [x] Penguncian baris / transaksi stok atomik (`StockService`, `lockForUpdate`)
- [x] Log pengguna dan waktu tiap perubahan stok
- [x] Batas stok negatif sesuai izin owner (default ditolak; owner bisa mengizinkan saat koreksi)

### 3.4 ADM-03: Pembelian
- [x] Tabel purchases dan purchase_items ada
- [x] Form pembelian lengkap sesuai alur masuk stok (menu Stok → Pembelian; item per satuan, harga beli per satuan, subtotal/total otomatis)
- [x] Status pembelian dan penerimaan barang (draft → diterima / dibatalkan; tidak ada hapus permanen)
- [x] Pencatatan stock-in saat status diterima (`PurchaseService::receive`, semua item atomik + HPP rata-rata tertimbang)
- [x] Audit supplier history (dibuat/diterima/dibatalkan oleh siapa & kapan, alasan batal)

### 3.4 ADM-04: Pemasok
- [x] CRUD supplier
- [x] Riwayat pembelian per supplier lengkap (tab Riwayat pembelian + kolom jumlah & total dibeli)
- [ ] Integrasi ke modul pembelian dan laporan — modul pembelian selesai, laporan belum

### 3.5 ADM-05: Pesanan online
- [ ] Daftar pesanan online
- [ ] Filter status pesanan
- [ ] Ubah status pesanan sesuai flow PRD
- [ ] Verifikasi bukti transfer
- [ ] Catatan internal
- [ ] Cetak/unduh faktur
- [ ] Riwayat status penuh
- [ ] Notifikasi ke customer/admin

### 3.6 ADM-06: Transaksi kasir
- [ ] Daftar transaksi POS
- [ ] Pembatalan transaksi sesuai izin owner
- [ ] Retur barang/retur transaksi
- [ ] Stok balik otomatis saat pembatalan/retur
- [ ] Validasi hak akses owner/admin

### 3.7 ADM-07: Pelanggan
- [ ] Modul pelanggan
- [ ] Riwayat belanja pelanggan
- [ ] Harga langganan / customer pricing
- [ ] Saldo piutang / bon

### 3.8 ADM-08: Pengaturan toko
- [ ] Store schedules flow lengkap
- [ ] Informasi toko
- [ ] Metode pembayaran
- [ ] Area dan biaya antar
- [ ] Nomor WhatsApp admin
- [ ] Pengaruh langsung ke toko online melalui API

### 3.9 ADM-09: Pengguna dan peran
- [x] User resource dasar
- [x] Fondasi role-based access control
- [x] Owner/Admin distinction untuk akses Filament
- [x] Mencegah login API bagi user nonaktif
- [ ] Granular permissions per fitur dan endpoint bisnis

### 3.10 ADM-10: Dashboard
- [ ] Dashboard penjualan per kanal
- [ ] Dashboard pesanan menunggu tindakan
- [ ] Dashboard stok rendah
- [ ] Dashboard produk terlaris
- [ ] Dashboard sesuai data transaksi yang sebenarnya

### 3.11 Keputusan desain satuan (toko pakan)
- Stok disimpan `decimal(12,3)` dalam satuan dasar produk; pakan curah memakai satuan dasar Kg, kemasan pabrik memakai Bungkus/Pcs sebagai produk tersendiri.
- `product_units.conversion_qty`: 1 satuan = N satuan dasar (mis. Karung = 50 Kg); `allow_decimal_qty` menentukan boleh tidaknya jumlah pecahan.
- `products.price`/`cost_price` = harga per 1 satuan dasar. Satuan tanpa harga khusus memakai harga dasar × isi (aturan untuk PricingService).
- Satu stok per produk (tidak membedakan karung tersegel vs curah); fitur "pecah karung" bisa ditambah nanti.
- Transaksi/pembelian/mutasi wajib menyimpan snapshot satuan (`unit_name`, `conversion_qty`, `base_qty`) — dikerjakan bersama StockService.
- Migrasi memberi produk lama satuan dasar Pcs; admin perlu meninjau dan mengganti produk curah ke Kg sebelum ada mutasi stok.

## 4. Fitur backend / API PRD

### 4.1 Layanan inti
- [x] API auth dasar dengan Sanctum
- [ ] Pendaftaran akun toko online dan autentikasi cookie Next.js
- [ ] PricingService
- [x] StockService (`increase`, `decrease`, `adjust`, `opname`)
- [ ] OrderService
- [ ] PaymentService
- [ ] Queue/notification jobs
- [ ] Scheduled tasks untuk pembatalan otomatis

### 4.2 Endpoint API publik
- [ ] `GET /products`
- [ ] `GET /products/{slug}`
- [ ] `GET /categories`
- [ ] `GET /store/schedule`
- [ ] `POST /register`
- [ ] `POST /login`
- [ ] `GET /me`
- [ ] `GET/POST /addresses`
- [ ] `POST /orders`
- [ ] `GET /orders`
- [ ] `GET /orders/{id}`
- [ ] `POST /orders/{id}/payment-proof`

### 4.3 Endpoint kasir
- [ ] `POST /pos/transactions`
- [ ] `GET /pos/transactions`
- [ ] `GET /pos/orders`
- [ ] `PATCH /pos/orders/{id}/status`
- [ ] `GET /stock/{barcode}`
- [ ] `POST /stock/receive`
- [ ] `POST /stock/adjust`
- [ ] `POST /stock/opname`
- [ ] `GET /customers`
- [ ] `POST /customers`

## 5. Aturan bisnis kunci PRD

- [x] Stok atomik dengan transaction + row locking
- [x] Satuan dasar dan konversi satuan (stok desimal dalam satuan dasar, satuan dasar terkunci setelah ada mutasi)
- [ ] Harga tercatat saat transaksi
- [ ] Harga beli rahasia untuk owner/admin tertentu
- [ ] Pembatalan & retur mencatat stok kebalikan — pembatalan pembelian selesai (owner, mutasi PURCHASE_CANCEL); penjualan/retur belum
- [ ] Status pesanan flow lengkap
- [ ] Tidak ada penghapusan permanen untuk transaksi/pembelian/stok
- [ ] `Idempotency-Key` diterapkan pada endpoint pembuatan transaksi
- [ ] Error handling dengan format seragam
- [x] Kode `STOK_TIDAK_CUKUP` tersedia (JSON 422 `{code, message, details}`)

## 6. Kebutuhan non-fungsional PRD

- [ ] Security / authorization strict
- [ ] HTTPS
- [ ] Server-side validation
- [ ] Rate limit login / checkout
- [ ] Secrets hanya di `.env`
- [ ] Backup DB schedule
- [ ] Queue jobs for async tasks
- [ ] Error logging
- [ ] Database portability (non-Supabase specific)
- [ ] Audit log for changes
- [ ] UI Filament in Indonesian

## 7. Prioritas implementasi berikutnya

### Prioritas 1: Fondasi backend yang benar
1. Audit skema database rutin dan sinkronkan dengan PRD.
2. Tambah tabel/kolom yang belum ada.
3. Buat role/permission owner/admin/staf.
4. Siapkan Laravel Sanctum dan auth API.
5. Implementasikan service layer dasar.

### Prioritas 2: Master data dan stok
1. Add `product_units`, `product_prices`, `min_stock`, `barcode`.
2. Implementasikan stok atomik dan `inventory_movements` secara terstruktur.
3. Lengkapi pembelian dan penerimaan stok.

### Prioritas 3: Order + payment + kasir
1. Order flow online.
2. Payment proof.
3. POS transaksi, retur, dan pembatalan.
4. Customer + receivable.

### Prioritas 4: Dashboard & reporting
1. Dashboard admin.
2. Laporan penjualan dan stok.
3. Laporan piutang dan laba rugi.
4. Log aktivitas, notifikasi, dan tugas terjadwal.

## 8. Kesimpulan

Repositori saat ini sudah masuk tahap `fondasi awal` sesuai PRD:
- admin panel dan CRUD master data dasar sudah ada,
- struktur database utama untuk produk, pembelian, transaksi, stok, dan jadwal toko juga sudah ada,
- tetapi banyak komponen inti PRD masih belum dibuat atau belum lengkap.

Dengan kata lain, proyek ini sudah punya pondasi teknis, namun masih perlu pengembangan besar untuk mencapai MVP yang diuraikan di PRD 01.

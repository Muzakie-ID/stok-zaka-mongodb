# Catatan Stok Bisnis (stok-zaka)

Aplikasi pencatatan stok, penjualan, service, keuangan, dan upah karyawan untuk usaha jual-beli HP bekas. Dibangun dengan Laravel 12 + Livewire 3, sekarang berjalan di atas **MongoDB** dan siap deploy ke **Vercel**.

## Fitur

- Manajemen stok HP (IMEI, warna, minus, RAM, harga modal)
- Input stok oleh karyawan; admin melengkapi harga modal belakangan
- Penjualan multi-unit dengan split harga per unit + retur barang
- Pencarian pembeli via kode label barcode (4 digit terakhir IMEI)
- Service per unit HP
- Kas (cash flow) dengan referensi ke penjualan/input stok
- Laporan laba/rugi per periode
- Upah karyawan (saldo earn/paid) per karyawan
- Label barcode cetak A4 & printer thermal bluetooth 58mm
- Role admin/karyawan dengan pembatasan harga modal & laba

## Stack

| Komponen | Teknologi |
|---|---|
| Framework | Laravel 12 |
| Frontend | Livewire 3, Tailwind CSS 4, DaisyUI 5, Vite 7 |
| Database | MongoDB Atlas (mongodb/laravel-mongodb v5.8) |
| Session/Cache | MongoDB |
| Queue | sync (serverless) |
| Hosting | Vercel (vercel-php runtime, PHP 8.3) |
| Barcode | picqer/php-barcode-generator, @zxing/browser |

## Struktur Data

MongoDB tidak punya skema kolom tetap. Migrasi `database/migrations/0001_01_01_000000_create_mongodb_collections.php` membuat collection + index yang dibutuhkan:

`users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `hps`, `services`, `penjualans`, `detail_penjualans`, `cash_flows`, `arus_kas`, `salary_transactions`, `jobs`, `job_batches`, `failed_jobs`

ID dokumen memakai ObjectId yang dipresentasikan sebagai string di layer aplikasi (`$keyType = 'string'`).

Migrasi MySQL lama diarsipkan di `database/migrations/_mysql_backup/`.

## Persiapan Lokal (Laragon/Windows)

1. PHP 8.3+ dengan ekstensi `mongodb` (PECL `php_mongodb` 1.21+). Di Laragon: unduh DLL dari `windows.php.net/downloads/pecl/releases/mongodb/`, letakkan di folder `ext/`, lalu tambahkan `extension=mongodb` di `php.ini`.
2. Salin `.env.example` ke `.env`, isi `MONGODB_URI` (dari Atlas) dan `MONGODB_DATABASE`.
3. Instal dependensi:

```
composer install
npm install
php artisan key:generate
php artisan migrate
php artisan db:seed
npm run dev
php artisan serve
```

Akun admin pertama dibuat oleh `AdminSeeder` (email dari `ADMIN_EMAIL`, password dari `ADMIN_PASSWORD` di `.env`).

## Deploy ke Vercel

1. Buat cluster MongoDB Atlas (M0 gratis). Di **Network Access** izinkan `0.0.0.0/0` (IP serverless Vercel dinamis).
2. Jalankan migrasi dari lokal (sekali saja): `php artisan migrate`.
3. Push repo ini, lalu import di [vercel.com/new](https://vercel.com/new).
4. Set **Production Branch** ke branch `mongodb` (atau branch produksi pilihanmu).
5. Isi Environment Variables:

| Key | Contoh / Keterangan |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://<project>.vercel.app` |
| `ASSET_URL` | sama dengan `APP_URL` |
| `APP_KEY` | `php artisan key:generate --show` |
| `MONGODB_URI` | `mongodb+srv://user:pass@cluster0.xxx.mongodb.net/?retryWrites=true&w=majority` |
| `MONGODB_DATABASE` | `stok_zaka` |
| `SESSION_DRIVER` | `mongodb` |
| `SESSION_SECURE_COOKIE` | `true` |
| `CACHE_STORE` | `mongodb` |
| `QUEUE_CONNECTION` | `sync` |
| `LOG_CHANNEL` | `stderr` |
| `VIEW_COMPILED_PATH` | `/tmp/views` |
| `APP_CONFIG_CACHE` | `/tmp/config.php` |
| `APP_EVENTS_CACHE` | `/tmp/events.php` |
| `APP_PACKAGES_CACHE` | `/tmp/packages.php` |
| `APP_ROUTES_CACHE` | `/tmp/routes.php` |
| `APP_SERVICES_CACHE` | `/tmp/services.php` |

Build dijalankan lewat `vercel.json`: `npm install` + `npm run build` (asset Vite). Dependency Composer diinstall otomatis oleh runtime `vercel-php@0.7.4` (PHP 8.3, ekstensi `mongodb` tersedia). Request dinamis diarahkan ke `api/index.php`; file statis `public/build/` dilayani langsung.

## Catatan Serverless

- Filesystem ephemeral: jangan menyimpan file permanen di `storage/`.
- Queue `sync`: semua proses berjalan inline, tidak ada worker.
- Cold start function pertama sekitar 250 ms; request berikutnya cepat.

## Struktur Penting

```
app/
  Livewire/        Komponen halaman (Stok, Penjualan, Keuangan, ...)
  Models/          Model MongoDB (Hp, Penjualan, CashFlow, ...)
resources/
  views/livewire/  View blade komponen
database/
  migrations/      Migration MongoDB (collection + index)
  seeders/         AdminSeeder, DemoSeeder
public/js/         label-print.js, thermal-printer.js
api/index.php      Entrypoint serverless Vercel
vercel.json        Konfigurasi build & routing Vercel
```

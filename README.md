# HRM Indonesia

Aplikasi manajemen SDM untuk **satu perusahaan**: karyawan, absensi, cuti,
payroll, rekrutmen, dashboard, dan laporan. Backend Laravel 12 (API), frontend
React + Tailwind.

## Peran

Super Admin, Atasan, HR, Finance, Karyawan. Hak akses dijaga di server (policy);
menu di frontend hanya soal tampilan.

## Prasyarat

- PHP 8.2+ dan Composer
- MySQL
- Node.js 20+ dan npm

## Setup backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Buat database MySQL (default: `hrm`), sesuaikan `DB_*` di `.env`, lalu:

```bash
php artisan migrate
php artisan regions:import database/data/wilayah.csv
php artisan serve
```

`regions:import` memuat data provinsi sampai desa/kelurahan dari CSV. Perintah
menolak file yang terlalu kecil; opsi `--allow-partial` hanya untuk uji.

## Membuat Super Admin pertama

Tidak ada akun demo. Akun awal dibuat lewat command:

```bash
php artisan hrm:admin admin@perusahaan.co.id --name="Nama Lengkap"
```

Password sementara ditampilkan **satu kali** di terminal. Simpan, lalu ganti
setelah login pertama. Command yang sama (tanpa `--name`) menyetel ulang
password Super Admin yang terkunci.

Setelah login: buat departemen dan jabatan, tambah karyawan, lalu buat akun
untuk karyawan di menu Akun.

## Setup frontend

```bash
cd frontend
npm install
npm run dev
```

Buka **http://localhost:5173** (pakai `localhost`, jangan `127.0.0.1`, supaya
cookie sesi cocok dengan `SANCTUM_STATEFUL_DOMAINS`). Frontend memanggil API
lewat path relatif (`/api/...`); saat dev, Vite meneruskannya ke
`http://127.0.0.1:8000`, jadi jalankan backend dulu.

## Test

```bash
cd backend && php artisan test
cd frontend && npm test
```

`ProbeConcurrency` dan `ProbeWorker` adalah alat uji konkurensi untuk
development. Keduanya menolak berjalan di luar database khusus probe dan tidak
dipakai di produksi.

## Produksi

Di `.env`, ganti ke nilai produksi (contoh di bagian bawah `.env.example`):

- `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`
- `APP_URL`, `SANCTUM_STATEFUL_DOMAINS`, `CORS_ALLOWED_ORIGINS` sesuai domain
- `SESSION_SECURE_COOKIE=true` (wajib HTTPS)
- `HRM_REPORT_MAX_EXPORT_ROWS` mengatur batas baris ekspor CSV

Proxy Vite hanya ada saat dev. Di produksi, jalankan `npm run build`, sajikan
`frontend/dist` lewat web server, dan teruskan `/api` dan `/sanctum` ke Laravel
dari **domain yang sama** (reverse proxy), karena frontend memakai path relatif
dan cookie sesi. Di backend: `php artisan config:cache route:cache`. Antrean dan
cache memakai driver `database`.

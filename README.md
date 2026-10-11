# Project Management Dashboard

Aplikasi manajemen proyek dengan pembagian tugas, kolaborasi tim, kalender, dan laporan harian.

**Status:** Pengembangan lokal; demo publik belum tersedia.

## Fitur

- Proyek, anggota tim, tugas, dan riwayat status
- Komentar, lampiran, serta tautan pendukung tugas
- Kalender kegiatan, laporan harian, dan pengaturan akses

## Teknologi

Laravel 12, Vue 3, Inertia.js 2, Tailwind CSS, MySQL.

## Menjalankan secara lokal

Gunakan PHP ^8.2, Composer, MySQL, serta Node.js yang sesuai dengan versi bundler di `package.json`.

```sh
composer install
npm ci
```

Salin `.env.example` menjadi `.env`, buat database kosong, lalu isi konfigurasi database lokal. Kredensial email, OAuth, pembayaran, dan pengiriman hanya diisi di `.env`.

```sh
php artisan key:generate
php artisan migrate
php artisan storage:link
npm run build
php artisan serve
```

Buka [localhost:8000](http://localhost:8000). Gunakan database pengembangan; data pengguna, unggahan, dan kredensial akun asli tidak disertakan. Seeder tidak menyediakan password login tetap; atur akun pengembangan sendiri.

Integrasi eksternal memerlukan akun dan konfigurasi layanan masing-masing. Gunakan mode sandbox jika tersedia.

## Struktur

- `app/` — model, controller, dan logika aplikasi.
- `resources/` — tampilan dan aset frontend.
- `routes/` — rute aplikasi.
- `database/` — migration, factory, dan seeder.
- `tests/` — pengujian yang tersedia pada proyek.

## Pemeriksaan

Jalankan `php artisan test` pada lingkungan pengembangan yang telah dikonfigurasi. Keberadaan pengujian di repository tidak berarti seluruh integrasi eksternal telah diuji.

## Catatan repository

Dependensi terpasang, cache, file `.env`, database, dan unggahan pengguna tidak disertakan. Nama merek dan aset pihak ketiga tetap mengikuti hak pemiliknya. Tidak ada lisensi penggunaan ulang yang diberikan melalui repository ini.

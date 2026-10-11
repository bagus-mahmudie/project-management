# AGENTS.md

## Bahasa

- Gunakan Bahasa Indonesia saat menjelaskan pekerjaan.
- Nama variabel dan kode tetap menggunakan Bahasa Inggris.

## Arsitektur

- Proyek menggunakan Laravel 12, Inertia.js, dan Vue 3.
- Letakkan logika bisnis kompleks di app/Services.
- Gunakan Policy untuk otorisasi Project, ProjectTask, dan Event.
- Jangan mengubah file di vendor, storage, atau node_modules.

## Standar perubahan

- Pertahankan gaya kode yang sudah ada.
- Jangan menambahkan dependency tanpa persetujuan.
- Buat migration baru untuk perubahan database; jangan mengedit migration lama yang sudah digunakan.
- Jalankan pengujian yang relevan setelah perubahan.
- Jalankan npm run build setelah mengubah frontend.
- Jangan menghapus data atau file tanpa persetujuan.

## Pemeriksaan

- Backend: php artisan test
- Frontend build: npm run build

# Panduan rilis kunci rute laporan dan API (untuk Randy)

Rilis ini menambah pemeriksaan login dan izin di rute yang sebelumnya terbuka. Tidak ada migrasi dan tidak ada perubahan struktur database, jadi rilis ini aman untuk toko yang sudah berjalan di MySQL dan bisa dipasang terpisah dari Open Bill.

## Yang berubah untuk pengguna

| Rute | Sebelum | Sesudah |
| --- | --- | --- |
| `/member/{cashier,selling,product,purchasing}-report/generate` | Siapa saja yang tahu URL-nya mendapat PDF, tanpa login | Login (tanpa login diarahkan ke halaman login panel) dan izin `generate ... report`, sama seperti halaman laporannya |
| `/member/sellings/{selling}/print` | Tanpa login | Login dan izin `can print selling` |
| `POST /api/setting` | Semua user login bisa mengubah semua setting | Izin per setting (tabel di bawah) |
| `PUT /api/about` | Semua user login | Izin `update about` |
| `GET /api/transaction/dashboard/total-revenue` dan `total-gross-profit` | Semua user login | Izin `read revenue overview` |
| `GET /api/transaction/dashboard/total-sales` | Semua user login | Izin `read sales overview` |
| `POST /api/register-fcm-token` | Body apa pun diisikan ke akun, termasuk `is_owner` | Hanya `fcm_token` yang disimpan |

Tetap terbuka untuk semua user login, sengaja: `GET /api/setting/{key}` dan `GET /api/about` (kasir butuh mata uang, pajak, metode jual, nama dan alamat toko untuk struk).

Izin per setting di `POST /api/setting`: `currency` = `update currency`, `selling_method` = `set selling method`, `cash_drawer_enabled` = `enable cash drawer`, `secure_initial_price_enabled` dan `secure_initial_price_using_pin` = `enable secure initial price`, `default_tax` = `set default tax`, `minimum_stock_nofication` = `set the minimum stock notification`.

## Langkah di produksi

1. Pasang kode seperti biasa. Tidak ada `migrate`.
2. Jalankan `php artisan app:refresh-permission`. Perintah ini aman diulang: hanya menambah izin `read revenue overview` dan `read sales overview` untuk guard mobile (sanctum) dan memberikannya ke role `admin`. Tanpa langkah ini, akun admin yang bukan owner mendapat 403 di kartu dashboard aplikasi mobile. Akun owner tidak terpengaruh.
3. Role selain `admin` (misalnya kasir) tidak otomatis mendapat izin baru. Bila ada role yang memang perlu melihat dashboard di aplikasi mobile, centang `read revenue overview` / `read sales overview` di menu Role, bagian "Mobile app permissions".
4. Cek singkat dengan akun kasir: login di aplikasi mobile, buka layar penjualan, `GET /api/setting/all` dan `GET /api/about` tetap 200.

## Bila ada masalah

Kembalikan kode ke versi sebelumnya. Izin yang ditambah langkah 2 tidak perlu dihapus; mereka tidak berefek pada kode lama.

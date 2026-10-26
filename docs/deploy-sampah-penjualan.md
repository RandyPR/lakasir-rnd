# Deploy: sampah (soft delete) untuk penjualan

Fitur ini menambah tombol "Pindahkan ke sampah" pada Riwayat Penjualan, tab "Sampah" untuk melihat dan memulihkan, dan hapus massal. Penjualan di sampah tetap ada di database (nomor, kode, baris barang, tanggalnya tidak berubah) tetapi hilang dari semua daftar, laporan, dashboard, dan API. Tidak ada hapus permanen.

## Yang berubah di database

Satu migrasi, hanya menambah: `2026_10_26_000001_add_soft_deletes_to_sellings_table`.

- `sellings.deleted_at` (nullable, kosong untuk semua penjualan lama), `sellings.deleted_by`, `sellings.delete_reason`, dan indeks `sellings_deleted_at_index`.
- Tidak ada data yang diubah atau dihapus. Kode lama mengabaikan kolom baru, jadi migrasi ini aman dijalankan SEBELUM kode baru dipasang.

## Urutan deploy (penting)

Kode baru menambah `deleted_at IS NULL` pada setiap query penjualan. Kalau kodenya sudah aktif tetapi kolomnya belum ada, kasir dan semua halaman penjualan error. Jadi:

1. `php artisan pos:preflight --pre` harus `HASIL: PASS` (berkas migrasi ini sudah dikenal dan boleh Pending).
2. Bila tujuh migrasi Open Bill belum dijalankan, jalankan dulu sesuai `docs/deploy-open-bill.md`.
3. Jalankan migrasi ini satu per satu, seperti migrasi Open Bill:
   `php artisan migrate --path=database/migrations/2026_10_26_000001_add_soft_deletes_to_sellings_table.php --force`
   Pada MariaDB 10.11 ini selesai dalam hitungan detik. Aman diulang bila terputus.
4. Pasang kode baru.
5. `php artisan pos:preflight` harus `HASIL: PASS`.

Jangan menjalankan `php artisan migrate` polos di produksi (alasannya ada di `docs/deploy-open-bill.md`).

## Izin

- Memakai izin yang sudah ada: `delete selling`. Tidak ada izin baru, jadi tidak perlu `php artisan app:refresh-permission`.
- Yang boleh memindahkan ke sampah dan memulihkan: akun pemilik (`is_owner`) dan peran yang punya izin `delete selling` (menu Role, bagian Selling, centang Delete).
- Tab "Sampah" hanya terlihat oleh mereka. Peran lain tidak melihat tab itu dan tidak bisa membuka penjualan yang ada di sampah.
- Di produksi, Rizko, Rama, dan Rizal berperan `owner` tetapi `is_owner` 0. Mereka baru bisa memakai fitur ini bila peran `owner` punya izin `delete selling`. Cek di menu Role sebelum mengumumkan fitur ini.

## Perilaku yang perlu diketahui

- Stok selalu dikembalikan saat dipindahkan ke sampah, dan dikurangi lagi saat dipulihkan. Bila stok sudah tidak cukup, penjualan itu tidak dipulihkan dan alasannya muncul di notifikasi; penjualan lain dalam pilihan yang sama tetap dipulihkan.
- Stok kembali ke batch yang sedang aktif, bukan batch asal penjualan (penjualan tidak mencatat batch). Total stok benar. Fitur ini untuk transaksi uji; bila ada stok opname sesudah penjualan itu, stok akan bertambah dua kali.
- Nomor urut harian dan kode `SELLxxxx` milik penjualan di sampah tetap terpakai, jadi nomor di hari itu boleh bolong. Pemulihan tidak pernah menabrak nomor lain.
- Produk yang sudah dihapus dan produk non-stok tidak disentuh stoknya.
- Kuota voucher yang terpakai tidak dikembalikan.
- Bill (open bill) yang sudah dibayar tetap berstatus dibayar dan tidak dibuka lagi.
- Aplikasi mobile: penjualan di sampah hilang dari daftar dan dashboard, dan `GET /api/selling/{id}` memberi 404. Belum ada layar sampah di aplikasi.
- Setiap pindah ke sampah dan pemulihan juga ditulis ke `storage/logs/laravel.log` (kode penjualan, siapa, alasan).

## Mundur (rollback)

Kode lama tidak tahu soal sampah: penjualan yang sedang ada di sampah akan muncul lagi di semua laporan. Pulihkan dulu semua isi sampah (atau terima bahwa angkanya muncul lagi) sebelum memasang kode lama. Kolom baru tidak perlu dihapus.

# Panduan rilis Open Bill (untuk Randy)

Panduan ini untuk memasang fitur Open Bill (bill sementara, tiket dapur, pembayaran baru, harga per item, hari bisnis WIB) ke toko yang SUDAH berjalan di MySQL atau MariaDB dengan data asli. Produksi Randy memakai MariaDB 10.11.19 (terlihat di kepala berkas dump 2026-10-24); urutan di bawah sudah dicoba di salinan MariaDB 10.11 dan MySQL 8.0 dari dump itu. Kerjakan berurutan. Bila ragu di langkah mana pun: BERHENTI, jangan lanjut, kirim keluaran perintah terakhir kepada pengembang.

Aturan emas:

- Semua perubahan database di rilis ini hanya MENAMBAH (kolom, tabel, indeks). Tidak ada yang dihapus atau diubah.
- Migrasi dijalankan SATU PER SATU dengan `--path`. Jangan pernah menjalankan `php artisan migrate` polos.
- Sebelum migrasi ada GERBANG: `php artisan pos:preflight --pre`. Bila hasilnya FAIL, berhenti.
- Toko ditutup (`php artisan down`) selama jendela pemasangan. Kode baru dengan database lama akan menggagalkan SEMUA penjualan, termasuk aplikasi mobile.

## Dilarang di produksi

Jangan jalankan ini di server produksi, dalam keadaan apa pun:

```
php artisan migrate                (tanpa --path)
php artisan migrate:rollback
php artisan migrate:fresh
php artisan migrate:refresh
php artisan db:wipe
ALTER TABLE ... ALGORITHM=INSTANT  (ditulis tangan)
```

Alasannya: `php artisan migrate` polos menjalankan SEMUA migrasi yang berstatus Pending, dan beberapa migrasi lama menjatuhkan kolom (`2025_10_03_042817_remove_barcode_from_products_table` menyalin lalu menghapus `products.barcode`; `2024_06_04_082221_drop_expired_columns_in_products_table`; `2024_04_25_231131_create_abouts_table` punya `dropColumn`). `migrate:rollback` menjalankan `down()` yang menghapus kolom `note` dan tabel bill (data hilang). `--pretend` TIDAK membuktikan apa pun: dengan penjagaan `hasTable/hasColumn/hasIndex` ia selalu mencetak semua perintah (kasus terburuk), meski sebagian sudah ada. Status sebenarnya hanya dari `pos:preflight` dan `migrate:status`.

## Langkah 0: fakta dan cadangan (boleh kapan saja, SEBELUM menyentuh apa pun)

1. Cadangan penuh:

   ```
   mysqldump --single-transaction --routines --triggers -u USER -p NAMADB > backup-AAAA-BB-HH.sql
   ```

   (Di MariaDB `mariadb-dump` dengan opsi yang sama juga boleh.)

   Lakukan SEBELUM melihat `migrate:status`. Salin berkasnya ke tempat lain. Lalu PULIHKAN ke database kosong (scratch) di server yang zona waktunya sama dan jalankan `SELECT COUNT(*) FROM sellings;` di sana. Cadangan yang belum pernah dipulihkan bukan cadangan. (Memulihkan ke server berzona waktu lain menggeser `created_at` bertipe TIMESTAMP terhadap `date` bertipe DATETIME; latihan pemulihan di server yang zonanya sama.)

2. Empat perintah SQL fakta (baca-saja). Kirim hasilnya kepada pengembang:

   ```sql
   SELECT VERSION(), @@system_time_zone, @@global.time_zone,
          @@innodb_lock_wait_timeout, @@innodb_default_row_format, @@sql_mode;

   SHOW VARIABLES LIKE '%isolation%';

   SELECT TABLE_NAME, ENGINE, ROW_FORMAT FROM information_schema.TABLES
   WHERE TABLE_SCHEMA = DATABASE() AND (ENGINE <> 'InnoDB' OR ROW_FORMAT NOT IN ('Dynamic','Compressed'));
   -- hasil yang benar: KOSONG

   SELECT COUNT(*) FROM sellings;
   SELECT COUNT(*) FROM selling_details;
   SELECT MAX(id) FROM sellings;
   ```

   `SHOW VARIABLES LIKE '%isolation%'` dipakai karena nama variabelnya berbeda: MySQL 8 `transaction_isolation`, MariaDB 10.11 `tx_isolation`. Jangan menulis `@@transaction_isolation` di MariaDB 10.11: galat "Unknown system variable". Dari `php artisan pos:preflight` baris `@@transaction_isolation` di MariaDB 10.11 tertulis "(tidak tersedia di versi ini)"; itu normal, bukan kegagalan.

   Catat juga: isi `CACHE_DRIVER` di `.env` produksi, nama database (`DB_DATABASE`), dan commit yang sedang berjalan (`git rev-parse HEAD`).

3. Ruang disk: bandingkan ruang kosong server dengan hasil `SELECT data_length+index_length FROM information_schema.TABLES WHERE table_name='selling_details';`. MySQL 5.7 dan MariaDB membangun ulang tabel saat menambah kolom, jadi butuh ruang kira-kira sebesar tabel itu. MySQL 8 menambah kolom nullable hampir seketika.

## Langkah 1: pilih jam sepi, tutup toko

4. Pastikan tidak ada transaksi atau laporan yang sedang berjalan lama:

   ```sql
   SHOW FULL PROCESSLIST;
   SELECT * FROM information_schema.innodb_trx;
   ```

   Tidak boleh ada transaksi/laporan panjang pada `sellings`, `selling_details`, `cart_items`, `stocks`, `products`. Perubahan struktur menunggu sampai transaksi itu selesai, dan SEMUA query berikutnya antre di belakangnya (terlihat seperti aplikasi mati total).

5. `php artisan down --retry=60` (pengunjung melihat halaman 503). Tunggu 30 detik.

## Langkah 2: pasang kode (belum menjalankan migrasi)

6. `git pull` atau unggah kode, `composer install --no-dev`, lalu pasang hasil `npm run build` (`public/build`).

   Sesudah `composer install` selesai dan SEBELUM perintah artisan pertama yang kamu ketik sendiri, hapus cache bootstrap lama:

   ```
   rm -f bootstrap/cache/config.php bootstrap/cache/routes-*.php bootstrap/cache/events.php
   ```

   Alasannya: rilis penghapusan tenancy (T1) mengeluarkan `App\Providers\TenancyServiceProvider` dari `config/app.php`. Bila server ini pernah menjalankan `php artisan config:cache` atau `php artisan optimize`, cache lama masih menyebut kelas itu, dan perintah artisan gagal dengan `Class "App\Providers\TenancyServiceProvider" not found`. `composer install` yang berjalan normal (dengan script-nya) sudah menghapus `config.php`, `services.php`, dan `packages.php` sendiri lewat script `post-autoload-dump` Laravel; tetapi ia tidak menghapus cache rute dan event, dan tidak membantu bila `vendor` diunggah tanpa menjalankan composer atau composer dijalankan dengan `--no-scripts`. Perintah `rm -f` di atas aman diulang dan tidak merugikan (Laravel membuat cache itu lagi bila diminta). Langkah 11 (`optimize:clear`) tetap dijalankan.

## Langkah 3: GERBANG (wajib lulus)

7. `php artisan pos:preflight --pre`

   Perintah ini hanya membaca. Ia mencetak fakta database, memastikan ini database yang benar (tabel `users`, `products`, `stocks`, `sellings`, `selling_details`, `cart_items`, `migrations` ada), dan memastikan migrasi Pending hanyalah sebagian dari tujuh berkas rilis ini. Harus berakhir dengan `HASIL: PASS`.

   Bila FAIL (misalnya daftar migrasi lama seperti `remove_barcode...` berstatus Pending): BERHENTI. Jangan jalankan migrate. Kirim seluruh keluarannya kepada pengembang. Pasang kembali kode lama dan jalankan `php artisan up`.

8. `php artisan migrate:status` sebagai pemeriksaan kedua dengan mata: semua migrasi sebelum `2026_10_15_000001` harus Ran, kecuali `2026_10_14_000001` boleh Pending.

## Langkah 4: migrasi satu per satu

9. Jalankan berurutan, dan lewati yang sudah berstatus Ran. Berhenti pada kegagalan pertama.

   ```
   php artisan migrate --path=database/migrations/2026_10_14_000001_add_pos_performance_indexes.php --force
   php artisan migrate --path=database/migrations/2026_10_15_000001_add_note_to_cart_items_and_selling_details_tables.php --force
   php artisan migrate --path=database/migrations/2026_10_15_000002_create_open_bills_table.php --force
   php artisan migrate --path=database/migrations/2026_10_15_000003_create_open_bill_items_table.php --force
   php artisan migrate --path=database/migrations/2026_10_15_000004_create_open_bill_events_table.php --force
   php artisan migrate --path=database/migrations/2026_10_15_000005_create_numbering_locks_table.php --force
   php artisan migrate --path=database/migrations/2026_10_15_000006_add_date_index_to_sellings_table.php --force
   ```

   Setiap perubahan pada tabel yang sudah berisi data memasang batas tunggu kunci pendek (10 detik) dan mencoba ulang 3 kali. Bila gagal dengan pesan "Lock wait timeout exceeded" atau "Deadlock", cari transaksi yang menahan (langkah 4), lalu ulangi PERINTAH YANG SAMA: aman diulang. Tabel baru kecil; tiap migrasi selesai dalam hitungan detik. Berkas `000006` (indeks `sellings`) opsional: boleh dilewati, kode tetap benar.

10. `php artisan pos:preflight` (tanpa `--pre`). Semua baris harus PASS. `sellings_date_number_index` hanya WARN bila tidak ada.

## Langkah 5: hidupkan

11. `php artisan optimize:clear`, `php artisan view:clear`, restart PHP-FPM (membersihkan cache opcode dan cache status tabel per proses), lalu `php artisan up`.
12. Muat ulang keras (Ctrl+Shift+R) di SETIAP perangkat kasir.

## Langkah 6: uji asap (10 menit)

13. Periksa:
    - (a) satu penjualan tunai langsung berhasil;
    - (b) satu bill: Kirim ke dapur, tambah item, kirim lagi (hanya tambahan yang tercetak), Bill sementara, bayar;
    - (c) laporan harian cocok dengan uang yang masuk;
    - (d) stok produk yang terjual berkurang tepat satu kali;
    - (e) `SELECT COUNT(*) FROM sellings;` dan `SELECT MAX(id) FROM sellings;` naik sesuai jumlah transaksi uji dibanding angka langkah 0;
    - (f) besok pukul 00:05 WIB buat satu penjualan: nomor harian harus 1.

## Bila satu migrasi gagal di tengah jalan

DDL MySQL dan MariaDB tidak transaksional: perubahan yang sudah terjadi sebelum kegagalan tetap ada. Karena itu setiap pernyataan di migrasi ini diperiksa dulu sebelum dijalankan, sehingga mengulang migrasi yang sama memperbaiki keadaan setengah jadi.

| Migrasi | Keadaan bila gagal | Aman diulang? |
|---|---|---|
| `2026_10_14_000001` (indeks kinerja) | Sebagian dari empat indeks sudah dibuat | Ya. Tiap indeks diperiksa dengan nama yang sama. |
| `2026_10_15_000001` (kolom catatan) | Satu dari dua kolom `note` sudah ada | Ya. Tiap kolom diperiksa. |
| `000002`, `000003`, `000004`, `000005` (tabel baru) | Tabel sudah ada tetapi indeks atau foreign key belum | Ya. Pembuatan tabel dilewati, lalu tiap indeks dan foreign key diperiksa dan ditambahkan satu per satu. Error 1215 berarti tabel induk bukan InnoDB atau tipenya berbeda: kirim keluaran `SHOW CREATE TABLE nama_tabel;`, jangan memaksa. |
| `000006` (indeks `sellings`) | Indeks belum ada | Ya. Boleh juga dilewati; kode tetap benar. |

## Bill terbuka: daftar baca-saja

Kapan pun, termasuk sebelum menutup laci kas, sebelum rollback, atau saat menyelidiki:

```sql
SELECT id, business_date, number, customer_name, created_at
FROM open_bills
WHERE status = 'open'
ORDER BY business_date, number;
```

## Catatan perilaku yang perlu Anda ketahui

**Hari pertama penomoran.** Hari bisnis memakai zona `Asia/Jakarta` (WIB), diatur di `config/pos.php` (`business_timezone`); zona aplikasi (`app.timezone`) tetap UTC. Penjualan lama bernomor menurut hari UTC. Pada hari deploy, penjualan antara 00:00 dan 07:00 WIB yang tadinya bernomor menurut hari UTC lama ikut terhitung ke hari bisnis baru, dan nomor berikutnya adalah nomor tertinggi + 1 atas keduanya: terjadi lompatan nomor, bukan reset. Ini hanya berpengaruh bila toko atau sinkronisasi offline aplikasi mobile aktif pada jam itu. Mulai tengah malam berikutnya nomor di-reset tepat ke 1.

**Laci kas.** Bill masuk ke laci kas yang sedang terbuka saat DIBAYAR, atas nama kasir yang menerima pembayaran, bukan yang membuat bill. Menutup laci tidak memperingatkan adanya bill terbuka: lihat daftar bill terbuka (SQL di atas, atau tombol daftar bill di kasir) sebelum menutup laci. Bila laci belum dibuka saat membayar, kasir mendapat pesan "Cash drawer is not opened", bukan galat 500.

**Bill melintasi tengah malam.** Penjualan, stok, laci kas, dan penomoran mengikuti HARI PEMBAYARAN, dengan nomor baru. Contoh: bill dibuka 23:55 dan dibayar 00:05 mendapat nomor baru di hari berikutnya; tiket dapur membawa nomor lama sedangkan struk membawa nomor baru; laporan hari pesan tidak memuat pesanan itu. Daftar bill dan tiket cetak ulang menampilkan label tanggal (`KEMARIN` atau `d/m`) di samping nomor, dan bill ditandai "Dari kemarin" hanya bila umurnya lebih dari 6 jam (`pos.stale_after_hours`). Janji "satu nomor dari pesan sampai bayar" hanya berlaku bila dibayar di hari yang sama.

**Harga dan pajak bill.**
- Baris biasa memakai harga saat dipesan. Menambah jumlah hanya menghargai SELISIH jumlah dengan harga hari ini.
- Baris dengan satuan-harga (price unit) dihitung ulang saat bayar (batasan lama).
- Pajak dibekukan saat bill dibuat; diskon header milik bill.
- Modal bayar menampilkan petunjuk bila harga katalog sekarang berbeda dari harga bill (hanya petunjuk, tidak memblokir).
- Piutang untuk penjualan kredit dihitung dari jumlah SESUDAH diskon, dan hanya piutang baru yang terpengaruh; data lama tidak ditulis ulang.
- Rupiah tetap memakai 2 desimal seperti sekarang; tidak ada pembulatan ke rupiah penuh.

**Dasbor dan pemulihan antar-zona.** Dasbor memakai zona profil dan `created_at` untuk "hari ini" (tidak diubah di rilis ini); penomoran memakai zona bisnis. Untuk `Asia/Jakarta` keduanya sama. Bila suatu hari Anda memulihkan cadangan ke server dengan zona waktu berbeda, kolom `created_at` (TIMESTAMP) bergeser terhadap kolom `date` (DATETIME), sehingga laporan harian bisa tampak bergeser: latihan pemulihan di server yang zonanya sama.

**Produk jangan dihapus permanen saat masih ada di bill terbuka.** Penghapusan permanen (force delete) produk yang masih ada di bill terbuka menghasilkan galat foreign key mentah (1451), sama seperti produk yang masih ada di keranjang atau di penjualan hari ini. Hapus biasa (soft delete) aman: bill tetap menampilkan nama produknya.

**Penjualan yang dihapus.** Menghapus penjualan tidak memulihkan stok (perilaku lama). Bill berstatus `paid` yang penjualannya dihapus tetap read-only.

**Aplikasi mobile (API).** Perubahan bersifat aditif. Hanya pesan galat stok kurang yang lebih panjang (kunci `products.N.qty` tidak berubah), dan `date` berformat ISO dari aplikasi dinormalisasi ke UTC.

## Kill switch dan rollback

**Kill switch.** Pengaturan `open_bill_enabled` bernilai salah (`0`/`false`) HANYA menyembunyikan PEMBUATAN bill (tombol Simpan/Kirim ke dapur). Tombol daftar bill dan tombol Bayar tetap tampil selama masih ada bill terbuka, supaya bill yang sudah ada tidak tertinggal. Pengaturan disimpan di tabel `settings` (kolom `key` dan `value`) dan di-cache sampai 3 jam: setelah mengubahnya jalankan `php artisan cache:clear`.

**Rollback = pasang kode lama. Skema TIDAK di-rollback.** Kode lama mengabaikan tabel dan kolom baru. Satu pengecualian bisnis: bill terbuka tidak terlihat oleh kode lama dan omzetnya belum tercatat, jadi selesaikan semuanya dulu.

1. Tutup toko: `php artisan down`.
2. SEBELUM memasang kode lama, jalankan SQL daftar bill terbuka di atas. Selesaikan SEMUA bill terbuka: bayar (kode baru masih terpasang), atau batalkan dengan alasan, atau cetak Bill sementara dan catat manual.
3. Pasang kode lama (checkout commit sebelumnya, `composer install`, aset lama), lalu `php artisan optimize:clear`, restart PHP-FPM, `php artisan up`.
4. Jangan memakai `migrate:rollback`: `down()` menjatuhkan kolom `note` (catatan item hilang) dan tabel bill (jejak audit hilang). Tabel dan kolom tambahan yang tersisa tidak berbahaya.

## Pemeriksaan sesudah rilis (kapan saja)

`php artisan pos:preflight` bersifat baca-saja dan boleh dijalankan kapan pun untuk memastikan tabel, kolom, indeks, dan foreign key rilis ini lengkap. Perintah ini keluar dengan kode 0 (PASS) atau 1 (FAIL); WARN tidak menggagalkan.

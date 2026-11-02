# Deploy: halaman kasir lebih cepat di HP

Tidak ada migrasi dan tidak ada izin baru. Perubahan kode ikut deploy biasa, lalu ada beberapa langkah server yang tidak ada hubungannya dengan kode (bagian "Langkah server"). Kembali ke versi lama cukup dengan memasang kode lama; tidak ada yang perlu dibatalkan di database.

## Yang berubah di kode

1. **Kartu produk langsung berubah saat disentuh.** Angka di kartu (tombol Tambah, tombol + dan -) berubah saat jari menyentuh, tanpa menunggu jawaban server. Server tetap yang menentukan: bila ia menolak (stok habis) atau permintaan gagal (sinyal putus, sesi habis), kartu kembali ke isi keranjang yang sebenarnya. Dua pengecualian disengaja:
   - Tambah melebihi stok yang tampil di kartu tidak didahulukan (server menjawab "Stok habis").
   - Mengurangi di bill yang sudah dikirim ke dapur tidak didahulukan, karena bisa membuka dialog alasan batal dan jumlahnya tidak berubah.
2. **Bagian yang tidak pernah berubah tidak dikirim ulang.** Pemindai QR, dialog sukses, pratinjau cetak, dan tiga dialog tindakan (batalkan item terkirim, bill berubah di perangkat lain, bawa pulang) hanya dikirim saat halaman dibuka (halaman itu memberi tahu server lewat header `X-Lakasir-Static-Parts`). Setiap sentuhan tambah atau kurang tidak lagi mengirimnya dan browser tidak lagi membandingkannya. Jawaban "tambah item" turun dari sekitar 160 KB menjadi 115 sampai 129 KB (isinya makin besar bila keranjang berisi banyak baris).

Hasil ukur (Chromium 390 px, CPU diperlambat 4 kali sebagai HP kelas menengah, MariaDB 10.11): dari sentuhan sampai angka di kartu berubah dulu 470 sampai 710 ms, sekarang 13 sampai 38 ms. Jawaban server baru terpasang di layar setelah 350 sampai 480 ms (dulu 380 sampai 630 ms); kini itu terjadi di belakang layar.

Pengecekan di browser: `docs/pos-redesign/browser/flowG.cjs` (cara menjalankannya ada di README folder itu). Skrip itu memperlambat server 1,5 detik dan memeriksa bahwa angka sudah berubah sebelum jawaban datang, bahwa dialog yang tidak dikirim ulang masih berfungsi, dan bahwa kartu kembali benar bila permintaan gagal.

## Sesudah deploy: tab kasir yang sudah terbuka

Halaman kasir yang terbuka sejak SEBELUM deploy (HP kasir yang dibiarkan menyala semalaman) tetap berfungsi: ia tidak mengirim header `X-Lakasir-Static-Parts`, jadi server masih mengirim semua bagiannya (dialog sukses, pemindai QR, pratinjau cetak, tiga dialog tindakan tidak hilang). Hanya saja tab itu belum mendapat percepatannya. Minta kasir menarik halaman ke bawah (atau menekan F5) sekali agar mendapat versi baru. Tidak ada yang rusak bila lupa. (Diuji: halaman dimuat oleh kode lama, kode baru dipasang, lalu satu sentuhan Tambah; semua dialog masih ada.)

Kebalikannya (kode dikembalikan ke versi lama sementara tab versi baru masih terbuka) tidak dilindungi: jawaban versi lama menghapus dialog-dialog itu dari tab tersebut sampai dimuat ulang. Setelah rollback, minta semua kasir memuat ulang halaman kasir.

## Langkah server (tanpa kode)

Urutan dari yang paling berpengaruh. Server sendiri sudah cepat (50 sampai 150 ms per permintaan), jadi yang dicari adalah ukuran data yang dikirim ke HP.

### 1. Kompres JSON (gzip atau brotli)

Jawaban kasir adalah JSON berukuran 115 sampai 130 KB; dikompres hanya sekitar 15 sampai 19 KB. Di HP dengan sinyal biasa ini beda yang terasa. Cek dulu apakah sudah aktif:

1. Chrome di komputer, buka halaman kasir, tekan F12, tab Network, sentuh satu produk.
2. Klik permintaan bernama `update`, tab Headers, bagian Response Headers: cari `content-encoding`. Bila isinya `gzip` atau `br`, sudah aktif dan langkah ini selesai.
3. Bila tidak ada, aktifkan kompresi untuk `application/json` di nginx situs ini (di CloudPanel lewat pengaturan Vhost situs; baris yang diperlukan: `gzip on;` dan `gzip_types application/json text/css application/javascript;`). Muat ulang konfigurasi nginx, lalu cek lagi seperti di atas.

### 2. Cache Laravel (selisih kecil, tetapi gratis)

Setiap deploy, sesudah `php artisan optimize:clear`:

```
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan filament:optimize
```

Lalu mulai ulang PHP-FPM. Ulangi setiap deploy: cache yang lama tidak tahu kode yang baru.

**Jangan** menjalankan `php artisan optimize` dan `php artisan config:cache`. `Product.php` dan `StockService.php` membaca `env('SELLING_METHOD')` langsung (bukan dari `config/`), dan `env()` di luar `config/` mengembalikan nilai kosong bila konfigurasi di-cache. Memperbaikinya adalah tugas T7 di backlog; sebelum itu cache konfigurasi tidak aman.

Perkiraan hasil: 10 sampai 20 ms per permintaan. Kecil dibanding langkah 1, tetapi tidak ada risikonya.

### 3. Driver cache dan sesi

Di `.env` server: `CACHE_DRIVER` dan `SESSION_DRIVER` sebaiknya `file` (atau `redis` bila sudah ada). Jangan `database`: diukur per sentuhan, `file` 136 sampai 147 ms, `redis` 162 sampai 165 ms, `database` 177 sampai 205 ms. Redis tidak perlu dipasang hanya untuk kecepatan kasir. Server uji memakai `redis`; produksi belum diketahui, cek isi `.env`-nya.

### 4. OPcache

Pastikan aktif: `php -i | grep opcache.enable` harus `On` untuk PHP-FPM (CloudPanel biasanya sudah menyalakannya). Tidak perlu diubah bila sudah aktif.

## Kalau hasilnya tidak terasa

Ukur di Chrome komputer dengan HP yang sama di jaringan yang sama: F12, Network, klik `update`, tab Timing. "Waiting for server response" adalah waktu server (harus di bawah 200 ms); "Content download" adalah waktu mengirim data (di sinilah langkah 1 bekerja). Bila "Waiting" besar, kirim angkanya; masalahnya di server, bukan di browser.

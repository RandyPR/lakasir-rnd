# Deploy: kartu Omzet, HPP, dan Laba di Dasbor dan Riwayat Penjualan

Tidak ada migrasi, tidak ada izin baru, dan tidak ada langkah server. Kode ikut deploy biasa. Gaya kartu ditulis langsung di halaman (bukan di berkas CSS yang harus dibangun), jadi `npm run build` tidak perlu dijalankan. `php artisan optimize:clear` boleh, tidak wajib. Kembali ke versi lama cukup dengan memasang kode lama.

## Yang berubah

- **Dasbor dan Riwayat Penjualan** punya kartu "Hari ini" yang baru: Omzet (setelah diskon, tanpa pajak) − HPP = Laba (setelah HPP), margin, jumlah transaksi, rata-rata, dan diskon. Di layar lebar berupa satu strip tipis; di HP (lebar kartu di bawah 700 px) berupa kartu yang lega.
- **Kartu periode di Dasbor** (7 hari, bulan, tahun) ikut tampil dalam rumus yang sama dan kini punya HPP.
- **Perbandingan yang adil.** Selama hari belum selesai, "Hari ini sampai 19.42" dibanding "kemarin sampai 19.42", bukan dengan seluruh hari kemarin. Angka "turun 81%" setiap pagi hilang.
- **Peringatan modal 0.** Bila ada produk terjual tanpa harga modal pada rentang kartu, tampil satu baris peringatan (hanya untuk yang punya izin `read revenue overview`).
- **Riwayat Penjualan:** kartu mengikuti filter tanggal dan kasir di tabel (tanpa filter = hari ini) dan disembunyikan di tab Sampah.

## Satu definisi "hari ini" untuk semua kartu

Sebelumnya "Laba hari ini" memakai `created_at` dan zona waktu profil, sedangkan "Penjualan hari ini" memakai `date` dan WIB. Sekarang semuanya sama dengan kartu periode: kolom `date`, hari bisnis WIB (`config('pos.business_timezone')`), hanya `is_paid`, penjualan di sampah tidak dihitung. Angka "Laba hari ini" bisa bergeser bila ada transaksi dini hari (tengah malam WIB); jam buka toko (17 sampai 23 WIB) tidak terpengaruh.

## Filter tanggal di tabel Riwayat Penjualan

Pemilih tanggal yang diisi dari alamat halaman (muat ulang, tautan yang dibagikan) membawa jam saat halaman dibuka (misalnya `2026-11-01 16:08:00`), dan filter lama memakai jam itu sebagai batas rentang. Sekarang filter memakai hari penuh di zona waktu toko (awal hari pertama sampai akhir hari terakhir), sama dengan kartu, sehingga jumlah kartu = jumlah baris tabel. Satu tanggal saja (tanggal mulai tanpa tanggal akhir, atau sebaliknya) tidak lagi menyaring apa pun, sesuai penanda filter yang juga tidak muncul untuk kasus itu.

## Tidak ikut berubah

App Flutter: `Api/Tenants/Transaction/DashboardController` memakai definisinya sendiri (`created_at`, `SUM(total_price)` disebut "gross profit", termasuk pajak, tanpa filter `is_paid`). Angka di app mobile dan di web bisa berbeda. Hanya dicatat, tidak diubah.

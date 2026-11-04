# Deploy: kartu Omzet, HPP, dan Laba di Dasbor dan Riwayat Penjualan

Tidak ada migrasi, tidak ada izin baru, dan tidak ada langkah server. Kode ikut deploy biasa. Gaya kartu ditulis langsung di halaman (bukan di berkas CSS yang harus dibangun), jadi `npm run build` tidak perlu dijalankan. `php artisan optimize:clear` boleh, tidak wajib. Kembali ke versi lama cukup dengan memasang kode lama.

## Yang berubah

- **Dasbor dan Riwayat Penjualan** punya kartu "Hari ini" yang baru: Omzet (setelah diskon, tanpa pajak) − HPP = Laba (setelah HPP), margin, jumlah transaksi, rata-rata, dan diskon. Di layar lebar berupa satu strip tipis; di HP (lebar kartu di bawah 700 px) berupa kartu yang lega.
- **Kartu periode di Dasbor** (7 hari, bulan, tahun) ikut tampil dalam rumus yang sama dan kini punya HPP.
- **Perbandingan yang adil.** Selama hari belum selesai, "Hari ini sampai 19.42" dibanding "kemarin sampai 19.42", bukan dengan seluruh hari kemarin. Angka "turun 81%" setiap pagi hilang.
- **Peringatan modal 0.** Bila ada produk terjual tanpa harga modal pada rentang kartu, tampil satu baris peringatan (hanya untuk yang punya izin `read revenue overview`).
- **Riwayat Penjualan:** kartu mengikuti filter tanggal dan kasir di tabel (tanpa filter = hari ini) dan disembunyikan di tab Sampah.

## Follow-up: pemilih hari dan tanpa garis oranye

Kartu "Hari ini" di Dasbor punya pemilih hari (◀ ▶, tanggal, "Kembali ke hari ini"). Hari yang dipilih tidak boleh di masa depan dan kembali ke hari ini saat halaman dibuka ulang; grafik dan kartu periode tetap mengikuti toggle Periode. Garis oranye di tepi kartu dihapus. Riwayat Penjualan tidak punya pemilih (filter tanggal tabel yang mengatur kartu). Tidak ada migrasi.

## Follow-up: jam ramai, laba per produk, laba per transaksi

Tanpa migrasi, tanpa izin baru, tanpa build frontend.

- **Dasbor, "Jam ramai"** (izin `read sales overview` atau `read revenue overview`): jumlah penjualan per jam WIB pada periode yang dipilih, jam terramai dinamai di bawah judul. Jam ditampilkan di sekitar penjualan saja (minimal 8 jam).
- **Dasbor, "Produk paling menguntungkan"** (izin `read revenue overview`): 5 produk dengan laba terbesar pada periode. Laba per produk = harga baris − diskon baris − modal baris; diskon dan pajak seluruh transaksi tidak dibagi ke baris. Margin di bawah 20% bertanda kuning, nol atau minus bertanda merah.
- **Riwayat Penjualan, kolom "Laba"** (izin `read revenue overview`): omzet transaksi (setelah pajak dan diskon) − modal transaksi, bisa diurutkan dan bisa disembunyikan lewat tombol kolom.
- **Dasbor, "Penjualan per jam"** (di bawah kartu Hari ini): batang penjualan per jam untuk hari yang dipilih di pemilih hari kartu (hari ini bila belum memilih). Kartu memberi tahu grafik lewat event Livewire `day-picked`; grafik tidak ikut toggle Periode.
- **Dasbor, "Jam ramai per hari"**: heatmap hari × jam (bulan × jam untuk Tahunan) pada periode yang dipilih; sel makin gelap makin ramai, angka muncul di layar lebar, di HP hanya warna (sentuh sel untuk melihat jumlahnya).
- Belum dikerjakan: laba bersih dengan biaya tetap (butuh tabel baru).

## Satu definisi "hari ini" untuk semua kartu

Sebelumnya "Laba hari ini" memakai `created_at` dan zona waktu profil, sedangkan "Penjualan hari ini" memakai `date` dan WIB. Sekarang semuanya sama dengan kartu periode: kolom `date`, hari bisnis WIB (`config('pos.business_timezone')`), hanya `is_paid`, penjualan di sampah tidak dihitung. Angka "Laba hari ini" bisa bergeser bila ada transaksi dini hari (tengah malam WIB); jam buka toko (17 sampai 23 WIB) tidak terpengaruh.

## Filter tanggal di tabel Riwayat Penjualan

Pemilih tanggal yang diisi dari alamat halaman (muat ulang, tautan yang dibagikan) membawa jam saat halaman dibuka (misalnya `2026-11-01 16:08:00`), dan filter lama memakai jam itu sebagai batas rentang. Sekarang filter memakai hari penuh di zona waktu toko (awal hari pertama sampai akhir hari terakhir), sama dengan kartu, sehingga jumlah kartu = jumlah baris tabel. Satu tanggal saja (tanggal mulai tanpa tanggal akhir, atau sebaliknya) tidak lagi menyaring apa pun, sesuai penanda filter yang juga tidak muncul untuk kasus itu.

## Tidak ikut berubah

App Flutter: `Api/Tenants/Transaction/DashboardController` memakai definisinya sendiri (`created_at`, `SUM(total_price)` disebut "gross profit", termasuk pajak, tanpa filter `is_paid`). Angka di app mobile dan di web bisa berbeda. Hanya dicatat, tidak diubah.

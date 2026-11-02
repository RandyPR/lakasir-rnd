# Panduan rilis: overlay kosong dan sesi habis saat idle (untuk Randy)

Rilis ini memperbaiki dua gejala yang sama akarnya: layar kasir tiba-tiba tertutup overlay gelap yang kosong (harus refresh), dan kadang tiba-tiba diminta login lagi. Tidak ada migrasi dan tidak ada perubahan struktur database.

## Apa penyebabnya

Overlay itu adalah jendela error milik Livewire. Setiap request Livewire yang gagal (poll 20 detik, tombol tambah menu, dan sebagainya) membuka jendela penuh layar berisi isi jawaban server. Ada tiga hal yang membuat isi jawaban itu kosong atau tidak terbaca:

1. **Service worker PWA** (`public/serviceworker.js`) menjawab setiap request yang gagal koneksi dengan halaman kosong "408 Request timed out". HP yang baru bangun dari layar mati sering mengirim poll sebelum Wi-Fi atau data nyambung kembali, jadi poll itu "dijawab" kosong dan Livewire membuka overlay kosong.
2. **Exception handler** (`app/Exceptions/Handler.php`) mengubah semua kegagalan request ber-body JSON menjadi JSON, dan request Livewire memang ber-body JSON. Jendela error Livewire menampilkan isi jawaban sebagai halaman, jadi JSON tampil sebagai teks hitam di bingkai gelap, terlihat kosong.
3. **Sesi habis** (`SESSION_LIFETIME`, bawaan 120 menit tanpa request). Request Livewire pertama setelah sesi habis (poll daftar bill, atau ketukan kasir) gagal dengan 403, karena `authorizeUse()` dan `billUser()` menolak user kosong, dan 403 itu juga tampil kosong. Refresh lalu membawa kasir ke halaman login. Layar yang mati lebih dari dua jam, atau tab di latar belakang, cukup untuk itu.

## Yang berubah untuk pengguna

- Request Livewire yang gagal tidak lagi membuka overlay. Kasir melihat toast kecil: "Koneksi bermasalah", "Tidak diizinkan", atau "Terjadi kesalahan". Poll latar belakang yang gagal tidak menampilkan apa-apa.
- Bila sesi memang sudah habis, kasir melihat toast "Sesi berakhir" dan 1,5 detik kemudian halaman dimuat ulang ke halaman login. Setelah login, kasir kembali ke halaman yang tadi dibuka.
- Selama halaman terbuka dan terlihat, browser memanggil `GET /session-ping` setiap beberapa menit (seperempat umur sesi, antara 1 dan 10 menit). Panggilan ini memperpanjang sesi, jadi layar yang tetap menyala di meja kasir tidak habis sesinya. HP yang baru dibuka setelah tidur lama langsung mengecek sesi, tanpa menunggu ketukan pertama.
- Dengan `APP_DEBUG=true` (komputer lokal), error 500 tetap menampilkan jendela error Livewire yang asli, sekarang berisi halaman error yang terbaca.

## Langkah di produksi

1. Pasang kode seperti biasa. Tidak ada `migrate` dan tidak ada `filament:assets` (script-nya ada di Blade, bukan file aset).
2. Jalankan `php artisan optimize:clear`, supaya view dan daftar rute baru (`/session-ping`) terbaca.
3. Atur umur sesi di `.env` produksi, lalu `php artisan config:clear`:

   ```
   SESSION_LIFETIME=1440
   ```

   1440 menit = 24 jam tanpa aktivitas. Angka yang lebih besar boleh (10080 = 7 hari). Kode ini tidak mengubah nilai bawaannya, jadi bila `.env` tidak punya baris ini, sesi tetap 120 menit. Centang juga "Remember me" di halaman login pada HP kasir.
4. Service worker baru dimuat browser sendiri saat halaman dibuka (file-nya berubah, `skipWaiting` sudah ada). Bila overlay kosong masih muncul di satu HP, tutup aplikasi/tab sepenuhnya, buka lagi, lalu muat ulang sekali.
5. Cek singkat: buka halaman kasir di HP, matikan data/Wi-Fi 30 detik, nyalakan lagi. Tidak boleh ada overlay gelap; paling banyak satu toast "Koneksi bermasalah".

## Bila overlay masih muncul

Lihat `storage/logs/laravel.log` pada jam kejadian. Error server yang sebenarnya (misalnya Redis atau database terputus sesaat) tercatat di sana, dan sekarang tampil sebagai toast "Terjadi kesalahan" alih-alih overlay kosong. Kirim barisnya ke thread ini.

## Bila ada masalah

Kembalikan kode ke versi sebelumnya dan jalankan `php artisan optimize:clear`. Tidak ada data yang berubah.

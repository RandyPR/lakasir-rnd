# Cek browser kasir (Playwright)

Skrip yang dipakai untuk cek browser redesign kasir (2026-10-20). Bukan bagian dari Pest/CI; dijalankan manual
terhadap database demo terpisah. **Jangan pernah** pakai `database/database.sqlite`.

## Siapkan

```bash
touch database/demo.sqlite
DB_CONNECTION=sqlite DB_DATABASE=database/demo.sqlite php artisan migrate:fresh --force
DB_CONNECTION=sqlite DB_DATABASE=database/demo.sqlite php docs/pos-redesign/browser/demo-seed.php fnb   # atau: retail
npm run build
DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/demo.sqlite APP_URL=http://127.0.0.1:8000 DARK_MODE=true \
  DEBUGBAR_ENABLED=false PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8000
```

`demo-seed.php` menolak database selain `demo.sqlite` (ia membuat akun pemilik dengan kata sandi `password`).
`DEBUGBAR_ENABLED=false` wajib: di layar HP debugbar menutupi dock keranjang. `DARK_MODE=true` agar mode gelap bisa
dicek (panel mengikuti `prefers-color-scheme`). Akun demo: `owner@demo.test` / `password`.

Playwright: `npm i -D playwright && npx playwright install chromium`, atau arahkan `PLAYWRIGHT_MODULE` ke instalasi yang
ada. Tangkapan layar dan sesi login disimpan di `$TMPDIR/lakasir-browser` (ubah dengan `OUT`). Login dipakai ulang
antar-skrip karena Filament membatasi 5 login per menit.

## Skrip

| Skrip | Isi |
|---|---|
| `flowA.cjs` | fnb desktop: tambah item, catatan, Kirim ke dapur (prompt bawa pulang), buka bill, tambah, kirim selisih, batalkan item terkirim, bayar tunai +20.000 +50.000 |
| `flowB.cjs` | prompt bawa pulang "Isi meja", gagal cetak tiket dapur lalu Coba lagi, Bill sementara + Pratinjau, cari/filter bill, void, dua tab (konflik saat bayar, lalu QRIS; tab lain menutup dialog bayar bill yang sudah dibayar), kredit dengan uang muka |
| `flowC.cjs` | ketik jumlah 12,5 di stepper, Urungkan setelah hapus baris, "Kirim ke dapur" di toast bill tersimpan |
| `flowD.cjs` | retail di HP 375: Simpan bill, toast tanpa kata dapur, Bayar bill, uang pas |
| `flowE.cjs` | ubin Catatan pesanan, diskon baris lewat "lainnya" (fitur Discount harus aktif untuk diskon) |
| `flowF.cjs` | HP 360: toast "Item dihapus / Urungkan" ada di bawah, di atas dock (tidak menutupi kotak cari dan chip kategori); tetap di atas saat sheet pesanan terbuka |
| `widths.cjs` | 320/375/414/768/1024/1280, terang dan gelap: halaman, keranjang, dialog bayar, daftar bill tanpa overflow horizontal dan tanpa error konsol |

`printer-stub.js` dipasang sebelum skrip halaman: printer palsu yang hanya mencatat perintah (`window.__prints`),
`window.__printFail = true` membuat cetak gagal.

Setel ulang data demo di antara skrip bila perlu (salin cadangan `demo.sqlite` yang masih bersih). Hapus
`database/demo.sqlite` setelah selesai.

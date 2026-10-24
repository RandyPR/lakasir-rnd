# Status: redesign POS / kasir (2026-10-23)

## Posisi
- Cabang `claude/project-thread-h561g4`, PR #3 ke `1.x`. Cabang ini juga memuat commit T1 (single tenant, PR #2):
  merge PR #2 dulu, lalu PR #3. Jangan push ke 1.x.
- PHP 8.4 (Herd: `$env:Path="$env:USERPROFILE\.config\herd\bin;$env:Path"`). Test: `php vendor/bin/pest`
  (SQLite `database/testing.sqlite`, jangan jalankan dua Pest bersamaan pada berkas yang sama; untuk paralel pakai DB
  privat: `New-Item database\testing_x.sqlite; $env:DB_DATABASE="database/testing_x.sqlite"`). Benchmark:
  `PERF=1 php vendor/bin/pest tests/Feature/CashierPerfTest.php`. Harness Node (`tests/Support/*.mjs`) dijalankan oleh
  `PaymentFlowTest` dan `ReceiptPartialTest`; tanpa `node` keduanya dilewati.
- Test di MySQL, seperti CI (mysql:5.7, koneksi `testing` yang ketat): `DB_CONNECTION=testing
  DB_DATABASE_TESTING=<db> DB_HOST=... DB_PORT=... DB_USERNAME=... DB_PASSWORD=... php vendor/bin/pest`.
- Produksi = MySQL dengan data nyata. Deploy hanya lewat `docs/deploy-open-bill.md` (`pos:preflight --pre`, migrasi
  satu per satu dengan `--path`, `npm run build`). Jangan jalankan `php artisan migrate` biasa di produksi.

## Selesai
1. Performa: indeks stocks/cart_items, daftar produk jadi komponen sendiri (`Cashier\ProductBrowser`), aksi keranjang
   tidak merender daftar produk. 5000 produk (SQLite, waktu server per permintaan): cari 20-35 ms, tambah ke
   keranjang 75-85 ms, sebelumnya 5-8 dtk per aksi. Satuan harga dimuat sekali (bukan satu query per baris),
   skrip dialog dimuat sekali per halaman (bukan dikirim ulang tiap ketukan).
2. Open Bill: `open_bills`/`open_bill_items`/`open_bill_events`, `OpenBillService`, daftar bill (cari, filter,
   void), kirim ke dapur per batch dengan ack setelah benar-benar tercetak, batal item terkirim dengan alasan, konflik
   versi (dua perangkat), bayar bill lewat `SellingService::create` (laporan dan stok lama tidak berubah).
3. Pembayaran baru: Tunai (Uang pas, +20.000/+50.000/+100.000 menambah, saran pintar, papan angka), non-tunai dengan
   konfirmasi, Kredit (member, jatuh tempo, uang muka). Struk, Bill sementara, tiket dapur dari satu kode cetak
   (`window.LakasirReceipt`) + Pratinjau. Nomor harian dan nama "(2)" direset tengah malam WIB.
4. Test: 744 test Pest, lulus di SQLite, MySQL 8.0 dan MySQL 5.7 (1 benchmark dilewati tanpa `PERF=1`; di MySQL
   satu test lagi dilewati karena hanya untuk SQLite).
5. Audit MySQL 8.0.46 dan 5.7.44 (Docker): ke-7 migrasi lulus, idempoten, kondisi setengah jalan diperbaiki dengan
   menjalankan ulang, preflight menolak migrasi lama yang destruktif, `down()` aman (error MySQL 1553 ditangani),
   balapan dua proses: tidak ada nomor/nama ganda, unit terakhir hanya terjual sekali, bill yang sama dibayar dua kali
   = satu penjualan.
6. Cek browser (skrip di `docs/pos-redesign/browser/`): semua alur fnb dan retail, 320 sampai 1280 px, terang dan
   gelap, tanpa overflow horizontal dan tanpa error konsol. Temuan yang sudah diperbaiki: skrip bersama tidak menemukan
   komponen halaman (ketik jumlah, Urungkan, "Kirim ke dapur" di toast tidak jalan); dialog konflik ganda saat bayar;
   dialog bayar tetap terbuka (dan beralih ke order baru) saat bill sudah dibayar di tab lain; toast retail menyebut
   dapur; tombol "lainnya" kosong; label "Uang pas" terpotong di 320 px; chip "Melanjutkan Bill" memotong nomornya;
   setengah unit (0,5) ditolak saat bayar langsung tapi diterima lewat bill; toast "Bill tersimpan" menutupi dialog
   bayar; daftar bill kosong menampilkan ringkasan "0"; SQLite lokal "database is locked" saat ack dapur.
7. Test di MySQL 8.0 dan 5.7 (2026-10-23): satu bug nyata, pencarian kasir dengan kata yang diawali "!" tidak
   menemukan apa pun di MySQL 5.7 (bug `LIKE ... ESCAPE` MySQL 5.7 pada utf8mb4_unicode_ci); kata seperti itu
   sekarang memakai "~" sebagai karakter escape. Sisanya masalah persiapan test, bukan kode kasir.
8. CI (`.github/workflows/ci.yaml`) menjalankan semua test di MySQL 5.7 dengan database sementara milik job, tanpa
   secret repo. Sejak Mei 2026 (upstream) langkah test CI berhenti sebelum test pertama: `test.sh` memberi `-vvv` ke
   Pest (`Unknown option "-v"`) lalu menganggap kode keluar 2 sebagai lulus. Sekarang kode keluar Pest dipakai apa
   adanya.

## Belum / perlu keputusan Randy
- Pertanyaan lama yang belum dijawab: (a) topologi printer, (b) satu akun dipakai banyak perangkat,
  (c) output `php artisan migrate:status` di produksi sebelum deploy.
- Bug lama di luar redesign: `TenantLogin::authenticate` error 500 bila login dibatasi (lebih dari 5 percobaan per
  menit), karena `parent::authenticate()` mengembalikan null. Belum diubah.
- Belum diukur dengan data produksi asli di MySQL (hanya data uji 5000 produk di SQLite dan 300 produk di MySQL).

## Keputusan (jawaban Randy / default yang diterima)
- Bill = tabel baru `open_bills`+`open_bill_items`, dikonversi ke Selling lewat `SellingService::create` saat bayar.
  Stok dicek/dikurangi saat bayar saja.
- Bill dibuat 23:55 dibayar 00:05 -> hari pembayaran, nomor baru. Zona waktu app TETAP UTC;
  `config('pos.business_timezone')` default Asia/Jakarta untuk nomor harian, nomor bill, dan penomoran nama "(2)".
- Batal item yang sudah dikirim ke dapur: pemilik / izin update-delete selling / pembuat bill <=10 menit, alasan wajib
  dan dicatat. Override stok untuk item yang sudah disajikan: pemilik/update selling.
- Harga item bill tetap harga saat dipesan, pajak dikunci saat bill dibuat. Setelah Simpan/Kirim kembali ke order
  baru kosong. Istilah "Bill"/"Bill sementara" (bukan "Bon"). Tanpa meja+nama = BAWA PULANG dengan konfirmasi.
  Pindah meja tercetak "PINDAH meja a -> b".
- Tiket dapur tanpa harga (toggle di Printer); struk dan bill sementara memakai harga satuan x qty = total baris.
  Kategori urut A-Z natural, tanpa kategori terakhir. Catatan per item (cart_items.note, selling_details.note).
- Jumlah pecahan diterima di keranjang, bill, dan bayar langsung (> 0). API (`TransactionSellingStoreRequest`) tetap
  `min:1`, tidak diubah.
- Scanner barcode fisik belum dipakai; detektor global tetap ada.

## Gotcha
- Jangan ubah `app.timezone` (menggeser semua tanggal yang tersimpan).
- Komponen Livewire halaman kasir ada di pembungkus `cashier-root`, bukan di `cashier-root` sendiri: skrip bersama
  mencarinya dengan `closest('[wire\\:id]')` (dijaga oleh test di `CashierProductBrowserTest`).
- SQLite lokal: `busy_timeout` 5000 ms di `config/database.php` (tanpa itu permintaan yang berdekatan bisa gagal
  "database is locked").
- `Setting::get` memakai cache; test yang memakai tanggal harus membekukan waktu (`Carbon::setTestNow`, 16:59:59Z /
  17:00:00Z = tengah malam WIB).
- Pest tidak mendaftarkan TestCase untuk direktori selain `Feature`/`Unit`.
- `OpenBill::$table` adalah nama tabel; relasi meja lewat `getRelationValue('table')`.
- File sementara (`database/testing_*.sqlite`, `demo.sqlite`) harus dihapus; `*.sqlite` di-gitignore.
- Test yang juga harus lulus di MySQL: tanpa TRUNCATE atau DDL di seed per test (itu meng-commit transaksi test),
  tanpa id tetap (MySQL tidak mengembalikan AUTO_INCREMENT saat rollback), SQL dibandingkan lewat `normalizeSql()`.
  MySQL menolak menghapus indeks yang dipakai foreign key (error 1553) dan tabel yang dirujuk tabel lain.
- Rujukan: `plan-v3.md` (bagian 13-19 mengikat), `final_contract_v3.txt`, `final_packages_v3.json`,
  `design-notes-v3.md`, `kasir-mock-v3.html`, `openbill-research.md`. Artifact mock (privat):
  https://claude.ai/artifact/XdBdV32uBHqtAyNpEQavrw

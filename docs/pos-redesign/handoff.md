# Handoff: redesign POS / kasir (ditahan oleh Randy, 2026-10-17)

## Posisi
- Repo `C:\webapp\lakasir-rnd`, cabang `feat/pos-redesign-perf` (dari `feat/t1-single-tenant`, PR #2 belum merge). Target PR: `1.x`. Jangan push ke 1.x. Belum ada PR untuk cabang ini.
- Commit: `bd93625` slice performa (selesai, teruji, 87 test lulus); `aeb3a15` WIP besar (116 file) yang BELUM lengkap; commit berikutnya = commit handoff ini.
- PHP 8.4 Herd: `$env:Path="$env:USERPROFILE\.config\herd\bin;$env:Path"`. Test: `php vendor/bin/pest` (SQLite `database/testing.sqlite`). Untuk menjalankan test paralel pakai DB privat: `New-Item database\testing_x.sqlite; $env:DB_DATABASE="database/testing_x.sqlite"`. Benchmark: `PERF=1 php vendor/bin/pest tests/Feature/CashierPerfTest.php`.
- Produksi = MySQL, sudah ter-deploy dengan data nyata. Semua migrasi harus aditif, idempoten (SafeMigration), jangan jalankan `migrate` biasa di produksi (3 migrasi lama menghapus kolom; lihat `docs/deploy-open-bill.md`, `php artisan pos:preflight`).

## Sudah selesai
1. Perf (bd93625): indeks stocks/cart_items, `Product::scopeSellableInPos/withStockTotal`, `cartQtyFor`, `refreshCart`. 5-8 dtk -> ~0,2 dtk per aksi (5000 produk, SQLite).
2. Gelombang 1 (data-layer, domain-support, numbering, receipt-print): 7 migrasi, SafeMigration, PosPreflight, model OpenBill/OpenBillItem/OpenBillEvent, Money, OrderTotals, PaymentPlanner, StockGuard, OpenBillGate, BusinessDay, DailyNumber, NumberingLock, partial `receipt-print` (window.LakasirReceipt mode receipt|bill|kitchen + preview).
3. Gelombang 2 (open-bill-backend, open-bills-component, cashier-livewire, tests-core): OpenBillService/Context/Presenter, Livewire `Cashier/{ProductBrowser,CartItemNote,OpenBills}`, Cashier.php/CartInteraction diperluas, banyak test (OpenBill*, DailyNumbering, StockGuard, dst.).
4. Gelombang 3 SEBAGIAN: ada view di `resources/views/filament/tenant/pages/cashier/{bills,payment,...}` dan `resources/views/livewire/cashier/*`, tapi belum diverifikasi, belum tentu lengkap/terintegrasi. Cek dengan `docs/pos-redesign/salvage.json` (status gelombang lama) dan baca kodenya, jangan percaya.

## Sisa (urut)
1. Audit file view gelombang 3 vs kontrak (plan-v3 sec. 14, 16): product-view, cashier-shell (hapus JS cetak inline lama + listener scanner `keypress` yang rusak, wiring LakasirReceipt, `lang/id.json`+`en.json`, tailwind content globs, build aset bila `public/build` tracked), open-bill-view, payment-view.
2. Tambahan Randy: tombol pecahan 20.000 / 50.000 / 100.000 selalu tampil di layar bayar + "Uang pas" + 1-2 saran pintar; tombol MENAMBAH per ketukan (bukan mengganti), ada total diterima + tombol Hapus.
3. tests-bills, tests-cashier (+ integrasi: `route:list`, semua view yang direferensikan ada), jalankan seluruh suite.
4. Review: code-reviewer, mysql-migration-auditor (MySQL belum pernah dijalankan; hanya SQLite), uiux di browser pada 320/375/414/768/1024/1280 (DB demo terpisah, JANGAN pakai `database/database.sqlite`), debugger.
5. Dokumen deploy (`docs/deploy-open-bill.md` sudah ada dari gelombang 1; cek akurasi), commit, buka PR ke 1.x (blok atribusi PR wajib: lihat prompt proyek), assign + review ke RandyPR.

## Keputusan (jawaban Randy / default yang diterima)
- Bill = tabel baru `open_bills`+`open_bill_items`, dikonversi ke Selling lewat `SellingService::create` saat bayar (laporan/stok tidak tersentuh). Stok dicek/dikurangi saat bayar saja.
- Bill dibuat 23:55 dibayar 00:05 -> hari pembayaran, nomor baru. Zona waktu app TETAP UTC; `config('pos.business_timezone')` default Asia/Jakarta untuk nomor harian, nomor bill, dan penomoran nama "(2)".
- Batal item yang sudah dikirim ke dapur: pemilik / izin update-delete selling / pembuat bill <=10 menit, alasan wajib dan dicatat. Override stok untuk item yang sudah disajikan: pemilik/update selling.
- Harga item bill tetap harga saat dipesan, pajak dikunci saat bill dibuat. Setelah Simpan/Kirim kembali ke order baru kosong. Istilah "Bill"/"Bill sementara" (bukan "Bon"). Tanpa meja+nama = BAWA PULANG dengan konfirmasi. Pindah meja tercetak "PINDAH meja a -> b".
- Tiket dapur tanpa harga (toggle di Printer); struk dan bill sementara memakai harga satuan x qty = total baris. Kategori urut A-Z natural, tanpa kategori terakhir, judul per kategori di keranjang dan cetak. Catatan per item (cart_items.note, selling_details.note).
- Scanner barcode fisik belum dipakai; detektor global tetap dibangun (listener lama kemungkinan ReferenceError).
- Belum dijawab Randy: topologi printer (a), satu akun dipakai banyak perangkat (b), commit/status migrasi di produksi (c, minta output `php artisan migrate:status`).

## Gotcha
- Jangan ubah `app.timezone` (menggeser semua tanggal yang tersimpan).
- Pest jalan di SQLite; `Setting::get` memakai cache file (falsy value sekarang di-cache via `Cache::has`).
- Beberapa test lama memakai `now()->toDateString()`: bekerja tergantung jam; bekukan dengan `Carbon::setTestNow` (16:59:59Z / 17:00:00Z = tengah malam WIB).
- Pest tidak mendaftarkan TestCase untuk direktori selain `Feature`/`Unit`.
- `OpenBill::$table` adalah nama tabel; relasi lewat `getRelationValue('table')`.
- File sementara (`database/testing_*.sqlite`, `demo.sqlite`) harus dihapus; `*.sqlite` di-gitignore.
- Rujukan lengkap di folder ini: `plan-v3.md` (sec. 13-19 mengikat), `final_contract_v3.txt`, `final_packages_v3.json` (kepemilikan file), `design-notes-v3.md`, `kasir-mock-v3.html` (mock interaktif), `openbill-research.md`.
- Artifact mock (privat): https://claude.ai/artifact/XdBdV32uBHqtAyNpEQavrw

## Saran model/effort untuk melanjutkan
- Gelombang tampilan + integrasi (banyak file Blade/Alpine, rawan overflow): model besar (Opus) effort medium-tinggi untuk satu sesi utama; subagent implementasi per paket di effort medium.
- Test, review kode, audit migrasi: Sonnet effort medium cukup untuk test-runner; code-reviewer dan audit MySQL sebaiknya effort tinggi.
- Hindari menjalankan 8+ agent paralel sekaligus; batas penggunaan sudah terlewati 3 kali.

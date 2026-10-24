# Rencana V3 (ADDENDUM untuk plan.md): Open Bill, perombakan pembayaran, harga per item, mode cetak, hari bisnis, migrasi MySQL

Branch `feat/pos-redesign-perf`, repo `C:\webapp\lakasir-rnd` (Laravel 11.51, Filament 3.3.50, Livewire 3.8.0). Produksi: MySQL, sudah berjalan dengan data nyata.
Author: tech-analyst. Fase ini hanya membaca repo; tidak ada berkas repo yang diubah. Tanggal: 2026-10-15.

> **Aturan baca:** plan.md bagian 0-2 + 11 tetap berlaku untuk hal yang TIDAK disebut di sini (catatan per item, urut kategori, ProductBrowser, CartItemNote, bootstrap/LakasirPending, scanner global, partial cetak bersama, API aditif). Bila plan.md dan dokumen ini bertentangan, **dokumen ini menang**. Bagian 7 di sini menggantikan kontrak plan.md 11.3 untuk semua yang disebut; bagian 9 menggantikan paket kerja plan.md 11.5.
> Perubahan terhadap v2 yang paling penting: (1) `Cache::lock` pada penomoran diganti kunci baris database; (2) migrasi `2026_10_15_000002` (indeks products) DIBATALKAN; (3) `CashierDraftService` hanya untuk pesanan baru yang belum disimpan; (4) tujuh keputusan baru A-F di bawah.

> **STATUS: v3.1 (sudah ditantang oleh tiga devils-advocate). Bagian 13-19 di akhir dokumen adalah rencana FINAL dan MENANG atas bagian 0-12 bila bertentangan.** Kontrak mesin: bagian 14 (= `final_contract_v3.txt`), paket: bagian 16 (= `final_packages_v3.json`). Bagian yang bertanda [DIGANTIKAN/DIREVISI v3.1] hanya dipertahankan sebagai riwayat alasan. Kata "bon sementara" di bagian 0-12 dibaca "Bill sementara"; "BELUM LUNAS" dibaca "BELUM DIBAYAR".

---

## 0. Ringkasan untuk Randy (Indonesia)

### 0.1 Yang akan Anda dapat

1. **Open Bill ("Pesan Dulu, Bayar Belakangan").** Di halaman kasir ada tombol **Simpan bill** dan (untuk usaha F&B) **Kirim ke dapur**. Pesanan yang disimpan menjadi *bill terbuka* dengan nomor harian, nama pelanggan, meja, catatan, dan item. Bill bisa dibuka lagi kapan saja, ditambah item, dikirim ke dapur lagi (dapur hanya menerima item BARU atau yang berubah), dicetak sebagai **bon sementara**, dibayar, atau dibatalkan. Ada daftar **Bill terbuka** (semua kasir melihat daftar yang sama: meja, nama, total, usia bill, siapa yang membuat, sudah/belum dikirim ke dapur).
2. **Pembayaran dirombak.** Modal bayar baru: total tagihan besar, rincian pesanan (harga satuan x jumlah = total per baris), pilihan metode berupa kotak besar (Tunai, QRIS, Debit, Kredit, dompet digital, sesuai pengaturan metode pembayaran Anda), tunai punya tombol Uang pas + saran pecahan + papan angka + kembalian langsung, non-tunai cukup konfirmasi, kredit meminta member + jatuh tempo (+ uang muka bila ada), pesan kesalahan jelas, tombol bayar tidak bisa terkirim dua kali.
3. **Harga per item selalu tampil**: di keranjang, di modal bayar, di daftar bill, di pratinjau cetak, dan di cetakan (struk, bon sementara). Bentuknya `2 x 25.000   50.000`. Tiket dapur secara bawaan **tanpa harga** (dapur tidak perlu harga; bisa dinyalakan di Pengaturan Printer).
4. **Tiga jenis cetakan** dari satu kode cetak: **Struk** (setelah bayar), **Bon sementara** (sebelum bayar, tertulis BELUM LUNAS), **Tiket dapur** (dikelompokkan per kategori, jumlah besar, catatan item, hanya yang baru; item yang dibatalkan/diubah ditandai). Ada tombol **Pratinjau cetak** yang menampilkan persis teks yang akan dicetak (58 mm dan 80 mm).
5. **Nomor harian reset ke 1 setelah lewat tengah malam WIB.** Berlaku untuk nomor order harian dan penomoran nama kembar ("Budi", "Budi (2)"). Satu nomor dipakai dari tiket dapur sampai struk (nomor bill = nomor order bila dibayar di hari yang sama). Pengaturan zona waktu aplikasi (UTC) TIDAK diubah supaya data lama aman; "hari bisnis" dihitung terpisah dengan zona `Asia/Jakarta` (bisa diubah lewat `POS_BUSINESS_TIMEZONE` untuk WITA/WIT).
6. Yang sudah disepakati sebelumnya tetap: tombol +/- dan gambar tidak lagi overflow, tampilan kotak dan daftar ringkas, chip kategori + cari cepat, nama pelanggan tersimpan dengan benar, catatan per item, keranjang dan cetakan diurutkan per kategori, pembaca barcode fisik (dibangun, belum Anda pakai), kecepatan tetap terjaga.

### 0.2 Cara kerja sehari-hari (alur singkat)

- **Bayar langsung (seperti sekarang):** isi keranjang, Bayar, selesai.
- **Meja/pesan dulu:** isi keranjang, pilih meja atau nama, **Kirim ke dapur** (bill dibuat, tiket dapur tercetak). Pelanggan tambah pesanan: buka bill dari daftar, tambah item, **Kirim ke dapur** lagi (hanya item tambahan tercetak). Pelanggan minta tagihan: **Bon sementara**. Pelanggan bayar: **Bayar** (dari daftar bill atau dari keranjang), struk tercetak.
- **Pesanan baru yang belum disimpan** tetap tersimpan sementara per akun kasir (nama, meja, catatan, item) walau halaman di-reload. **Begitu disimpan sebagai bill, semuanya menjadi milik bill** dan bisa dibuka dari perangkat/akun kasir mana pun (ini perubahan mekanisme yang Anda sebut di poin 4).
- **Bill belum dibayar tidak masuk laporan dan tidak mengurangi stok.** Penjualan, stok, piutang, laci kas, dan laporan baru tercatat pada saat bill DIBAYAR (lewat jalur penjualan yang sama dengan sekarang, jadi aturan stok/piutang/laci kas tidak berubah). Konsekuensi: stok dicek ulang saat bayar; jika stok sudah habis karena terjual lewat jalur lain, pembayaran ditolak dengan pesan jelas dan Anda mengubah jumlahnya.
- **Bill dari kemarin** tetap terbuka tetapi diberi label "Dari kemarin" supaya tidak terlupa. Bila dibayar setelah lewat tengah malam, penjualannya tercatat di hari pembayaran dan mendapat nomor order hari itu.
- **Batalkan bill:** bill yang belum pernah dikirim ke dapur bisa dibatalkan oleh pembuatnya; bill yang sudah dikirim ke dapur hanya oleh pemilik/manager (izin `delete selling`), dengan alasan, dan dapur diberi tahu lewat tiket BATAL. Bill batal tetap tersimpan sebagai jejak audit (tidak dihapus).
- **Dua perangkat membuka bill yang sama:** setiap perubahan mengunci bill sesaat; bila perangkat lain mengubah bill, tombol Kirim/Bayar/Batalkan menolak dengan pesan "Bill diubah di perangkat lain" dan menawarkan **Muat ulang** atau **Simpan sebagai bill baru**. Tidak ada data yang diam-diam tertimpa.

### 0.3 Keamanan migrasi MySQL (inti dari pesan "hati-hati")

> **[DIGANTIKAN/DIREVISI v3.1]** Sekarang TUJUH berkas (tambah `open_bill_events` dan `numbering_locks` berkunci tunggal); migrasi dijalankan satu per satu dengan `--path` dan gerbang `php artisan pos:preflight --pre`; `php artisan down` wajib; lihat 13 dan 18.

- Semua perubahan skema bersifat **aditif**: tabel baru (`open_bills`, `open_bill_items`, `numbering_locks`), dua kolom `note` (nullable), satu indeks di `sellings`. **Tidak ada** DROP, RENAME, ubah tipe kolom, `->after()`, atau penulisan ulang data di dalam migrasi. Tabel `sellings`, `selling_details`, `stocks`, `products`, `cart_items` yang sudah berisi data nyata hampir tidak disentuh (hanya `ADD COLUMN note` nullable dan satu indeks), jadi kode lama tetap bisa jalan di skema baru.
- Kode baru butuh skema baru, jadi urutannya: **backup, migrate, baru pasang kode** (bagian 6.4).
- Migrasi perf yang sudah ter-commit (`2026_10_14_000001`) ternyata **tidak aman diulang** (tanpa pengecekan) dan `down()`-nya berpotensi **gagal di MySQL** (error 1553). Akan diperbaiki (aman bila sudah pernah dijalankan maupun belum).
- Yang perlu Anda lakukan: ikuti daftar di bagian 6.5 (backup `mysqldump`, 3 perintah cek, `php artisan migrate:status`, `php artisan migrate --pretend`, jam sepi, reload semua perangkat kasir setelah deploy). **Jangan menjalankan `migrate:rollback` di produksi.**

### 0.4 Default yang saya pilih (bisa diubah, tidak memblokir)

Tiket dapur tanpa harga; cetak tiket dapur lewat printer di perangkat yang menekan tombol; semua kasir (izin `create selling`) boleh membuka/mengubah/membayar bill apa pun; meja atau nama tidak wajib saat menyimpan bill (tanpa keduanya, bill diberi label "Bill #012"); bill lama tidak dibatalkan otomatis; pembayaran terpisah (sebagian tunai sebagian QRIS) TIDAK dibuat (kredit dengan uang muka tersedia untuk "bayar sebagian"); tidak ada stok yang "dipesan" oleh bill terbuka (stok dicek saat bayar).

### 0.5 Pertanyaan yang tidak bisa saya tebak (jawaban boleh belakangan; ada default)

> **[DIGANTIKAN/DIREVISI v3.1]** Daftar pertanyaan FINAL ada di bagian 19 (fakta produksi F1-F7 dan keputusan B1-B12).

1. Fakta produksi yang tidak bisa saya baca dari repo: versi MySQL/MariaDB, engine tabel, `CACHE_DRIVER` produksi, dan apakah migrasi `2026_10_14_000001` (indeks performa) sudah pernah dijalankan (`php artisan migrate:status`). Perintahnya ada di bagian 6.5; hasilnya menentukan apakah migrasi perf cukup diperbaiki di tempat atau perlu migrasi pengaman terpisah.
2. Apakah dapur punya printer sendiri di perangkat lain? Default: tiket dapur dicetak di printer perangkat yang menekan tombol (kasir menyerahkan tiket). Antrean cetak ke printer dapur otomatis adalah pekerjaan lanjutan.
3. Harga di tiket dapur: default tidak tampil. Beri tahu bila Anda ingin default menyala.

---

## 1. Temuan baru dari kode (fakta, bukan tebakan)

### 1.1 Alur penjualan dan efek sampingnya (file)

| Hal | Fakta |
|---|---|
| Pembuatan penjualan | Satu pintu: `SellingService::create(array $data)` (`app/Services/Tenants/SellingService.php`): `DB::beginTransaction` -> `Selling::create($data)` -> `SellingCreated::dispatch` -> `RecalculateEvent::dispatch` -> commit. Dipakai Livewire (`Cashier::proceedThePayment`) dan API (`TransactionSellingStoreRequest::store`). |
| Listener `SellingCreated` | `AssignProduct` (membuat `SellingDetail`; mengurangi stok per produk bukan-`is_non_stock` lewat `StockService::reduceStock` yang memecah lapisan FIFO, TIDAK bisa dibalik secara presisi) dan `CreateReceivableIfCredit` (bila `paymentMethod->is_credit`: `ReceivableService::create` membuat `Receivable`, `ReceivableItem`, dan `ReceivablePayment` untuk uang muka). `RecalculateEvent` -> `AdjustProduct` menyimpan ulang stok/harga produk. |
| Observer `SellingObserver::creating` | Mengisi `date = now()` (UTC) bila kosong, `code` (`SELL`+`Selling::all()->count()+1`: memuat SELURUH tabel `sellings` ke memori pada tiap penjualan; temuan di luar scope, perbaikan satu baris `Selling::query()->count()` dimasukkan ke paket `numbering` karena memperpendek waktu kunci), `cash_drawer_id` bila setting `cash_drawer_enabled` (`CashDrawer::lastOpened()->first()->id`, **crash 500 bila laci belum dibuka** di jalur Livewire; API memeriksanya lebih dulu di `TransactionSellingStoreRequest::authorize` dengan pesan `Cash drawer is not opened`), dan `user` = pengguna login (jadi penjualan atas nama yang MENERIMA uang, benar untuk rekonsiliasi laci). |
| Validasi bayar | API: `TransactionSellingStoreRequest::rules` (`payed_money gte:total`, kecuali kredit). Livewire: validator inline di `proceedThePayment` (kredit mewajibkan `member_id` dan `due_date`; metode non-tunai/wallet/debit dipaksa pas). `mapProductRequest` menghitung ulang `total_price`, `tax_price`, `money_changes` di server; kredit memaksa `money_changes = 0`. Rule stok: `CheckProductStock` (pesan generik tanpa nama produk). |
| Semantik harga baris | `cart_items.price` dan `selling_details.price` adalah **total baris sebelum diskon** (`selling_price * qty`); `discount_price` diskon baris; `price_per_unit = price/qty` (`SellingDetail::pricePerUnit`). Dengan `price_unit_id`, `AssignProduct` menghitung `price = unit.selling_price * qty` lalu MENGUBAH `qty = unit.stock * qty` (qty tersimpan dalam satuan dasar). Akibatnya harga satuan di struk untuk baris satuan-harga adalah per satuan dasar (batasan diketahui, dicatat). |
| Nomor harian dan nama kembar | `Selling::booted creating`: `daily_order_number = max + 1` memakai `whereDate('date', $tanggal)` lalu cadangan `created_at`; `CustomerNameService::generateDailyCustomerName` memakai `whereDate('date', ...)`. `date` disimpan sebagai string UTC (`now()->format('Y-m-d H:i:s')`, kolom DATETIME), jadi "hari" sekarang = **hari UTC** (reset pukul 07:00 WIB). `whereDate` membungkus kolom dengan fungsi sehingga **pemindaian penuh** tabel pada tiap penjualan. `config/app.php` timezone = `UTC`; `Profile::timezone` per pengguna (default `Asia/Jakarta` di `TenantLogin`) hanya dipakai untuk tampilan dan sebagian filter dasbor. |
| Pembaca `sellings` / `selling_details` | Tanpa filter `is_paid`: `DashboardController` (6 query), `SellingOverview` (kecuali omzet), `TodaysBestSellingProduct`, `CashierReportService`, `ProductReportService`, `SellingReportService`, `StatsProduct`, `SellingDetailsRelationManager`. Kolom `sellings.is_paid` (default true) sudah ada dan hanya dipakai `SellingController::index`, `ListSellings`, sebagian `SellingOverview`; **tidak ada** jalur yang membuat `is_paid=false`. Konsekuensi: **status "belum bayar" di tabel `sellings` akan bocor ke ~10 pembaca.** |
| `cart_items` | Satu keranjang per kasir (`user_id`, `product_id`, tanpa unique di DB), dibaca `Cashier`, `POS` (V2), `Pages/CartItem`, `PriceSetting`, `CartItemNote`. Tidak ada pembaca laporan. |
| Izin | Spatie permission dengan nama `create selling`, `read selling`, `update selling`, `delete selling`; owner lolos semua (`Gate::after`). Menambah izin baru berarti menyisipkan data (tidak diinginkan): **pakai izin yang ada**. |
| Cache dan kunci | `CACHE_DRIVER` default `file` (`.env.example` memakai `redis`). Kunci `file` hanya berlaku per server; `Cache::lock` tidak ada pada driver `array`. Salvage mencatat `LockTimeoutException` tak tertangani. |
| Konfigurasi MySQL | `config/database.php` mysql: `utf8mb4`, `utf8mb4_unicode_ci`, **`strict => false`**, engine `null`. Tidak ada `Schema::defaultStringLength`, jadi `users.email unique` (varchar 255, 1020 byte) sudah berhasil dibuat di produksi: **bukti kuat bahwa kunci indeks > 767 byte didukung** (MySQL >= 5.7.7 / MariaDB >= 10.2, DYNAMIC). |
| Struk | `receipt-print` v1 sudah mencetak `harga satuan x qty` + total baris. `grand_total_price` (`total - tax - diskon`) mengurangi pajak DUA kali bila pajak > 0 (cacat lama; tidak diubah, bon sementara memakai `amount_due` sendiri). |

### 1.2 Temuan di luar scope (dilaporkan, hanya satu yang diperbaiki)

1. `SellingObserver` memuat seluruh `sellings` (`Selling::all()`), O(N) memori per penjualan. **Diperbaiki** (perubahan perilaku nol).
2. `whereDate('date', ...)` pada pemindaian penuh: **diperbaiki** karena penomoran diganti query rentang + indeks baru.
3. `SellingOverview::getSalesToday` memakai `whereDate('date', Carbon di zona profil)`: hitungan "Sales today" salah antara 00:00-07:00 WIB. **Diperbaiki** ringan lewat `BusinessDay::range()` agar konsisten dengan nomor harian (paket `numbering`).
4. `grand_total_price` mengurangi pajak dua kali; `VoucherService` memakai `today()` UTC; `Cashier::mount`/`CartInteraction::refreshCart` memakai `today()` UTC untuk voucher: voucher yang aktif "hari ini" baru berlaku pukul 07:00 WIB. **Voucher diperbaiki** ke `BusinessDay::today()`; `grand_total_price` hanya dilaporkan.
5. Total/diskon manual yang ter-reset tiap request (plan.md 1.3, A29): **sekarang ikut diperbaiki** karena total bill harus sama antara keranjang, bon sementara, dan pembayaran (`OrderTotals`). Voucher tetap hilang setiap perubahan keranjang (perilaku lama) dan TIDAK disimpan di bill.
6. Stok: validasi hanya `stock_calculate >= qty` per produk; bill terbuka tidak memesan stok.

---

## 2. Keputusan (A-F), masing-masing dengan pembanding

> **[DIGANTIKAN/DIREVISI v3.1]** Arah keputusan A-F tetap, tetapi rincian yang bertentangan dikalahkan bagian 13-14: stok otoritatif lewat `StockGuard`, satu kunci penjualan global, void/pembatalan satu gerbang + alasan + `open_bill_events`, dapur dua fase (ack), token hanya pagar UX, kredit dicabangkan dulu lewat `PaymentPlanner`, kembali ke order baru setelah Simpan/Kirim.

### (A) Model data Open Bill

**Opsi:**
- **A1. Kolom `status` pada `sellings`** (bill = penjualan `is_paid=false`). Ditolak: (a) `AssignProduct` mengurangi stok dan `CreateReceivableIfCredit` membuat piutang saat `SellingCreated`, jadi bill harus ditulis dengan jalur khusus; (b) bocor ke ~10 laporan/dasbor yang tidak memfilter (1.1); (c) API `index/show` ikut; (d) nomor harian dan nama kembar bercampur; (e) mengubah tabel panas berisi data nyata. Radius ledakan: seluruh sistem.
- **A2. Tabel baru `open_bills` + `open_bill_items`** yang diubah menjadi penjualan biasa lewat `SellingService::create` SAAT DIBAYAR. **DIPILIH.** Laporan, stok, piutang, laci kas, API: tidak tersentuh sebelum bayar karena baris `sellings` baru lahir saat bayar dan melewati semua listener yang sama. Hanya tabel baru yang ditambah (aman dan mudah dibatalkan).
- **A3. `open_bills` + baris di `cart_items` lewat kolom `open_bill_id`.** Ditolak walau menghemat tabel: mengubah tabel yang dibaca `POS`/`Pages/CartItem`/`PriceSetting`; scope `cashier()` harus diubah (kode lama setelah rollback akan menampilkan baris bill di keranjang pribadi pembuatnya dan bisa menjualnya); riwayat bill bercampur di tabel keranjang.
- **A4. "Setiap pesanan adalah bill sejak item pertama".** Ditolak: bill-draft yatim, menulis ulang `POS`/`CartItem`, dan membuang pekerjaan wave-1 tanpa manfaat bagi Randy.

**Rincian keputusan A2:**

| Topik | Keputusan | Alasan |
|---|---|---|
| Hubungan dengan `cart_items` | `cart_items` tetap keranjang pribadi "pesanan baru" (tidak diubah selain kolom `note`). Konteks aktif kasir: `openBillId = null` (keranjang pribadi) atau `openBillId = X` (baris di `open_bill_items`). Kedua konteks bisa ada bersamaan; yang tampil hanya satu. Menyimpan bill = memindahkan baris keranjang pribadi ke bill baru dalam satu transaksi dan mengosongkan keranjang. | Nol perubahan pada pembaca keranjang lama; rollback kode aman (kode lama mengabaikan tabel baru). |
| Abstraksi baris | `CartInteraction` mendapat `lineQuery()`/`lineModel()`/`activeBillId()` (default: keranjang pribadi, jadi `POS` dan `Pages/CartItem` tidak berubah). `OpenBillItem` memakai `HasOrderLine` dan nama atribut yang sama (`product_id, qty, price, discount_price, price_unit_id, note`) sehingga `OrderLineSorter`, `OrderTotals`, view, dan pembuatan `products[]` identik. `wire:key` memakai awalan konteks (`cart-{id}` / `bill{billId}-{id}`) supaya komponen anak (`CartItemNote`) tidak dipakai ulang lintas konteks. | Hindari bug "komponen anak lama dengan key sama". |
| Stok | **Tidak ada pemesanan stok.** Validasi saat tambah item = sama dengan keranjang (`stock_calculate >= qty` per baris). Pengecekan wajib dan otoritatif saat BAYAR (`CheckProductStock` + `AssignProduct`). Pesan kegagalan menyebut nama produk dan stok tersisa. | `StockService::reduceStock` memecah lapisan FIFO dan tidak bisa dibalik presisi; menahan stok saat simpan menuntut buku besar stok baru (radius ledakan besar). Risiko: dapur memasak item yang stoknya habis di jalur lain (dicatat sebagai risiko R3; mitigasi: peringatan, bukan blokir, saat tambah item bila qty > stok). |
| Pelacakan dapur | Kolom `open_bill_items.sent_qty` (double, default 0) dan `sent_note` (string 255, null). Pengurangan di bawah `sent_qty` atau penghapusan baris yang sudah dikirim dicatat saat itu juga ke `open_bills.pending_cancel` (JSON teks) dan `sent_qty` diturunkan. "Kirim ke dapur" menghitung batch: baris dengan `qty > sent_qty` (selisih; `isAddition` bila `sent_qty > 0`), baris `qty == sent_qty` dengan `note != sent_note` (UBAH), plus `pending_cancel` (BATAL); lalu `sent_qty = qty`, `sent_note = note`, `kitchen_batch_count++`, `last_kitchen_batch = batch`, `pending_cancel = null`. Tidak ada selisih = tidak mencetak ("Tidak ada item baru untuk dikirim"). Cetak ulang: `last_kitchen_batch` (batch terakhir) atau seluruh isi bill (bertanda SALINAN). | Cetakan berhasil/gagal tidak bisa diketahui server (printer di browser), jadi server menandai terkirim saat batch dibuat dan menyediakan cetak ulang; item yang sudah dikirim dihapus meminta konfirmasi dan tidak ditawari Undo. |
| Void | `status = void`, `voided_by/at`, `void_reason` (wajib, maks 255). Baris tetap ada. Bill tanpa kiriman dapur: pembuat atau pemegang izin `update selling`; bill yang sudah dikirim: wajib izin `delete selling` (owner lolos), tiket BATAL tercetak. Bill paid tidak bisa di-void (pembatalan penjualan = alur penjualan yang ada). | Tanpa izin baru (tanpa penyisipan data). |
| Siapa boleh | Buka/ubah/kirim/bon/bayar: izin `create selling` (sama dengan akses halaman Cashier). Setiap method publik komponen `OpenBills` dan aksi bill di `Cashier` memeriksa `can('create selling')` karena method Livewire bisa dipanggil langsung. | Livewire public method = endpoint. |
| Konkurensi | Setiap mutasi bill (baris, header, kirim, bayar, void) berjalan dalam `OpenBillService::mutate()`: transaksi + `lockForUpdate` pada baris `open_bills` + cek `status = open` + `version++`. `expectedVersion` dari klien WAJIB untuk kirim/bayar/void/ubah header (apa yang Anda lihat = apa yang dikirim/dibayar); operasi baris tidak memeriksa versi (hanya menaikkan) dan keranjang selalu dimuat ulang dari DB setelah operasi. `UNIQUE (open_bill_id, product_id)` mencegah baris ganda. Pembanding: kunci keras "sedang dibuka oleh X" (ditolak: kunci basi bila browser ditutup, butuh "ambil alih") dan last-write-wins murni (ditolak: bayar/kirim diam-diam tidak sesuai tampilan). | Optimistic + kunci baris sesaat sederhana dan tanpa kunci basi. Polling ringan `wire:poll.20s.visible` hanya saat konteks bill. |
| Penomoran bill | `open_bills.number` + `business_date`; nomor diambil dari urutan harian BERSAMA dengan penjualan (lihat E). Unik `(business_date, number)`. Nomor bill yang dibatalkan tidak dipakai ulang (celah wajar, jejak audit). | Satu nomor dari dapur sampai struk. |
| Data daftar bill | `OpenBillService::listOpen()`: 3 query (bill + relasi meja/pembuat/member, baris dengan `priceUnit`, jumlah) lalu total dihitung `OrderTotals` di PHP. Kolom: `id, number, label, table, customer_name, creator, items_count, amount_due, age_minutes, stale (business_date < hari ini), sent (kitchen_batch_count > 0), version`. Diurutkan `business_date, number`. Filter klien: Semua / Bill saya / Dari kemarin. Meja yang sudah punya bill terbuka: peringatan + tawaran membuka bill itu (tanpa constraint DB, karena pisah-bill sah). | |
| Kill-switch | `Setting::get('open_bill_enabled', true)`: bila `false`, UI bill disembunyikan (tanpa migrasi). Tombol dapur dan meja hanya untuk `business_type = fnb`; bon sementara dan Simpan bill untuk semua jenis. | |
| Bayar bill | `OpenBillService::pay()` membangun `products[]` dari baris bill dan memanggil `SellingService::create(data, hooks)` (hook `before` mengunci dan memverifikasi bill di dalam transaksi penjualan, hook `after` menandai `paid` + `selling_id` dalam transaksi yang SAMA). Tidak ada transaksi luar sebelum kunci penomoran. Bila bill dan pembayaran di hari bisnis yang sama: `daily_order_number = bill.number` dan `customer_name` dipakai apa adanya (`keep_customer_name`); bila beda hari: nomor dan penomoran nama baru untuk hari pembayaran. | Atomik: penjualan dan status bill tidak bisa berselisih. |

### (B) Perombakan pembayaran

Opsi: B1 menambal modal lama (Alpine sebesar 340 baris, deteksi QRIS dari NAMA metode, `isCash` aneh); **B2 modal baru berbasis jenis metode (DIPILIH)**; B3 pembayaran terpisah (ditolak).

- **Jenis metode** dari flag, bukan nama: `PaymentMethod::kind()` = `credit` bila `is_credit`; selain itu `cash` bila `is_cash && !is_wallet && !is_debit`; selain itu `exact`. Server menyertakan `kind` pada `$paymentMethods` (kontrak 7.3). Aturan server `proceedThePayment` yang sudah ada (non-tunai dipaksa pas, kredit wajib member + due date, tunai `payed_money >= total`) TIDAK berubah, jadi kompatibel API dan data lama.
- **Struktur modal:** (1) kepala: "Total tagihan" besar + chip bill (nomor/meja) bila konteks bill; (2) rincian pesanan terkelompok kategori yang bisa dilipat (default terbuka bila <= 5 baris): `nama`, `2 x 25.000`, `50.000`, diskon baris, catatan item; (3) kotak metode (grid 2-3 kolom, tinggi >= 56 px, ikon dari `icon` bila ada, jika tidak ikon bawaan per `kind`; urutan: tunai, lalu lainnya A-Z; metode terakhir dipakai per perangkat disimpan di `localStorage` `lakasir.cashier.lastPaymentMethod` dan hanya dipakai bila masih ada); (4) panel per jenis:
  - **cash:** layar "Uang diterima", tombol **Uang pas** + sampai 3 saran pecahan (algoritma saran lama dibersihkan: unik, > total, terurut), papan angka 3x4 (`7 8 9 / 4 5 6 / 1 2 3 / 000 0 hapus`) dan tombol C, keyboard fisik (digit, Backspace, Enter), baris kembalian hijau atau "Kurang Rp X" merah; tombol Bayar nonaktif sampai uang >= total.
  - **exact (QRIS/debit/wallet):** panel "Pembayaran pas" + tombol "Konfirmasi pembayaran diterima" (konfirmasi eksplisit, tanpa papan angka).
  - **credit:** pilih member (wajib), jatuh tempo (bawaan hari ini + 14 hari = perilaku lama `addWeek(2)`), uang muka opsional memakai papan angka yang sama, baris "Sisa piutang Rp X". `payed_money` = uang muka (backend sudah mendukung: `ReceivableService` mencatat `ReceivablePayment`).
  - (5) Kotak pesan kesalahan di dalam modal untuk `ValidationException` (stok per produk dengan nama, member, jatuh tempo, uang kurang, laci kas belum dibuka) dalam bahasa Indonesia.
- **Anti-ganda:** klien: `submitting` menonaktifkan semua tombol dan `wire:loading.attr`; server: `paymentToken` (UUID dibuat di `syncCartForPayment`) dicek dengan `Cache::add("pay:{userId}:{token}", 1, 600)`; token yang sudah dipakai diabaikan diam-diam, dan untuk bill status `paid` mengembalikan struk yang ada ("Bill sudah dibayar"). Keranjang pribadi sudah aman karena baris dihapus setelah sukses.
- **Pembayaran dari bill:** dari daftar bill, "Bayar" membuka bill di register lalu memuat modal yang SAMA (satu jalur kode).
- **Pembayaran terpisah (tunai + QRIS): TIDAK dibuat.** `sellings` hanya punya satu `payment_method_id` dan satu `payed_money`; pemisahan butuh tabel pembayaran baru dan ubahan semua laporan kasir/laci. Pengganti murah yang sudah ada: kredit + uang muka. Dicatat sebagai pekerjaan lanjutan.
- API: tidak ada perubahan perilaku dan tidak ada endpoint bill baru (di luar scope).

### (C) Harga per item pada pratinjau dan cetak

Aturan tunggal: **harga satuan x qty = total baris** (`OrderTotals::unitPrice/lineTotal`), diskon baris di baris sendiri, catatan item di bawahnya. Tempat: baris keranjang (`data-testid="cart-item-unit-{id}"`, `cart-item-total-{id}"`), rincian modal bayar, kartu/rincian di daftar bill, pratinjau cetak, bon sementara, struk, tampilan detail penjualan (kolom Unit price sudah ada). Tiket dapur: tanpa harga kecuali `Setting::get('kitchen_ticket_show_price', false)` (toggle di halaman Printer). Alasan: dapur butuh jumlah, catatan, kategori; harga memperpanjang kertas 58 mm dan membuka harga ke staf dapur; Randy minta harga untuk "pratinjau dan print" pelanggan. Pertanyaan 3 di 0.5.

### (D) Mode cetak

Opsi: D1 satu `build` penuh `if (mode)`; **D2 `build(printer, payload, {mode})` dengan pembangun per bagian bersama (header/meta/items/totals/footer) dan perbedaan per mode lewat tabel kecil (DIPILIH)**; D3 tiga fungsi terpisah (ditolak: tiga salinan = persis masalah "salinan inline" yang dibereskan).

| Mode | Header | Meta | Item | Total/penutup |
|---|---|---|---|---|
| `receipt` (setelah bayar) | logo, nama toko, alamat, header printer | `Order #NNN` besar, kasir, meja, pembayaran, pelanggan, catatan | per kategori, harga | pajak, subtotal, diskon, total, bayar, kembali, footer, `SALINAN` bila reprint |
| `bill` (bon sementara) | nama toko, alamat, **BON SEMENTARA**, `BELUM LUNAS` | `Order #NNN` (nomor bill), kasir pembuat, meja, pelanggan, jam | per kategori, harga | Subtotal, Pajak (fitur), Diskon, **TOTAL (`amount_due`)**, `BELUM LUNAS`, footer; TANPA baris bayar/kembali |
| `kitchen` (tiket dapur) | **DAPUR** bold tinggi dua kali; `PESANAN BARU` / `TAMBAHAN #n`; tanpa logo/alamat | `Order #NNN`, meja, pelanggan, jam, kasir | per kategori; **qty besar** (`size(0,1)` + bold, `2 x Es Teh`), catatan item `  > ...`, tanpa harga (kecuali setting) | bagian `BATAL:` dan `UBAH:` bila ada, catatan pesanan, garis; `SALINAN` bila cetak ulang |

Tata letak item (struk dan bon), lebar 32 (58 mm) / 48 (80 mm); bila `kiri + kanan > lebar`, baris ditumpuk:
```
Nasi Goreng Spesial
2 x 25.000                50.000
  Diskon                 (2.000)
  > pedas, tanpa telur
```
Fungsi baru `LakasirReceipt.preview(payload, opts): string` memakai `TextPrinter` (printer palsu yang merekam teks per lebar kolom) sehingga **pratinjau = cetakan persis (WYSIWYG)** dan dapat dites di Node.

### (E) BusinessDay dan penomoran

**E1. Hari bisnis.** `config/app.php` tetap `UTC` (mengubahnya menggeser cara SEMUA kolom datetime terbaca; dilarang). `App\Support\BusinessDay` (kontrak 7.1): zona dari `config('pos.business_timezone')` (`env POS_BUSINESS_TIMEZONE`, bawaan `Asia/Jakarta`, divalidasi terhadap `timezone_identifiers_list()`), `today()`, `dateOf($momen)` (string tanpa zona dianggap UTC seperti penyimpanan; string tanggal saja `2026-10-15` = tengah malam UTC = 07:00 WIB, tanggal sama), `startUtc/endUtc` (batas hari bisnis dalam UTC, akhir eksklusif), `range()`, `scopeDay($query, $kolom, $tanggal)` (`>= start AND < end`, bind string `Y-m-d H:i:s` UTC; berlaku sama untuk `date` DATETIME dan `created_at` TIMESTAMP di MySQL maupun SQLite). Dipakai: `DailyNumber`, `CustomerNameService`, `Selling::creating`, `OpenBillService`, `VoucherService`, `Cashier::mount`, `CartInteraction::refreshCart` (voucher), `SellingOverview::getSalesToday`, penanda "Dari kemarin". Zona profil pengguna TIDAK dipakai (penomoran harus sama untuk semua kasir).

**E2. Pembanding pengunci penomoran** (salvage: `Cache::lock` setengah jadi):

> **[DIGANTIKAN/DIREVISI v3.1]** Kunci PER HARI diganti SATU baris konstan `numbering_locks.name='sales'` untuk setiap penjualan (13.1 DA-I1); `ensure()` = SELECT dulu (13.1 DA-I6); urutan kunci global R1 (14.0). Tabel pembanding di bawah tetap valid sebagai alasan memilih opsi 4.

| Opsi | Kelebihan | Kekurangan | Keputusan |
|---|---|---|---|
| 1. `Cache::lock` (sekarang) | sederhana | `file` hanya per server; bergantung `CACHE_DRIVER`; timeout tak tertangani; lupa lepas bila proses mati sebelum TTL | ditolak |
| 2. MySQL `GET_LOCK` | lintas server | khusus MySQL; cabang tak teruji di SQLite | ditolak |
| 3. Tabel penghitung (`daily_counters`) | atomik | dua sumber kebenaran (penghitung vs baris), perlu seed dari data lama | ditolak |
| 4. **Baris kunci per hari `numbering_locks(business_date PK)` + `SELECT ... FOR UPDATE` di dalam transaksi** | tidak bergantung cache; lepas otomatis saat commit/rollback; lintas server; no-op wajar di SQLite; tetap `max()+1` (tanpa seed) | satu tabel kecil (1 baris/hari) | **DIPILIH** |

Aturan wajib (kunci MVCC `REPEATABLE READ`): (1) `NumberingLock::ensure($hari)` (`insertOrIgnore`, autocommit, SEBELUM transaksi, mencegah deadlock S-lock); (2) `DB::beginTransaction()`; (3) `NumberingLock::acquire($hari)` (`select ... for update`) sebagai **pernyataan PERTAMA di dalam transaksi** (pembacaan konsisten pertama membuat snapshot; `max()` harus terjadi setelah kunci); (4) hitung `DailyNumber::next` dan nama kembar; (5) simpan. **Dilarang** membuka transaksi luar sebelum `SellingService::create` di jalur bayar-bill (itulah alasan hook `before/after`). Sabuk pengaman: `UNIQUE (business_date, number)` di `open_bills` (ulang sekali bila 23000).

**E3. Satu urutan atau dua?** (a) Urutan bersama penjualan + bill (**DIPILIH**): `DailyNumber::next` = `max(max(sellings.daily_order_number di hari), max(open_bills.number di hari)) + 1`. Satu nomor dari tiket dapur sampai struk; tidak ada tabrakan dengan penjualan langsung. (b) Urutan terpisah "B-001": dua nomor untuk satu pesanan, membingungkan dapur/pelanggan. Bill yang dibayar lintas tengah malam mendapat nomor baru di hari pembayaran (supaya tidak menabrak nomor hari baru).

**E4. Nama kembar.** `CustomerNameService::generateDailyCustomerName($nama, ?string $hariBisnis, ?int $kecualiSellingId, ?int $kecualiBillId)`: menghitung dari `sellings` dalam rentang hari bisnis (+ cadangan `created_at` bila `date` null) DAN `open_bills` berstatus `open` pada hari itu. Bill menyimpan nama FINAL ("Budi (2)") saat dibuat/diubah (jadi tiket dapur sudah final), dan penjualan memakainya apa adanya (`keep_customer_name`) bila dibayar di hari yang sama. Perilaku lama yang dikunci test (mis. "Meja (5)" dipotong) tidak diubah.

**E5. Hari pertama setelah deploy.** Penjualan 00:00-07:00 WIB yang bernomor menurut hari UTC lama ikut dihitung ke hari bisnis baru: bila toko beroperasi pada jam itu pada hari deploy, nomor hari itu bisa lanjut dari malam sebelumnya; mulai tengah malam berikutnya reset tepat. Tidak ada penulisan ulang data (dicatat untuk Randy).

**E6. Strategi test:** `Carbon::setTestNow` pada batas UTC: `16:59:59Z` (23:59:59 WIB) dan `17:00:00Z` (00:00:00 WIB); pasangan penjualan sebelum/sesudah batas (nomor 2 lalu 1); nama "Budi" 2x sebelum batas ("Budi", "Budi (2)") dan 1x sesudah ("Budi"); bill dibuat 23:59 WIB dan dibayar 00:10 WIB (nomor baru, nama dinomori ulang); zona override (`pos.business_timezone = Asia/Makassar` menggeser batas ke `16:00Z`); `date` hanya tanggal; `date` null memakai `created_at`; baris lama; **test lama harus membekukan waktu** di tengah hari WIB (mis. `2026-10-15 05:00:00Z`) karena `now()->toDateString()` (UTC) berbeda hari dari hari bisnis antara 17:00-24:00 UTC (tes bisa gagal sesuai jam eksekusi bila tidak dibekukan).

### (F) Migrasi: lihat bagian 6 (daftar lengkap, audit migrasi yang sudah ada, urutan rilis, rollback, daftar Randy).

---

## 3. Spesifikasi data (DDL ringkas)

> **[DIGANTIKAN/DIREVISI v3.1]** Skema persis ada di 14.3 (tambah `tax_percent`, `open_bill_events`, `numbering_locks.name`, MEDIUMTEXT, migrasi `000002..000006`).

Semua `bigint unsigned` mengikuti `id()` tabel induk. Tanpa `->after()`, tanpa `->change()`, tanpa isi data.

```
open_bills
  id                  bigint unsigned PK AI
  number              int unsigned NOT NULL
  business_date       date NOT NULL
  status              varchar(12) NOT NULL DEFAULT 'open'       -- open|paid|void (enum PHP, BUKAN ENUM MySQL)
  version             int unsigned NOT NULL DEFAULT 1
  customer_name       varchar(255) NULL
  table_id            bigint unsigned NULL  FK tables.id  ON DELETE SET NULL
  member_id           bigint unsigned NULL  FK members.id ON DELETE SET NULL
  note                text NULL
  discount_price      double NOT NULL DEFAULT 0
  kitchen_batch_count smallint unsigned NOT NULL DEFAULT 0
  last_kitchen_batch  text NULL                                  -- JSON
  pending_cancel      text NULL                                  -- JSON
  kitchen_sent_at     datetime NULL
  created_by          bigint unsigned NULL  FK users.id ON DELETE SET NULL
  paid_by             bigint unsigned NULL  FK users.id ON DELETE SET NULL
  voided_by           bigint unsigned NULL  FK users.id ON DELETE SET NULL
  paid_at             datetime NULL
  voided_at           datetime NULL
  void_reason         varchar(255) NULL
  selling_id          bigint unsigned NULL  FK sellings.id ON DELETE SET NULL
  created_at, updated_at timestamp NULL
  UNIQUE open_bills_day_number_unique (business_date, number)
  INDEX  open_bills_status_day_index  (status, business_date)
  UNIQUE open_bills_selling_id_unique (selling_id)

open_bill_items
  id                  bigint unsigned PK AI
  open_bill_id        bigint unsigned NOT NULL FK open_bills.id ON DELETE CASCADE
  product_id          bigint unsigned NOT NULL FK products.id   (restrict, sama dengan cart_items)
  user_id             bigint unsigned NULL     FK users.id ON DELETE SET NULL   -- siapa menambah
  qty                 double NOT NULL
  price               double NULL            -- total baris sebelum diskon (sama dengan cart_items.price)
  discount_price      double NULL
  price_unit_id       bigint unsigned NULL   FK price_units.id ON DELETE SET NULL
  note                varchar(255) NULL
  sent_qty            double NOT NULL DEFAULT 0
  sent_note           varchar(255) NULL
  created_at, updated_at timestamp NULL
  UNIQUE open_bill_items_bill_product_unique (open_bill_id, product_id)

numbering_locks
  business_date       date PRIMARY KEY

sellings (ALTER, satu indeks)
  INDEX sellings_date_number_index (date, daily_order_number)
cart_items / selling_details (ALTER, sudah ada di wave-1)
  note varchar(255) NULL
```
Panjang indeks (utf8mb4, 4 byte/karakter): `open_bills_status_day_index` = 12*4+2+3 = 53 B; `open_bills_day_number_unique` 7 B; `open_bill_items_bill_product_unique` 16 B; `sellings_date_number_index` ~ 8+4 B; PK `numbering_locks` 3 B. Semua jauh di bawah 3072 B. Nama indeks <= 64 karakter (terpanjang 35).

---

## 4. Layanan dan perilaku (ringkas; kontrak lengkap di bagian 7)

> **[DIGANTIKAN/DIREVISI v3.1]** Kontrak lengkap sekarang di bagian 14.

- `OrderTotals`: satu-satunya rumus total (sama dengan `Cashier::calculateTotalPrice`): `amount_due = subtotal + subtotal*tax/100 - line_discount - header_discount` (min 0). Dipakai keranjang, bill, bon sementara, daftar bill, dan modal bayar sehingga angkanya selalu sama. `Cashier::calculateTotalPrice` membaca diskon header dari `cartDetail['discount_price']` (perilaku "total ter-reset" hilang).
- `OpenBillService`: `createFromCart`, `mutate`, `updateHeader`, `kitchenBatch`, `registerCancellation`, `void`, `pay`, `listOpen`, `totals`, `printPayload` (lewat `OpenBillPresenter`). Mengembalikan/melempar `BillConflictException`, `BillClosedException`, `BillEmptyException` (pesan sudah diterjemahkan, ditampilkan sebagai notifikasi dan di modal).
- `SellingService::create(array $data, array $hooks = [])`: tidak lagi memakai `Cache::lock`; urutan: `NumberingLock::ensure(hari)` -> `beginTransaction` -> `NumberingLock::acquire(hari)` -> `hooks['before']` -> `Selling` dibuat (flag transien `numberingLocked`, `keepCustomerName`) -> `SellingCreated` -> `RecalculateEvent` -> `hooks['after']($selling)` -> commit; kegagalan di mana pun = rollback total. `$data['date']` menentukan hari; kosong = sekarang. Pemanggil API tidak berubah.
- `Selling::creating`: tetap sebagai cadangan untuk pembuatan di luar `SellingService` (seeder, factory, test): jika `numberingLocked` belum diset, ia memanggil `NumberingLock::acquire` sendiri. Nomor dan nama dihitung memakai `BusinessDay`.
- Cashier: konteks bill (bagian 7.4), tombol aksi, cetak lewat event browser `bill-print`.

---

## 5. Cetak: kontrak payload dan JS (ringkas; 7.6)

> **[DIGANTIKAN/DIREVISI v3.1]** Kontrak cetak sekarang di 14.8 (antrean, dua fase, tata letak dapur baru).

`OpenBillPresenter::payload` menghasilkan objek berbentuk `Selling` (`selling_details[]` dengan `qty, price (total baris), discount_price, note, product{id,sku,name,category{id,name}|null}`) plus: `daily_order_number`, `formatted_daily_order_number`, `customer_name`, `table`, `member`, `note`, `note_plain`, `user.name`, `created_at_label`, `printed_at_label` (sudah diformat di zona bisnis agar JS tidak bergantung zona perangkat), `subtotal, tax, tax_price, discount_price, total_discount_per_item, amount_due`, `kitchen{batch, is_addition, cancelled[], changed[]}` (hanya mode dapur; `selling_details` berisi hanya baris batch dengan `qty` = selisih). Penjualan dari bill mencetak struk lewat `Selling::loadForReceipt()` seperti biasa.

---

## 6. Migrasi MySQL (F)

### 6.1 Audit migrasi yang sudah ada

| Migrasi | Temuan | Tindakan |
|---|---|---|
| `2026_10_14_000001_add_pos_performance_indexes` (ter-commit) | (a) Tanpa `hasIndex`: dijalankan ulang atau setelah kegagalan sebagian (DDL MySQL tidak transaksional, mis. gagal di indeks ke-4) akan error `1061 Duplicate key name`. (b) `down()` berpotensi GAGAL di MySQL (`1553 Cannot drop index needed in a foreign key constraint`; dokumentasi MySQL: indeks implisit FK boleh dibuang diam-diam begitu ada indeks lain yang bisa melayani FK): setelah indeks komposit dibuat, indeks implisit FK (`cart_items_user_id_foreign`, FK `stocks.product_id`) dibuang MySQL, sehingga komposit menjadi satu-satunya indeks FK. (c) Panjang kunci: `stocks_pos_lookup_index` = 8+1+(255*4+2)+8 ~ 1.04 KB, `products_pos_listing_index` ~ 1.03 KB, `products_sku_index` ~ 1.02 KB; aman pada DYNAMIC (3072 B) dan terbukti didukung (`users.email unique`). Gagal total pada MariaDB < 10.2 / MySQL < 5.7.7 dengan COMPACT (767 B). (d) Pembuatan indeks sekunder InnoDB bersifat online (tanpa menyalin tabel) di 5.6+. | **Tulis ulang di tempat**: bungkus tiap indeks dengan `Schema::hasIndex`; `down()` hanya membuang indeks bila ada dan, sebelum membuang indeks komposit, memastikan ada indeks FK pengganti (`stocks_product_id_index`, `cart_items_user_id_index`). Menulis ulang aman baik migrasi sudah dijalankan di produksi (tidak dijalankan ulang) maupun belum. |
| `2026_09_20_000001/2` (customer_name, daily_order_number) | Sudah di produksi (dipakai kode yang ada). Menggunakan `->after()` dan `index()` bernama otomatis; guard sudah ada. | Tidak disentuh. |
| `2026_10_15_000001_add_note_to_cart_items_and_selling_details_tables` (belum ter-commit) | nullable `string(255)`, tanpa `after()`, guard `hasColumn`, dua `Schema::table` terpisah. MySQL 8.0.12+/MariaDB 10.3+: `ADD COLUMN` di akhir = INSTANT; MySQL 5.7: INPLACE dengan penulisan ulang tabel (DML tetap boleh) untuk `selling_details` yang besar. | **Dipertahankan.** Dijadwalkan jam sepi; cek jumlah baris `selling_details` (6.5). |
| `2026_10_15_000002_add_listing_indexes_to_products_table` (belum ter-commit) | `(category_id, show)` mubazir (indeks FK `category_id` sudah melayani filter, `show` boolean berselektivitas rendah); `(show, name)` nyaris tak membantu pencarian infix `LIKE '%x%'`. Menambah indeks pada tabel `products` yang hidup tanpa bukti manfaat di MySQL. | **DIBATALKAN (berkas dihapus)**. Jaga anggaran performa lewat `CashierPerfTest` (SQLite); bila anggaran gagal, eskalasi (jangan menambah indeks diam-diam). Tindak lanjut opsional: `EXPLAIN` pada salinan produksi. |

### 6.2 Migrasi baru (urutan jalan)

> **[DIGANTIKAN/DIREVISI v3.1]** Tujuh berkas dan polanya: 14.3 dan 14.9 (create hanya kolom, lalu indeks/FK satu per satu idempoten).

| Berkas | Isi | Catatan keamanan |
|---|---|---|
| `2026_10_15_000001_add_note_to_cart_items_and_selling_details_tables` | (sudah ada) | lihat 6.1 |
| `2026_10_15_000002_create_open_bills_table` | tabel `open_bills` (bagian 3) | `Schema::hasTable` guard; hanya `create`; FK ke tabel induk yang ada (semua `id()` bigint unsigned; InnoDB; cek engine di 6.5); `down()` = `dropIfExists` (hanya dev) |
| `2026_10_15_000003_create_open_bill_items_table` | tabel `open_bill_items` | sama |
| `2026_10_15_000004_create_numbering_locks_table` | tabel `numbering_locks` | sama |
| `2026_10_15_000005_add_date_index_to_sellings_table` | `index(['date','daily_order_number'], 'sellings_date_number_index')` | **satu-satunya perubahan pada tabel `sellings`**; online; boleh dilewati (kode tetap benar, hanya lebih lambat pada tabel besar) |

Aturan untuk SEMUA berkas baru: guard `hasTable/hasColumn/hasIndex` (idempoten dan aman dijalankan ulang), nama indeks eksplisit <= 64 karakter, `string()` bukan `text` untuk kolom beindeks, tanpa `->after()`, tanpa `->change()`, tanpa `DB::table(...)->update/insert` (nol pengisian data), kolom tambahan NOT NULL selalu `DEFAULT` konstan, tanpa ENUM MySQL, `down()` hanya membalik apa yang dibuat berkas itu dan diberi komentar "dev saja, jangan di produksi", dan tidak ada migrasi yang mengubah data yang ada. `strict => false` di produksi tidak dijadikan sandaran (semua kolom ber-DEFAULT eksplisit).

### 6.3 Perilaku MySQL yang sudah dipertimbangkan

- Penguncian: `FOR UPDATE` pada `numbering_locks` (PK) dan `open_bills` (PK); urutan kunci selalu: baris hari -> baris bill -> baris item -> stok/penjualan (tanpa jalur terbalik, jadi tidak ada deadlock siklik); pada `deadlock` (1213) `OpenBillService` mengulang satu kali.
- `REPEATABLE READ`: lihat aturan E2 (tidak ada pembacaan konsisten sebelum kunci hari).
- Transaksi bersarang `DB::beginTransaction` berarti savepoint; `SellingService` tetap pemilik transaksi pada jalur bayar.
- Waktu: `date` DATETIME dan `created_at` TIMESTAMP keduanya diikat sebagai string UTC `Y-m-d H:i:s`; perbandingan rentang konsisten pada MySQL maupun SQLite.
- Kolasi `utf8mb4_unicode_ci` (tidak peka huruf besar): perbandingan nama di PHP memakai `mb_strtolower` seperti sebelumnya.
- `Schema::hasColumn/hasIndex/hasTable` memakai `information_schema` (cukup cepat; hanya dijalankan saat migrasi).

### 6.4 Urutan rilis dan rollback

> **[DIGANTIKAN/DIREVISI v3.1]** Diganti 18.1 dan 18.4.

Prinsip expand/contract: skema baru kompatibel dengan kode lama (kolom nullable/default, tabel baru), kode baru tidak kompatibel dengan skema lama. Maka:
1. `migrate` (skema) DULU atau serentak dengan deploy kode, **tidak pernah sesudahnya**.
2. Deploy kode + aset (`npm run build` di CI/lokal; `public/build` ikut), lalu `php artisan optimize:clear` dan `view:clear`. `cache:clear` menghapus draft kasir (cache) dan cache Setting; diterima.
3. Reload keras semua perangkat kasir (JS/CSS berubah; tab lama tanpa kolom `note` aman karena nullable).
4. Rollback = pasang kembali kode lama. **Skema TIDAK di-rollback** (kode lama mengabaikan tabel baru; bill yang sudah ada tetap aman dan terbaca lagi saat kode baru dipasang). `down()` ada hanya untuk dev (menjatuhkan kolom `note` = kehilangan data catatan). Bila ada bill terbuka saat rollback: bayar/batalkan dulu atau biarkan terbuka sampai kode baru kembali; kode lama tidak melihatnya (tidak ada kebocoran ke keranjang).

### 6.5 Daftar tugas Randy (bahasa Indonesia, urut)

> **[DIGANTIKAN/DIREVISI v3.1]** Diganti 18.2 (gerbang `pos:preflight`, `down`, migrasi satu per satu, tanpa `migrate --force` tanpa batas).

**Sebelum deploy**
1. Backup: `mysqldump --single-transaction --routines --triggers -u USER -p NAMADB > backup-2026-10-XX.sql`; pastikan ukuran berkas wajar dan simpan salinan di luar server.
2. Cek fakta (jalankan di MySQL, kirim hasilnya ke saya):
   `SELECT VERSION(), @@innodb_default_row_format, @@sql_mode;`
   `SELECT TABLE_NAME, ENGINE, ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND (ENGINE <> 'InnoDB' OR ROW_FORMAT NOT IN ('Dynamic','Compressed'));` (harus kosong)
   `SELECT COUNT(*) FROM sellings; SELECT COUNT(*) FROM selling_details;`
   Dan baca `CACHE_DRIVER` di `.env` produksi.
3. `php artisan migrate:status` dan catat apakah `2026_10_14_000001_add_pos_performance_indexes` sudah `Ran`.
4. `php artisan migrate --pretend` (tidak mengubah apa-apa): isinya hanya boleh `create table`, `alter table ... add`, `create index`/`add index`. Bila ada `drop`/`rename`/`modify`, hentikan dan kirim ke saya.
5. Pilih jam sepi (tutup toko). `php artisan down` boleh tetapi tidak wajib (perubahan aditif).
**Deploy**
6. `php artisan migrate --force` (sebelum kode baru aktif). Pantau: harus selesai dalam hitungan detik; `ALTER` pada `selling_details` bisa memakan waktu lebih lama di MySQL 5.7 dengan ratusan ribu baris (tetap online).
7. Pasang kode, `php artisan optimize:clear`, `php artisan view:clear`, `php artisan up`.
8. Di SETIAP perangkat kasir: reload keras (Ctrl+Shift+R atau tombol Reload).
**Sesudah deploy (uji asap, 10 menit)**
9. (a) Satu penjualan tunai langsung; (b) satu bill: simpan, kirim dapur, tambah item, kirim lagi, bon sementara, bayar; (c) bandingkan laporan harian dengan jumlah yang dibayar; (d) cek stok produk yang terjual; (e) besok pagi 00:05 WIB buat satu penjualan: nomor harus 1.
**Dilarang di produksi:** `php artisan migrate:rollback`, `migrate:fresh`, `migrate:refresh`, `db:wipe`.

---

## 7. KONTRAK FINAL v3 (menggantikan plan.md 11.3 untuk yang disebut; sisanya tetap)

> **[DIGANTIKAN/DIREVISI v3.1]** Seluruh bagian 7 DIGANTIKAN oleh bagian 14. Jangan mengimplementasikan dari 7.1-7.6.

### 7.1 Dukungan hari bisnis dan penomoran (paket `numbering`)

```php
// config/pos.php
return ['business_timezone' => env('POS_BUSINESS_TIMEZONE', 'Asia/Jakarta')];

namespace App\Support;
final class BusinessDay {
  public static function timezone(): string                                   // valid atau 'Asia/Jakarta'
  public static function today(?\Carbon\CarbonInterface $now = null): string  // 'Y-m-d' hari bisnis
  public static function dateOf(\Carbon\CarbonInterface|string|null $moment): string
  //   string tanpa zona = UTC; null = sekarang; tanggal saja = tengah malam UTC
  public static function startUtc(string $businessDate): \Illuminate\Support\Carbon
  public static function endUtc(string $businessDate): \Illuminate\Support\Carbon      // eksklusif
  /** @return array{0: Carbon, 1: Carbon} */ public static function range(?string $businessDate = null): array
  public static function scopeDay($query, string $column, string $businessDate)        // where col >= start and col < end (bind 'Y-m-d H:i:s')
}

namespace App\Services\Tenants;
final class NumberingLock {
  public static function ensure(string $businessDate): void    // insertOrIgnore numbering_locks (autocommit); panggil SEBELUM transaksi
  public static function acquire(string $businessDate): void   // select ... for update di dalam transaksi; membuat baris bila belum ada
}
final class DailyNumber {
  public static function next(string $businessDate): int       // pemanggil memegang acquire(); max(sellings.daily_order_number di hari [date, fallback created_at], open_bills.number di hari)+1; membaca open_bills dilindungi try/catch + cache hasTable
}
// CustomerNameService::generateDailyCustomerName(?string $name, ?string $businessDate = null, ?int $excludeSellingId = null, ?int $excludeBillId = null): ?string
//   tiga argumen pertama kompatibel dengan pemanggil lama; $businessDate kini 'Y-m-d' hari bisnis (default BusinessDay::today()).
// SellingService::create(array $data, array $hooks = []): Selling
//   $hooks = ['before' => ?Closure(): void, 'after' => ?Closure(Selling): void, 'keep_customer_name' => bool]
//   $data['date'] ??= now()->format('Y-m-d H:i:s') DI SINI (satu momen untuk hari, nomor, dan kolom date; tidak ada celah tengah malam antara hitungan dan observer);
//   hari = BusinessDay::dateOf($data['date']); urutan: ensure -> beginTransaction -> acquire -> before -> create -> SellingCreated -> RecalculateEvent -> after -> commit.
//   LockTimeout/Deadlock: tidak ada lagi Cache::lock; deadlock 1213 diulang 1x.
// Selling: properti transien public bool $numberingLocked = false, public bool $keepCustomerName = false (bukan kolom; tidak ikut fill).
// VoucherService::applyable: $today = Carbon::parse(BusinessDay::today()).
// SellingObserver::creating: $count = Selling::query()->count().
```

### 7.2 Model, enum, total (paket `data-layer`)

```php
namespace App\Enums; enum OpenBillStatus: string { case Open = 'open'; case Paid = 'paid'; case Void = 'void'; }

namespace App\Models\Tenants;
class OpenBill extends Model {       // guarded ['id']; casts: status=OpenBillStatus, business_date='date:Y-m-d', discount_price float, pending_cancel/last_kitchen_batch array, paid_at/voided_at/kitchen_sent_at datetime
  public function items(): HasMany;  // OpenBillItem
  public function table(): BelongsTo; member(); creator() (created_by); payer(); voider(); selling();
  public function scopeOpen($q);     // status = open
  public function label(): string;   // customer_name ?: "Meja {table.number}" ?: "Bill #" . str_pad(number,3,'0')
  public function formattedNumber(): string; // "Order #012"
}
class OpenBillItem extends Model {   // guarded ['id']; use HasOrderLine; bill(): BelongsTo; product(); priceUnit(); casts qty/price/discount_price/sent_qty float
  // atribut yang sama dengan CartItem: product_id, qty, price, discount_price, price_unit_id, note
}
// CartItem: TIDAK berubah selain 'note' (sudah).  PaymentMethod::kind(): 'cash'|'exact'|'credit'.

namespace App\Services\Tenants;
final class OrderTotals {
  public static function lineTotal($line): float        // price_unit ? priceUnit.selling_price*qty : (price ?? 0)
  public static function unitPrice($line): float        // qty > 0 ? round(lineTotal/qty, 2) : lineTotal
  /** @return array{subtotal:float,line_discount:float,tax_price:float,header_discount:float,amount_due:float,qty:float,count:int} */
  public static function compute(\Illuminate\Support\Collection $lines, float $taxPercent, float $headerDiscount = 0.0): array
}
// OrderLineSorter::sort/group menerima CartItem|OpenBillItem|SellingDetail (duck-typed; tanpa query).
// CheckProductStock::validate: pesan "Stok {nama} tidak cukup (tersisa {n})" (lang key 'Stock of :name is not enough (left :left)').
```

### 7.3 `OpenBillService` dan presenter (paket `open-bill-backend`)

```php
namespace App\Exceptions\OpenBill;
class BillConflictException extends \DomainException {}   // versi berubah
class BillClosedException extends \DomainException {}     // bukan open (paid/void)
class BillEmptyException extends \DomainException {}      // tanpa item

namespace App\Services\Tenants;
final class OpenBillService {
  public function createFromCart(\App\Models\Tenants\User $by, array $header): OpenBill
  //   $header: customer_name?, table_id?, member_id?, note?, discount_price?; ensure -> tx -> acquire -> number=DailyNumber::next(today) -> nama final (CustomerNameService) -> create -> pindahkan CartItem::cashier() (user $by; gabung product_id ganda) ke open_bill_items -> hapus keranjang pribadi. Kosong -> BillEmptyException. Ulang 1x pada 23000.
  public function find(int $id): OpenBill                    // dengan items.product.category, items.priceUnit, table, member
  public function mutate(int $billId, \Closure $fn): mixed   // tx + lockForUpdate + status open (else BillClosedException) + fn(OpenBill) + version++ ; deadlock 1213 diulang 1x
  public function updateHeader(OpenBill $bill, array $header, int $expectedVersion): OpenBill   // BillConflictException bila beda; nama kembar dihitung ulang (kecuali bill ini)
  public function registerCancellation(OpenBill $bill, OpenBillItem $item, float $newQty): void // dipanggil di dalam mutate: bila sent_qty > newQty -> catat pending_cancel {product_id,name,qty,note}, sent_qty=min(sent,newQty)
  /** @return ?array batch (null bila tidak ada selisih): {batch:int, is_addition:bool, items:[{item_id,product_id,qty,note,is_addition}], cancelled:[...], changed:[{...,was}]} */
  public function kitchenBatch(OpenBill $bill, int $expectedVersion): ?array
  public function void(OpenBill $bill, \App\Models\Tenants\User $by, ?string $reason, int $expectedVersion): ?array // kembalikan batch BATAL bila ada kiriman
  public function pay(OpenBill $bill, array $request, int $expectedVersion, \App\Models\Tenants\User $by): \App\Models\Tenants\Selling
  //   $request = kunci yang sama dengan Cashier::proceedThePayment (payment_method_id, payed_money, money_changes?, member_id?, due_date?, tax?, discount_price?, voucher?, friend_price=false, total_price). Pre-cek di luar transaksi (status, versi, item, laci kas) lalu SellingService::create($data, hooks) -- TANPA transaksi luar.
  public function totals(OpenBill $bill): array             // OrderTotals::compute(items, tax, discount_price) + 'amount_due'
  /** @return \Illuminate\Support\Collection<int,array> */ public function listOpen(?int $createdBy = null): \Illuminate\Support\Collection
  public function printPayload(OpenBill $bill, string $mode, ?array $batch = null, bool $reprint = false): array   // 'bill'|'kitchen'
}
final class OpenBillPresenter { public static function payload(OpenBill $bill, string $mode, ?array $batch = null, bool $reprint = false): array; public static function row(OpenBill $bill, array $totals): array; }
```
Izin diperiksa di lapisan Livewire (`can('create selling')`; void bill terkirim `can('delete selling')`; void bill belum terkirim: `created_by == user` atau `can('update selling')`).

### 7.4 Komponen `Cashier` (paket `cashier-livewire`; tanda tangan lama TETAP)

Tambahan pada `App\Filament\Tenant\Pages\Cashier` (sisa kontrak plan.md 11.3 tetap):
```php
#[Locked] public ?int $openBillId = null;        // konteks aktif; null = keranjang pribadi
#[Locked] public ?int $openBillVersion = null;
public ?array $openBill = null;                   // ringkasan view: id, number, label, version, sent(bool), batches(int), stale(bool), items_count
public string $paymentToken = '';
public bool $billsEnabled = true; public bool $kitchenEnabled = false;   // Setting open_bill_enabled; About business_type == 'fnb'
// mount(): isi billsEnabled/kitchenEnabled; $this->openBillId tetap null (konteks bill TIDAK dipulihkan setelah reload; keranjang pribadi dan bill bisa dibuka lagi dari daftar).
public function openBill(int $billId): void           // authorize; load header ke cartDetail; konteks bill; refreshCart; dispatch 'open-bill-changed'
public function leaveBill(): void                     // kembali ke keranjang pribadi (restoreDraft)
public function saveBill(): void                      // pribadi -> OpenBillService::createFromCart (konteks pindah ke bill baru; notifikasi 'Bill saved'); konteks bill -> flush header
public function sendToKitchen(): void                 // saveBill bila perlu -> kitchenBatch -> dispatch 'bill-print' (mode kitchen); batch null -> notifikasi 'Nothing new to send'
public function printProvisional(): void              // saveBill bila perlu -> dispatch 'bill-print' (mode bill)
public function reprintKitchen(bool $all = false): void
public function voidBill(?string $reason = null): void
#[On('open-bill-selected')] public function onOpenBillSelected(int $id): void
#[On('open-bill-pay')] public function onOpenBillPay(int $id): void   // openBill + syncCartForPayment + dispatch open-modal proceed-the-payment
public function syncCartForPayment(): void           // + paymentToken = Str::uuid(); konteks bill: muat ulang baris + versi
public function syncBill(): void                      // dipanggil wire:poll.20s.visible di konteks bill; ubah state hanya bila versi DB berbeda
public function proceedThePayment(SellingService $sellingService): void   // TETAP; konteks bill -> OpenBillService::pay; anti-ganda paymentToken
```
Perilaku tambahan: `commitCustomerName/selectTable/saveCustomTable/storeCart` pada konteks bill memanggil `updateHeader` (nama final tampil; `customerNameHint` null); `BillConflictException` -> notifikasi + event browser `bill-conflict` (UI menawarkan Muat ulang / Simpan sebagai bill baru). `calculateTotalPrice()` memakai `OrderTotals` + `cartDetail['discount_price']` (bukan `private float $discount_price`). Voucher tidak disimpan di bill. Voucher memakai `BusinessDay::today()`. Pengecekan laci kas sebelum bayar: setting `cash_drawer_enabled` dan tidak ada `CashDrawer::lastOpened()` -> notifikasi `Cash drawer is not opened` (key lang) dan berhenti (tidak crash 500).

`CartInteraction` (aditif): `protected function activeBillId(): ?int { return null; }`, `lineModel(): string`, `lineQuery(): Builder`, `withLineLock(Closure)` (konteks bill -> `OpenBillService::mutate`); `addCart/reduceCart/deleteCart/addDiscountPricePerItem/restoreCartItem` memakai `lineQuery()`; pengurangan/penghapusan baris terkirim -> `registerCancellation`; penghapusan baris terkirim hanya lewat konfirmasi klien (parameter `bool $confirmed = false` pada `deleteCart`; tanpa Undo untuk baris terkirim: event `cart-item-removed` membawa `undoable: false`). `POS` dan `Pages/CartItem` tidak berubah (default keranjang pribadi).

`PriceSetting` (`public Model $cartItem`; semua method memanggil `authorizeLine()`), `CartItemNote` (`#[Locked] string $context`, `#[Locked] ?int $billId`; `save()` memakai konteks).

### 7.5 Komponen `OpenBills` (paket `open-bill-backend`; tag `<livewire:cashier.open-bills />`; view `livewire.cashier.open-bills`)

```php
public bool $open = false; public string $filter = 'all';   // all|mine|stale
public function mount(): void                               // authorize; hitung jumlah (1 query ber-indeks)
public function setOpen(bool $open): void                   // daftar hanya dimuat saat $open
public function setFilter(string $filter): void
#[On('open-bills-changed')] public function refresh(): void
public function select(int $billId): void                   // dispatch('open-bill-selected', id: $billId)->to(Cashier::class); setOpen(false)
public function pay(int $billId): void                      // dispatch('open-bill-pay', id: $billId)->to(Cashier::class); setOpen(false)
public function printProvisional(int $billId): void         // dispatch browser 'bill-print' (mode bill)
public function reprintKitchen(int $billId): void           // mode kitchen, semua item, reprint
public function previewBill(int $billId, string $mode): void // dispatch 'bill-preview' {mode, bill}
public function voidBill(int $billId, ?string $reason, int $expectedVersion): void
public function render()                                    // view('livewire.cashier.open-bills', ['bills' => rows, 'count' => int, 'today' => string])
```
Setiap method: `abort_unless(can('create selling'), 403)`; wire:poll 20 dtk dan `.visible` pada wadah.

### 7.6 Event dan JS

Browser (dari server): `bill-print {mode:'bill'|'kitchen', bill:object}`, `bill-preview {mode, bill}`, `open-bill-changed {id:int|null, number:int|null, version:int|null}`, `bill-conflict {id}`, plus event plan.md 11.3 (`cart-qty-updated`, `cart-item-touched`, `cart-item-removed` (+`undoable`), `order-reset`, `selling-created`, ...). Livewire antar komponen: `open-bill-selected {id}`, `open-bill-pay {id}`, `open-bills-changed`, `product-scanned`, `products-refresh`.

Pendengar di `bootstrap.blade.php`: `window.addEventListener('bill-print', e => window.LakasirReceipt.print(e.detail.bill, {mode: e.detail.mode, reprint: e.detail.reprint}))`; `bill-preview` membuka modal `receipt-preview-modal` yang mengisi `<pre>` dari `LakasirReceipt.preview(...)`.

`window.LakasirReceipt` (partial, `version: 2`; semua method v1 TETAP):
```js
print(payload, opts?: {buttonId?:string, reprint?:boolean, mode?:'receipt'|'bill'|'kitchen', showPrices?:boolean}): Promise<boolean>
//   kunci anti-ganda per `${mode}:${payload.id ?? payload.daily_order_number}` (3 dtk); tetap menghormati window._lakasirIsPrintingNow
build(printer, payload, opts?: {mode?, reprint?, printerData?, showPrices?}): Printer     // murni; mode bawaan 'receipt'
preview(payload, opts?): string                                                          // teks per baris untuk 32/48 kolom via TextPrinter; sama persis dengan cetakan
groupDetails, htmlToPlain, toPrintable, wrapText, itemName                                // tetap
```
Aturan `build`: header/meta/items/totals/footer per tabel (D); tabel item dua baris (`qty x satuan` kiri, total kanan, ditumpuk bila tak muat); `showPrices` dapur = `opts.showPrices ?? cfg.kitchenShowPrices`; kitchen memakai `size(0,1)` + `style('bold')` untuk baris `qty x nama`; bagian `BATAL:` dan `UBAH:`; semua string dinamis lewat `toPrintable`; tetap tanpa `innerHTML`. `LakasirReceiptConfig` tambahan: `kitchenShowPrices: bool` (`Setting::get('kitchen_ticket_show_price', false)`), dan label: `bill, provisional, notPaid, kitchen, newOrder, addition, cancelled, changed, billNo, itemDiscount, copy`. Toggle `kitchen_ticket_show_price` ditambah di `Pages/Printer.php` (Toggle + `Setting::set`).

### 7.7 Terjemahan baru (satu pemilik: `cashier-shell`; `lang/id.json` dan `lang/en.json`; en = kunci)

> **[DIGANTIKAN/DIREVISI v3.1]** Diganti bagian 15 (kosakata Bill/Bill sementara; kunci baru).

Bill: `Open bills`=Bill terbuka; `Save bill`=Simpan bill; `Send to kitchen`=Kirim ke dapur; `Provisional bill`=Bon sementara; `Bill saved`=Bill tersimpan; `Bill #:number`=Bill #:number; `New order`=Pesanan baru; `Unsaved order`=Pesanan belum disimpan; `Open this bill`=Buka bill ini; `Close bill`=Tutup bill; `Void bill`=Batalkan bill; `Void reason`=Alasan pembatalan; `Bill voided`=Bill dibatalkan; `Bill already paid`=Bill sudah dibayar; `Bill is closed`=Bill sudah ditutup; `Bill was changed on another device`=Bill diubah di perangkat lain; `Reload bill`=Muat ulang bill; `Save as new bill`=Simpan sebagai bill baru; `Nothing new to send`=Tidak ada item baru untuk dikirim; `Sent to kitchen`=Terkirim ke dapur; `Not sent`=Belum dikirim; `Reprint kitchen ticket`=Cetak ulang tiket dapur; `From yesterday`=Dari kemarin; `All bills`=Semua bill; `My bills`=Bill saya; `No open bills`=Tidak ada bill terbuka; `Table already has an open bill`=Meja ini sudah punya bill terbuka; `Open that bill`=Buka bill itu; `Age`=Usia; `:minutes min`=:minutes mnt; `Remove item already sent to kitchen?`=Item sudah dikirim ke dapur. Hapus dan beri tahu dapur?; `Print preview`=Pratinjau cetak; `Add the bill first`=Tambahkan item dulu; `Only the owner can void a bill sent to the kitchen`=Hanya pemilik yang dapat membatalkan bill yang sudah dikirim ke dapur; `Stock of :name is not enough (left :left)`=Stok :name tidak cukup (tersisa :left).
Cetak: `NOT PAID`=BELUM LUNAS; `PROVISIONAL BILL`=BON SEMENTARA; `KITCHEN`=DAPUR; `NEW ORDER`=PESANAN BARU; `ADDITION #:n`=TAMBAHAN #:n; `CANCELLED`=BATAL; `CHANGED`=UBAH; `Thank you`=Terima kasih.
Bayar: `Payment`=Pembayaran; `Amount due`=Total tagihan; `Cash received`=Uang diterima; `Exact amount`=Uang pas (kunci `Uang Pas` lama tetap); `Short by`=Kurang; `Confirm payment received`=Konfirmasi pembayaran diterima; `Due date`=Jatuh tempo; `Down payment`=Uang muka; `Receivable remaining`=Sisa piutang; `Member is required for credit`=Member wajib untuk kredit; `Enter the received amount`=Masukkan uang yang diterima; `Payment failed`=Pembayaran gagal; `Cash drawer is not opened`=Laci kas belum dibuka; `Order items`=Rincian pesanan; `Show items`=Lihat rincian; `Hide items`=Sembunyikan rincian; `Exact payment`=Pembayaran pas; `Clear`=Hapus.
Test `LangKeysTest` memindai view dan kelas PHP baru (`__('...')`).

### 7.8 Pemetaan selector uji tambahan

`bills-toggle` (tombol + lencana jumlah `bills-count`), `bills-drawer`, `bills-filter-{all|mine|stale}`, `bill-row-{id}`, `bill-open-{id}`, `bill-pay-{id}`, `bill-print-{id}`, `bill-void-{id}`, `bill-conflict`, `save-bill`, `send-kitchen`, `provisional-bill`, `new-order-chip`, `bill-chip`, `pay-method-{id}`, `pay-numpad-{key}`, `pay-exact`, `pay-received`, `pay-change`, `pay-error`, `pay-submit`, `pay-items-toggle`, `receipt-preview-modal`, `receipt-preview-text`, `cart-item-unit-{id}`, `cart-item-total-{id}`.

---

## 8. Yang DIPERTAHANKAN vs DIKERJAKAN ULANG dari pekerjaan gelombang-1 yang belum ter-commit

> **[DIGANTIKAN/DIREVISI v3.1]** Diganti 16.1.

(salvage.json sudah ada; bagian ini mengikutinya)

| Berkas | Status salvage | Keputusan v3 |
|---|---|---|
| `Concerns/HasOrderLine.php`, `PlainText.php`, `OrderLineSorter.php` | complete | **DIPERTAHANKAN** (sorter diperluas duck-typing ke `OpenBillItem`). Belum ada tes (ditulis `tests-core`). |
| `CashierDraftService.php` | complete | **DIPERTAHANKAN**, perannya dipersempit: hanya draft pesanan baru yang belum disimpan (nama, meja, catatan, member) per akun; begitu disimpan sebagai bill, state milik bill. |
| `CartItem.php`, `SellingDetail.php`, `Product.php` (escapeLike, searchInPos, inCategory, isStockUnlimited) | complete | **DIPERTAHANKAN** (tanpa perubahan lanjut kecuali `PaymentMethod::kind`). |
| `Selling.php` (`loadForReceipt`, `notePlain`) | complete | **DIPERTAHANKAN**; tambahan: `creating` memakai BusinessDay/NumberingLock/DailyNumber, properti transien (paket `numbering`). |
| `SellingService.php` | partial (Cache::lock) | **DIKERJAKAN ULANG**: buang `Cache::lock`, tambah kunci baris hari + hook + flag (7.1). |
| `TransactionSellingStoreRequest`, `SellingDetailCollection`, `SellingController`, `SellingDetailsRelationManager` | complete | **DIPERTAHANKAN** apa adanya. |
| `ViewSelling.php`, `view-selling.blade.php` | partial | **DIPERTAHANKAN**; verifikasi browser di `verify`. |
| `receipt-print.blade.php` | partial (v1 belum dijalankan; asumsi `price` = total baris DIVERIFIKASI benar terhadap `AssignProduct`) | **DIPERTAHANKAN sebagai dasar, DIPERLUAS** ke v2 (mode, tata letak dua baris, `preview`, `TextPrinter`). |
| `ProductBrowser.php`, `CartItemNote.php` | partial (view belum ada) | **DIPERTAHANKAN**; `CartItemNote` diperluas (`context`, `billId`). |
| `CartInteraction.php` | partial | **DIPERTAHANKAN dan DIPERLUAS** (abstraksi baris, kunci bill, pembatalan terkirim). |
| `Cashier.php` | broken (view belum dikonversi) | **DIPERTAHANKAN logikanya** (restoreDraft, commitCustomerName, syncCartForPayment, restoreCartItem, resetOrder), **DIPERLUAS** konteks bill/pembayaran. View dibangun ulang di gelombang 3. |
| `TableProduct.php` (dihapus) | broken | tetap dihapus. |
| `cashier.blade.php` dan view anak/bootstrap/lang/tests | not started | **DIKERJAKAN** gelombang 3-4. |
| Migrasi `2026_10_15_000001` (note) | complete | **DIPERTAHANKAN**. |
| Migrasi `2026_10_15_000002` (indeks products) | partial | **DIHAPUS** (6.1). Berkas `000002` diganti `create_open_bills_table`. |
| Migrasi `2026_10_14_000001` (ter-commit) | partial | **DITULIS ULANG di tempat** (6.1). |

---

## 9. PAKET KERJA FINAL (menggantikan plan.md 11.5): 5 gelombang, `owner_files` saling lepas

> **[DIGANTIKAN/DIREVISI v3.1]** Diganti bagian 16 (16 paket).

Aturan umum: setiap paket mengedit HANYA berkas yang dimilikinya; kontrak 7 adalah satu-satunya antarmuka; migrasi hanya DITULIS (tidak dijalankan ke DB mana pun; `verify` yang menjalankan). Jangan menjalankan pest bersamaan dengan paket lain yang memakai `database/testing.sqlite`.

| Gelombang | Paket |
|---|---|
| 1 (paralel) | `data-layer`, `numbering`, `receipt-print`, `design` |
| 2 (paralel; setelah 1) | `open-bill-backend`, `cashier-livewire`, `tests-core` |
| 3 (paralel; setelah 2) | `product-view`, `cashier-shell`, `open-bill-view`, `payment-view`, `tests-bills` |
| 4 | `tests-cashier` |
| 5 | `verify`, lalu code-reviewer, test-runner, uiux-analyst, debugger sesuai alur tim |

Rincian di keluaran terstruktur (owner_files + tasks). Pemisahan berkas view gelombang 3: `cashier-shell` memiliki `cashier.blade.php` dan berkas LANGSUNG di `pages/cashier/` (detail, items, total, bootstrap, cart-panel, cart-item, order-header, actions) serta `livewire/cashier/cart-item-note.blade.php`, `livewire/price-setting.blade.php`, `tailwind.config.js`, `lang/*.json`; `payment-view` memiliki `pages/cashier/payment/**`; `open-bill-view` memiliki `pages/cashier/bills/**` dan `livewire/cashier/open-bills.blade.php`; `product-view` memiliki `livewire/cashier/product-browser.blade.php` dan `livewire/cashier/partials/**`. Titik sambung (kontrak): `cashier-shell` menyertakan `@include('filament.tenant.pages.cashier.payment.modal')`, `@include('filament.tenant.pages.cashier.payment.success-modal')`, `@include('filament.tenant.pages.cashier.bills.preview-modal')` dan `<livewire:cashier.open-bills />`.

---

## 10. Kriteria penerimaan tambahan

> **[DIGANTIKAN/DIREVISI v3.1]** Diganti bagian 17.

1. **Harga per item:** pratinjau teks (`LakasirReceipt.preview`) pada 58 dan 80 mm untuk mode `receipt` dan `bill` memuat `qty x harga satuan` dan total baris untuk setiap baris, diskon baris, catatan item; mode `kitchen` TIDAK memuat harga bila setting mati dan memuatnya bila menyala. Keranjang, modal bayar, dan daftar bill memuat `cart-item-unit-*`/`cart-item-total-*` dengan angka yang sama dengan cetakan.
2. **Open Bill:** simpan -> dapur (batch 1 semua item) -> tambah item -> dapur (batch 2 hanya tambahan) -> kurangi item terkirim (batch 3 memuat BATAL) -> ubah catatan item terkirim (UBAH) -> bon sementara (tanpa baris bayar) -> bayar: `sellings` bertambah 1, stok berkurang tepat sekali, bill `paid` dengan `selling_id`, nomor order = nomor bill, nama sama; bill belum dibayar tidak muncul di `DashboardController`, `CashierReportService`, `ProductReportService`, `SellingReportService`, API `GET /transaction/selling`, dan tidak mengubah stok.
3. **Konkurensi:** dua sesi, sesi A menyimpan kirim dengan versi lama -> `BillConflictException` tanpa efek samping; bayar dua kali (token sama dan bill sama) -> satu penjualan; void lalu bayar -> `BillClosedException`.
4. **Pembayaran:** kategori tiap jenis (cash/exact/credit) lulus; kredit tanpa member ditolak dengan pesan; stok kurang saat bayar bill ditolak dengan nama produk dan bill tetap `open` utuh; laci kas belum dibuka -> pesan, bukan 500; metode terakhir diingat; tombol bayar tidak aktif sebelum cukup uang.
5. **Penomoran:** semua skenario E6; dua pembuatan berurutan tidak pernah menghasilkan nomor kembar; `UNIQUE (business_date, number)` terpasang.
6. **Migrasi:** `php artisan migrate --pretend` (koneksi `mysql`) hanya memuat `create table`, `alter table add column`, `create index`; semua nama indeks <= 64; `migrate` dua kali berturut-turut tidak error (idempoten); `migrate:fresh` pada SQLite tes lulus; perf migration `down()` tidak melempar pada MySQL (diuji lewat SQL pretend: indeks FK pengganti dibuat sebelum drop).
7. **Tanpa regresi:** semua kriteria penerimaan plan.md 6 dan 11.6 yang tidak dibatalkan di sini; konsol bersih; `POS` dan `Pages/CartItem` tetap memuat.

---

## 11. Risiko untuk devils-advocate

> **[DIGANTIKAN/DIREVISI v3.1]** Risiko yang tersisa setelah revisi: 13.4. R1/R10 (cache hasTable, kunci) sudah dimitigasi oleh kunci tunggal dan cache positif-saja.

R1 Kolom/kunci: kunci baris hari bergantung pada urutan "kunci dulu, baca kemudian"; kesalahan urutan memunculkan nomor kembar hanya pada MySQL `REPEATABLE READ` (tidak tampak di SQLite). Mitigasi: aturan E2, komentar kode, tes statis posisi `acquire`, uji manual dua proses di `verify` bila MySQL tersedia. R2 Tidak ada konstrain DB untuk nomor penjualan (hanya bill). R3 Stok tidak dipesan: dapur bisa memasak item yang stoknya habis; bayar ditolak. R4 Kompleksitas kontrak konteks bill pada `CartInteraction` yang juga dipakai `POS`/`Pages/CartItem` (default keranjang pribadi; tes regresi). R5 `printer` per perangkat: tiket dapur dicetak di printer perangkat pemicu; batch ditandai terkirim di server walau cetak gagal (cetak ulang tersedia). R6 `daily_order_number` bill dipakai sebagai nomor order; bill lintas tengah malam mendapat nomor baru. R7 Hari pertama pasca-deploy (E5). R8 Migrasi perf ditulis ulang: harus tetap memuat nama indeks yang sama. R9 `CACHE_DRIVER=file` masih dipakai draft/Setting (tidak lagi untuk kunci). R10 `hasTable('open_bills')` dicache statis di `DailyNumber` (bila migrasi dijalankan saat proses hidup, restart PHP-FPM/`optimize:clear`). R11 Satu paket (`open-bill-backend`) memuat logika terpenting (bayar atomik); wajib review kode ganda. R12 Kolom `price` baris satuan-harga mencetak per satuan dasar (batasan lama). R13 Daftar bill shop-wide menampilkan nama pelanggan ke semua kasir (diterima, F&B).

## 12. Asumsi dan default

Zona bisnis `Asia/Jakarta`; izin yang dipakai ulang; kill-switch `open_bill_enabled`; tiket dapur tanpa harga; satu printer per perangkat; bill lama tetap terbuka; tidak ada pembayaran terpisah; tidak ada endpoint API bill; tidak ada layar admin riwayat bill (bill `paid`/`void` terbaca lewat tinker/laporan lanjutan); tidak ada pruning (baris item bill kecil); `Selling.is_paid` tidak dipakai.


---

# BAGIAN REVISI (plan v3.1): bagian 13-19 di bawah ini MENGGANTIKAN bagian 2-10 bila bertentangan

Urutan prioritas dokumen: **bagian 14 (KONTRAK FINAL v3) > bagian 13-19 > bagian 0-12 plan-v3 (tanda "DIGANTIKAN") > plan.md 11.3 (kontrak v2)**. Berkas padanan mesin: `final_contract_v3.txt` dan `final_packages_v3.json` (isinya sama dengan bagian 14 dan 16).
Istilah: di bagian 0-12 kata "bon sementara" dibaca **"Bill sementara"** dan "BELUM LUNAS" dibaca **"BELUM DIBAYAR"** (keputusan 13.1 #K11). Benda disebut **Bill**, kata "Bon" tidak dipakai karena di warung "bon" berarti hutang dan aplikasi ini punya Kredit/piutang.

## 13. Revision after challenge

Tiga devils-advocate (DA = produksi/MySQL/konkurensi, PAY = pembayaran/stok/uang, KUX = dapur/UX) memberi verdict *approve_with_changes*. Semua poin critical dan important dinilai terhadap kode; hampir semuanya valid. Fakta kode yang saya periksa ulang untuk revisi ini:

- `HEAD:SellingService::create` TIDAK punya `Cache::lock` (itu tambahan wave-1 yang belum ter-commit). Jadi "kunci penomoran tidak ada" = perilaku produksi saat ini, dan itu adalah jalur turun-ke-bawah (degrade) yang aman bila tabel kunci belum ada.
- `Selling::$guarded = ['friend_price']`; kolom `sellings.voucher` (kode) ada; `SellingService::mapProductRequest` memanggil `Voucher::reduceUsed()` dengan model basi (lost update) sebelum `create()`.
- `Cashier::proceedThePayment` (HEAD dan working tree, baris ~669): `wallet || debit || !is_cash` memaksa `payed_money = total_price`. Metode kredit biasa (`is_credit=1`, `is_cash=0`) ikut ditimpa, sehingga uang muka hilang dan piutang ~0. Terkonfirmasi (PAY-C2).
- `ReceivableService::create` memakai `selling.total_price` (sebelum diskon, sesudah pajak) minus `payed_money`; angka itu bisa lebih besar dari tagihan yang dilihat pelanggan bila ada diskon.
- `AssignProduct` tidak pernah menolak stok kurang; `StockService::reduceStock` jatuh ke cabang `$product->stock - $qty` (negatif). `CheckProductStock` hanya validator di luar transaksi.
- `SellingObserver::creating`: `CashDrawer::lastOpened()->first()->id` (null dereference bila laci tertutup) dan `Selling::all()->count()`.
- `database/migrations/2026_10_14_000001` (ter-commit 2026-10-15 12:21) bertanggal SETELAH deploy Randy ("kemarin" = 2026-10-14), jadi hampir pasti BELUM ada di produksi; commit T1 `0457dfd` (2026-10-14 17:02) meratakan folder migrasi tenant. Commit mana yang berjalan di produksi tidak diketahui.
- Laravel 11.51 memiliki `Schema::hasIndex` dan `Schema::getForeignKeys`. Pada MySQL, `unique()`, `index()` dan `foreign()` dikompilasi sebagai pernyataan `ALTER TABLE` TERPISAH sesudah `CREATE TABLE` (DDL tidak transaksional), sehingga guard `hasTable` saja menyembunyikan keadaan setengah jadi (tabel ada, FK/indeks belum). Ini temuan saya sendiri yang diperlukan untuk idempotensi sungguhan (13.3 #M-A).

### 13.1 DITERIMA (dan apa yang berubah)

| ID | Poin | Perubahan pada rencana |
|---|---|---|
| DA-C1 | Deploy sebagian mematikan SEMUA penjualan termasuk API | (1) `NumberingLock::ensure/acquire` menjadi no-op + `Log::warning` bila `Schema::hasTable('numbering_locks')` false (hanya hasil POSITIF yang di-cache per proses; perilaku turun-ke-bawah = perilaku produksi sekarang). (2) `AssignProduct` membuang kunci `note` bila kosong sehingga penjualan tanpa catatan tidak pernah menyentuh kolom `selling_details.note`. (3) `billsVisible` false bila tabel `open_bills` belum ada (UI bill tidak muncul, tidak 500). (4) Gerbang deploy: perintah baru `php artisan pos:preflight` (14.9) dan mode `--pre`. (5) `php artisan down` WAJIB selama jendela deploy sehingga tidak ada jalur kode-baru-skema-lama. |
| DA-C2 | `migrate --force` tanpa batas; commit produksi tidak diketahui; tiga migrasi lama merusak | Langkah rilis diganti (bagian 18): `pos:preflight --pre` adalah gerbang keras: SEMUA migrasi selain tujuh berkas yang diizinkan (`2026_10_14_000001` dan `2026_10_15_000001`..`000006`) harus berstatus Ran; bila ada Pending lain (mis. `2025_10_03_042817_remove_barcode...`, `2024_06_04_082221_drop_expired_columns...`, `2024_04_25_231131_create_abouts...`) perintah gagal (exit 1) dan Randy BERHENTI. Migrasi baru dijalankan SATU PER SATU dengan `--path`. `--pretend` dijelaskan sebagai daftar kasus-terburuk, bukan bukti. Randy ditanya hash commit + isi tabel `migrations` (bagian 19, fakta F1-F2). |
| DA-I1 | Kunci per hari lebih sempit daripada kunci global lama | Kunci penomoran menjadi SATU baris konstan `numbering_locks.name='sales'` untuk SETIAP penjualan (juga membuat stok read-modify-write dan `count()+1` kode `SELL` terserialisasi). Skema `numbering_locks` berubah dari `business_date` PK menjadi `name varchar(32)` PK. Tidak ada manfaat throughput per-hari untuk satu toko, jadi tidak ada yang hilang. |
| DA-I2 / PAY-C1 | Cek stok saat bayar tidak otoritatif | Kelas baru `StockGuard::assert()` dipanggil DI DALAM transaksi penjualan, setelah kunci penjualan dan hook `before` (kunci bill), dengan `lockForUpdate` pada baris produk, agregasi per produk, nama produk di pesan, produk terhapus bernama. `SellingService::create(..., ['verify_stock'=>true])` dipakai oleh jalur bill, jalur Livewire langsung, dan API. Kunci validasi API `products.N.qty` TIDAK berubah. Test: dua kali `pay()` berurutan dengan satu unit stok. |
| DA-I3 | Bill dibayar melintasi tengah malam bisa bernomor ganda | `pay()` membuat `$data['date']` SEKALI (UTC `Y-m-d H:i:s`) dan menurunkan "hari yang sama", `keep_customer_name`, nomor, dan kolom `date` dari nilai itu. |
| DA-I4 | `$data['date']` tidak dinormalisasi | `BusinessDay::normalize()` (14.1) dipakai `SellingService::create`: ISO/offset/Z diubah ke UTC, string tanpa zona = UTC, tanggal-saja = tengah malam UTC, tak terbaca = `now()`. Nilai ternormalisasi dipakai untuk kunci, nomor, nama, dan kolom. |
| DA-I5 / PAY-I8 | ALTER MySQL: metadata lock, disk, error 1799 | Helper `SafeMigration` (14.9): `SET SESSION lock_wait_timeout=10` + coba ulang 3x pada error 1205/1213 untuk SETIAP ALTER pada tabel yang sudah berisi data (`000001`, `000006`, migrasi perf). Daftar Randy: `SHOW FULL PROCESSLIST`, `information_schema.innodb_trx`, ruang disk vs `data_length`, versi, `@@system_time_zone`, `@@global.time_zone`, `@@transaction_isolation`, `@@innodb_lock_wait_timeout`. Dilarang `ALGORITHM=INSTANT` mentah. |
| DA-I6 | `ensure()` (INSERT IGNORE) memegang S-lock dan menunggu | `ensure()` = `SELECT` biasa (autocommit, tanpa kunci); `insertOrIgnore` hanya bila baris belum ada; hasil positif di-cache per proses. Retry untuk 1213 DAN 1205; bila tetap gagal `SaleBusyException` (pesan terbaca; API 503). |
| DA-I7 / PAY-I1 | Urutan kunci `updateHeader`/rename | Aturan global "urutan kunci" (14.0 R1): (1) kunci penjualan, (2) baris `open_bills`, (3) `open_bill_items`, (4) baris keranjang kasir, (5) baris produk. `updateHeader` yang mengubah NAMA dan `createFromCart` mengambil kunci penjualan DULU, baru baris bill. Edit baris biasa hanya (2)(3) dan tidak pernah meminta (1) sesudahnya. Test statis (grep kode) bahwa tidak ada jalur yang mengunci `open_bills` sebelum memanggil `NumberingLock::acquire`. |
| DA-I8 | 23:55-00:05 butuh keputusan Randy | Perilaku bawaan tetap hari pembayaran (omzet, stok, laci) dengan nomor baru; tampilan: daftar bill dan tiket cetak ulang menampilkan label tanggal (`KEMARIN`/`d/m`) di samping nomor; struk bill lintas hari mencetak jam/tanggal bill dibuka. Pertanyaan ke Randy (B1). |
| DA-I9 | Nomor hari deploy | Ditulis di `docs/deploy-open-bill.md` + pertanyaan fakta F5 (apakah toko beroperasi 00:00-07:00 WIB atau sinkronisasi offline). |
| DA-I10 / KUX-I10 | Rollback bill belum dibayar; kill switch menyembunyikan bill | Doc rollback: sebelum rollback daftar bill terbuka (SQL baca-saja di dokumen), bayar/batalkan. `open_bill_enabled=false` hanya menyembunyikan PEMBUATAN (Simpan/Kirim); tombol daftar bill + Bayar tetap tampil selama ada bill terbuka (`billsVisible`). |
| DA-I11 / PAY-m1 | Harga bill basi; pajak dibaca saat bayar | `open_bills.tax_percent decimal(6,3)` dibekukan saat bill dibuat; `pay()` mengirim `tax` eksplisit dari bill. Kebijakan harga: baris biasa = harga saat dipesan; menambah qty hanya menghargai SELISIH dengan harga hari ini (`OrderLinePricing`); baris satuan-harga tetap dihitung ulang (batasan lama, didokumentasikan); modal bayar menampilkan petunjuk bila harga katalog sekarang berbeda dari harga bill (bukan pemblokir). |
| DA-I12 | Migrasi perf: hasIndex di `up()`, nama sama | Ditulis ulang: `up()` dan `down()` keduanya idempoten dengan `SafeMigration`; nama indeks tidak berubah; `down()` membuat indeks FK pengganti dulu. |
| DA-M1 | `created_at` fallback = pemindaian penuh | `DailyNumber`: query kedua HANYA bila yang pertama `null`, dan hanya baris `date IS NULL` (memakai indeks `sellings_date_number_index`). |
| DA-M3 / PAY-I7 | Keranjang pribadi dihapus di luar transaksi; balapan jual-ganda | Jalur Livewire langsung memakai hook: `before` mengunci baris keranjang kasir + memverifikasi tanda tangan (id, qty, price, discount, note) yang dibaca saat membangun permintaan, `after` menghapus keranjang DI DALAM transaksi yang sama. `CartChangedException` bila berbeda. |
| DA-M4 | Pembandingan double | `Money::qty()` membulatkan 3 desimal; semua pembanding sent_qty/qty memakai `Money::qtyEq`. |
| DA-M5 | TEXT 64 KB terpotong senyap | `last_kitchen_batch`, `pending_cancel` = `MEDIUMTEXT`; test 150 baris bercatatan panjang. |
| DA-M10 | Tes kebocoran | `OpenBillPayTest` menegaskan bill terbuka tidak masuk laporan/dasbor/API. |
| DA-M11 | mysqldump sebelum status; uji pulih; hitung baris | Bagian 18 langkah 0 dan akhir. |
| PAY-C2 | Uang muka kredit tertimpa | `buildSellingRequest()` bercabang per `PaymentMethod::kind()` dengan KREDIT lebih dulu: `payed_money = clamp(uang muka, 0, amount_due)`, `money_changes = 0`, `member_id` dan `due_date` wajib; aturan server `0 <= payed_money <= amount_due` untuk kredit. `ReceivableService` memakai jumlah SESUDAH diskon (tindakan di 13.3 #M-B, pertanyaan B10). Berlaku juga untuk jalur bill. |
| PAY-C3 | paymentToken menelan percobaan ulang sah | Token hanya pagar UX. Sumber kebenaran = status bill di bawah kunci baris (`paid` + `selling_id`). Urutan: validasi dulu -> `Cache::add("paylock:...")` -> proses -> `finally Cache::forget`. Token berhasil disimpan `paydone:{user}:{token}` = selling_id sehingga percobaan ulang membuka modal sukses yang ada (bukan diam). Token dirotasi setelah setiap kegagalan. Bill `paid` yang dibayar ulang mengembalikan penjualan yang ada (`PayResult.alreadyPaid`). |
| PAY-C4 | Konflik versi buatan sendiri; polling saat bayar | Anak (`CartItemNote`, `PriceSetting`) mengirim `open-bill-changed`; induk memuat ulang baris DAN versi sekaligus, versi DIBACA DULU, baris sesudahnya. Versi di-PIN di `syncCartForPayment`; `syncBill`/`wire:poll` berhenti selama `paymentPinned`. Bila versi berubah saat modal bayar terbuka, bayar ditolak dengan total baru ditampilkan + tombol Muat ulang. |
| PAY-C5 / KUX-C1 / DA-I-print | Tiket dapur bisa hilang diam-diam | **Dua fase**: `kitchenBatch()` murni (tanpa tulis); klien mencetak; sesudah cetak berhasil klien mengirim `kitchen-ack`; `ackKitchenBatch()` menulis `sent_qty`/`sent_note`/`kitchen_batch_count`/`last_kitchen_batch` (idempoten). Cetak gagal = batch tetap belum terkirim, toast permanen "Tiket dapur belum tercetak. Cek printer, lalu Coba lagi" + [Coba lagi] + [Tandai terkirim tanpa cetak]. `LakasirReceipt.print` menjadi ANTREAN serial yang tidak pernah menjatuhkan pekerjaan; kunci anti-ganda per `mode:id:batch`; hasil `false` terlihat. "Tidak ada item baru" menawarkan [Cetak ulang tiket terakhir]. |
| PAY-C6 / KUX-I9 | Void bisa dilewati dengan menghapus baris; tanpa jejak | Satu gerbang `OpenBillGate::canCancelSent` untuk void bill terkirim, pengurangan di bawah `sent_qty`, dan penghapusan baris terkirim (izin `delete selling` atau `update selling`, atau pembuat bill dalam 10 menit sejak kiriman terakhir; evaluasi di dalam `mutate` terhadap baris terkunci). ALASAN wajib untuk semua pembatalan (juga void bill belum terkirim). Tabel append-only baru `open_bill_events` (sama dengan migrasi tambahan satu berkas, tanpa dampak data hidup). `pay` dengan perubahan dapur yang belum dikirim dicatat. |
| PAY-I2 | Voucher terbakar di luar transaksi | `mapProductRequest` tidak lagi memanggil `reduceUsed`; menyimpan id voucher di instance `SellingService`; `create()` mengonsumsi di fase `after` dengan `Voucher::where(id)->where('kuota','>',0)->decrement('kuota')` dan GAGAL (rollback) bila 0 baris. Berlaku untuk API dan Livewire. |
| PAY-I3 | Pay tidak boleh memercayai angka klien | `pay()` hanya menerima `payment_method_id, payed_money, member_id, due_date, voucher`; baris, diskon header, pajak diambil dari baris bill; `friend_price=false` dipaksa (jalur bill DAN Livewire langsung); `tax_percent` dibekukan di bill. Untuk metode `exact` server memakai `amount_due`-nya sendiri. |
| PAY-I4 | Stok kurang untuk barang yang sudah disajikan | (a) Komitmen stok: satu query `SUM(open_bill_items.qty)` bill terbuka per produk ditampilkan sebagai PERINGATAN "tersisa N setelah bill terbuka" di kartu produk dan keranjang. (b) Saat bayar, baris dengan `sent_qty>0` yang stoknya kurang hanya boleh lewat dengan override eksplisit oleh pengguna `update selling`/owner (`OpenBillGate::canOverrideStock`), stok boleh minus dan ditandai di event `stock_override`; selain itu diblokir dengan nama produk. |
| PAY-I5 / DA-M8 | Laci kas | Cek juga di `hooks['before']` (`ValidationException` 'Cash drawer is not opened'); `SellingObserver::creating` null-aman dan melempar `ValidationException` (bukan null dereference). Aturan terdokumentasi: bill masuk laci yang terbuka saat BAYAR dan atas nama pembayar. Peringatan di `CashDrawerController::close` ditolak (13.2). |
| PAY-I6 | Float dan pembulatan | `App\Support\Money::round(x,2)` dipakai `OrderTotals`, `mapProductRequest`, perbandingan `payed_money >= amount_due` (`Money::gte`), dan `money_changes`. Satuan harga tampil `qty x ~unit` (tanda `~`) bila `round(unit*qty) != total`; TOTAL adalah otoritas. Test: pajak 11% dengan 10 subtotal ganjil dan kombinasi 1/3. |
| PAY-I9 | Otorisasi method Livewire | `openBill(id)` hanya untuk status `open`; setiap method memanggil `OpenBillGate::canUse`; aturan void dievaluasi terhadap data terkunci; test 403 untuk setiap method dan "pembuat tidak bisa void bill yang dikirim perangkat lain". |
| PAY-m2 | Respons hilang | `PayResult.alreadyPaid` membuka kembali modal sukses; "Simpan" ulang dengan keranjang kosong menampilkan "Bill mungkin sudah tersimpan; cek daftar bill". |
| PAY-m4 | Metode bayar basi jatuh ke Cash | Jalur bill mengembalikan `ValidationException` bila `payment_method_id` tidak ada; jalur langsung tetap perilaku lama. |
| PAY-m5 | UNIQUE (bill, produk) | Dipertahankan; pesan UI "Produk ini sudah ada di bill dengan satuan lain". |
| PAY-m6 | Serialisasi, balapan SQLite tak terlihat | Dicatat: pekerjaan hook kecil; `verify` mengukur; bila MySQL/MariaDB tersedia di verify, jalankan dua proses PHP membayar dua bill dengan satu stok; bila tidak, laporan akhir WAJIB menyatakan "jalur REPEATABLE READ hanya ditinjau". |
| PAY-m7 | Void oleh pembuat tanpa jejak | Reason wajib + event untuk semua void. |
| PAY-m8 | Fakta isolasi | Ditambahkan ke daftar fakta Randy (`@@transaction_isolation`, `@@innodb_lock_wait_timeout`). |
| PAY-m9 / KUX-m8 | Tambahan test | Semua daftar di 17.5 dimasukkan. |
| KUX-C2 | Dapur tidak diberi tahu perubahan saat bayar | Modal bayar (fnb, konteks bill, `pending_count>0`) menampilkan saklar "Cetak tiket dapur sekarang (n perubahan belum dikirim)", bawaan MENYALA; bila menyala, `Cashier` menghitung `kitchenBatch` SEBELUM `pay()`, lalu sesudah sukses mengirim `bill-print` dapur (tanpa ack; bill sudah `paid`) lewat antrean cetak sebelum struk. Modal sukses: "Tiket dapur ikut dicetak". Bila saklar dimatikan, modal bayar menyatakan jumlah perubahan yang tidak diberitahukan. Penjualan langsung (tanpa bill) tetap punya tombol "Cetak tiket dapur" satu ketukan. |
| KUX-C3 | Setelah Simpan/Kirim kasir tetap di dalam bill | `saveBill`/`sendToKitchen` (dari konteks mana pun) mengakhiri dengan KEMBALI ke "Order baru" kosong dan event `bill-saved` -> toast >= 8 dtk "Bill #12 (Meja 5) tersimpan" [Bayar] [Buka]. Membuka bill dari daftar menjaga konteks dengan chip kontras tinggi. |
| KUX-C4 | Pindah meja tak terlihat dapur | Label meja yang terakhir diberitahukan disimpan di `last_kitchen_batch.table_label`; `kitchenBatch` membandingkannya, mencetak "PINDAH: meja 4 -> 6" (tebal), menghitungnya sebagai perubahan tertunda sehingga Kirim aktif walau tanpa perubahan item. Gabung/pisah meja tetap di luar scope. |
| KUX-I1 | Mock v3 dan plan beda produk | `design` merombak ke v4 mengikuti SEMANTIK rencana (keranjang pribadi + chip konteks bill) dengan perilaku dapur dari mock v3 (14.8). |
| KUX-I2 | Simpan vs Kirim membingungkan | fnb: [Kirim ke dapur] (sub-label "simpan + cetak tiket") memimpin, [Simpan saja] sekunder; toast pasca Simpan saja: "Bill #12 disimpan. BELUM dikirim ke dapur." [Kirim ke dapur]; retail: satu tombol [Simpan bill]. Daftar bill: filter "Belum ke dapur"; chip kuning "Belum ke dapur" menjadi merah setelah 3 mnt (`pos.unsent_alert_minutes`) dan menampilkan umur; titik kuning berhitung pada `bills-toggle`. |
| KUX-I3 | Daftar 30 bill tak terpakai | Pencarian (meja, nama, no.), filter Semua/Bill saya/Belum ke dapur/Dari kemarin, baris ringkas dua baris + satu aksi utama + menu, chip meja cepat (fnb), baris yang sedang fokus/menu terbuka tidak dirender ulang, bill belum terkirim diurut lebih dulu, dialog konfirmasi dan layar bayar selalu mengulang label+total. |
| KUX-I4 | Netting, status per baris, konfirmasi menyesatkan | Pengurangan di bawah `sent_qty` TIDAK menurunkan `sent_qty` (BATAL diturunkan dari selisih), penghapusan baris menulis entri `pending_cancel` yang dikonsumsi ulang bila produk ditambah lagi; sehingga minus lalu plus tidak mencetak BATAL+TAMBAHAN. Chip per baris (`Baru`, `Terkirim 2 - baru 1`, `Batal 1`, `Catatan berubah`). Teks konfirmasi baru "Hapus {nama}? Dapur sudah menerima {n}. Dapur baru diberi tahu saat Anda menekan Kirim ke dapur." + strip permanen "Ada n pembatalan yang belum diberitahukan ke dapur [Kirim ke dapur]". Undo 6 dtk berlaku juga untuk baris terkirim (aman karena netting). |
| KUX-I5 | Kirim terblokir versi; "Simpan sebagai bill baru" berbahaya | `kitchenBatch` dihitung dari data server terkunci tanpa `expectedVersion`; toast "Termasuk perubahan dari perangkat lain" bila versi klien tertinggal. `expectedVersion` hanya untuk bayar, void, dan ubah header. "Simpan sebagai bill baru" DIHAPUS seluruhnya; dialog konflik hanya [Muat ulang]. Test: +, +, Kirim cepat tanpa konflik palsu. |
| KUX-I6 | Aturan cetak ulang dan isi tiket | Tidak ada `reprintKitchen(all)`; hanya `Cetak ulang tiket terakhir` = `last_kitchen_batch`, isi identik, "SALINAN" di atas dan bawah, tanpa perubahan status. Batch hanya pembatalan berjudul PEMBATALAN; "TAMBAHAN - KIRIMAN KE-n" dan penutup "Kiriman ke-n"; jam = `printed_at_label`; "UBAH CATATAN:"; tiket void "BILL DIBATALKAN" + alasan; MEJA/nomor order ukuran terbesar; suffix "(total n)" pada baris tambahan. |
| KUX-I7 | Bawa pulang vs dine-in | Di fnb, kirim pertama tanpa meja dan tanpa nama menampilkan prompt "Meja atau nama belum diisi. Tiket akan tertulis BAWA PULANG #012." [Isi meja] [Kirim sebagai bawa pulang]; ubin meja langsung berlaku. |
| KUX-I8 | Jumlah ketukan | Cetak langsung tanpa modal pratinjau (Pratinjau di menu baris); ubin meja berlaku langsung; meja bisa dipilih dari chip header sebelum item; pratinjau produksi mengikuti lebar printer tersimpan. |
| KUX-I10 | "Dari kemarin" berteriak | `stale` = `business_date < hari ini` DAN umur > `pos.stale_after_hours` (6 jam); lebih muda dilabeli "Lewat tengah malam". |
| KUX-I11 | Kosakata | Terjemahan baru (bagian 15): Bill, Bill sementara, BELUM DIBAYAR, BUKAN BUKTI PEMBAYARAN, Sudah/Belum ke dapur, "Order baru (belum disimpan)", "Kembali ke order baru", dismiss = "Kembali". |
| KUX-I12 | Reload kehilangan konteks | `OpenBillContext` (cache `cashier-open-bill:{user}`, TTL 30 mnt) memulihkan bill terakhir di `mount()` dengan chip "Melanjutkan Bill #12 (Meja 5) [Order baru]". |
| KUX-I13 | Status printer | Tiket dapur diakhiri `size(0,0)`, `style('normal')`, `align left` sebelum potong; test Node. Topologi printer = pertanyaan fakta F6; toast gagal-cetak adalah minimum. |
| KUX-I14 | Layar bayar 320 px | Saran nominal satu baris di 320 px (3 chip + ikon Uang pas); ringkasan rincian terlipat di bawah 400 px. |
| KUX-m* | Target sentuh, keyboard, dialog, total, urutan kategori, polling | Pil status = target 44 px atau teks non-interaktif; `visualViewport` + numpad kustom (tanpa keyboard sistem) untuk uang; dialog (`role=dialog`, `aria-modal`, inert, focus trap, Esc, kembalikan fokus, `role=status`, `role=alert`); "(total n)"; urutan kategori A-Z dicatat sebagai pekerjaan lanjutan; `syncBill` tidak menimpa input fokus/kotor; janji "satu nomor" hanya bila dibayar hari yang sama (ditulis di ringkasan Randy). |

### 13.2 DITOLAK / DITERIMA SEBAGIAN (dengan alasan)

| ID | Keputusan | Alasan |
|---|---|---|
| DA-M2 | DITOLAK: `Selling::query()->count()` dipertahankan | Mengganti skema `code` (`SELL`+count+1) ke `max(id)+1` mengubah perilaku data hidup dan bukan bagian permintaan; perubahan satu baris `count()` sudah menghapus pemuatan seluruh tabel. Dengan kunci global, bagian kritis tetap pendek untuk satu toko. Dicatat sebagai pekerjaan lanjutan. |
| DA-M6 | DITOLAK sebagai kode: `ProductResource` tidak disentuh | `ForceDeleteAction` pada produk yang muncul di `open_bill_items` memberi error FK mentah 1451, SAMA seperti `cart_items`/`selling_details` hari ini. Mengubah resource produk di luar scope. Ditulis di dokumen (produk jangan dihapus permanen selama ada bill terbuka); jalur bayar/daftar memakai `withTrashed` dan menamai produk. |
| DA-M7 / DA-M9 | HANYA DOKUMEN | Dasbor memakai zona profil/`created_at`; restore dump antar-zona menggeser `created_at`. Dicatat di `docs/deploy-open-bill.md`; `DashboardController` tidak diubah. |
| PAY-m3 | DITOLAK: `selling_id` tetap `ON DELETE SET NULL` | `restrict` akan memblokir alur hapus penjualan yang ada dengan error FK mentah. Bill `paid` tanpa penjualan tidak berbahaya (tanpa efek stok/uang); bill `paid` selalu read-only. Test + baris dokumen. |
| DA-M8 | SEBAGIAN: peringatan di `CashDrawerController::close` TIDAK dibuat | Mengubah respons API laci kas yang dipakai aplikasi mobile. Gantinya: dokumen + aturan "bill masuk laci saat bayar" + `Cash drawer is not opened` bukan 500. |
| PAY-I4(b) | SEBAGIAN | Override stok minus hanya untuk baris `sent_qty>0` dan hanya pengguna `update selling`/owner; bukan untuk semua kasir (ini jalan pintas penipuan stok). |
| KUX-I5 | SEBAGIAN diperluas | Saya menghapus "Simpan sebagai bill baru" untuk SEMUA bill (bukan hanya bill terkirim) karena salinan sebelum kirim tidak bernilai dan menambah jalur kode. |
| DA-I8 | SEBAGIAN | Pilihan "omzet ikut hari pesan" ditolak sebagai bawaan (butuh jalur backdate penjualan, melanggar prinsip penjualan memakai jam bayar); ditawarkan sebagai pertanyaan B1. |
| KUX-I1 | SEBAGIAN | Mock v3 membuat bill otomatis pada item pertama (opsi A4 yang saya tolak karena bill yatim dan penulisan ulang `POS`). Yang diambil dari mock adalah perilaku dapur dan daftar, bukan model data. |

### 13.3 Perubahan tambahan dari saya (tidak diminta DA, perlu untuk kebenaran)

- **M-A. Migrasi benar-benar idempoten pada MySQL.** `CREATE TABLE` lalu `ALTER` (FK/indeks) yang terpisah berarti kegagalan di tengah meninggalkan tabel tanpa FK/indeks; ulangan dengan guard `hasTable` akan melewati pembuatan. Pola wajib: `Schema::create` hanya kolom + PK; lalu `SafeMigration::index/unique/foreign` yang masing-masing idempoten (`hasIndex` / `getForeignKeys`). Pada SQLite (tes) FK didefinisikan inline karena `ALTER ADD FOREIGN KEY` tidak tersedia.
- **M-B. `ReceivableService`** memakai `total_price - discount_price - total_discount_per_item` (jumlah yang benar-benar ditagih) sebagai `total_receivable`; hanya piutang BARU yang terpengaruh, tanpa menulis ulang data. Ini jalan paling jujur agar angka "Sisa piutang" di modal sama dengan database. Bila Randy menolak (B10), modal menyembunyikan "Sisa piutang" saat ada diskon.
- **M-C. Tidak ada jalur kode-baru-skema-lama:** `php artisan down` wajib, dan kode memeriksa keberadaan tabel (positif di-cache) untuk lapisan bill.
- **M-D. Logging kejadian bill** (`open_bill_events`) menggantikan kebutuhan `pending_cancel` sebagai satu-satunya jejak.
- **M-E. `AssignProduct`** membuang `note` kosong (alasan DA-C1).
- **M-F. Kunci `pos.*`** di `config/pos.php`: `business_timezone`, `stale_after_hours=6`, `unsent_alert_minutes=3`, `cancel_sent_grace_minutes=10`, `active_context_ttl_minutes=30`.

### 13.4 Risiko yang tersisa (untuk laporan akhir)

R-A Kunci penjualan global menserialkan seluruh penjualan (diterima untuk satu toko; `verify` mengukur). R-B Jalur REPEATABLE READ MySQL hanya bisa ditinjau, tidak diuji, bila tidak ada MySQL lokal. R-C Tiket dapur tergantung printer di perangkat pencetak; perangkat tanpa printer memakai "Tandai terkirim tanpa cetak" (jejak di event). R-D Harga baris satuan-harga dihitung ulang saat bayar (batasan lama). R-E File cache non-atomik: dipakai hanya untuk pagar UX, bukan kebenaran. R-F Penghapusan permanen produk yang ada di bill terbuka memunculkan error FK mentah (batasan lama).


---

## 14. KONTRAK FINAL v3 (satu-satunya antarmuka antar paket; menang atas bagian 7 dan plan.md 11.3)

Berkas padanan: `final_contract_v3.txt`. Bagian plan.md 11.3 / `final_contract.txt` (v2) tetap berlaku HANYA untuk hal yang tidak disebut di sini (ProductBrowser, struktur CartItemNote dasar, LakasirPending, detektor scanner, partial cetak v1, API aditif `note`, selector uji lama). Baris v2 yang BATAL ada di 14.11.

### 14.0 Invarian (berlaku untuk semua paket)

- **R1 Urutan kunci (tidak boleh dibalik).** (1) baris `numbering_locks.name='sales'`; (2) baris `open_bills`; (3) baris `open_bill_items`; (4) baris `cart_items` milik kasir yang membayar; (5) baris `products` (StockGuard). Kode yang menomori (nomor penjualan, nomor bill, nama "(2)"), membuat penjualan, atau membayar bill: `NumberingLock::ensure()` (di luar transaksi) -> `DB::beginTransaction()` -> `NumberingLock::acquire()` sebagai PERNYATAAN PERTAMA -> sisanya. Edit baris biasa hanya (2)(3) dan tidak pernah memanggil `acquire()` setelah mengunci (2). Deadlock 1213 dan lock-wait 1205 diulang SATU kali; sesudahnya `SaleBusyException` (pesan "Sistem sedang sibuk, coba lagi"; API 503).
- **R2 Satu momen.** Semua jalur penjualan memakai `$data['date'] = BusinessDay::normalize($data['date'] ?? null)` di `SellingService::create`; hari bisnis, nomor, nama kembar, kolom `date` diturunkan dari nilai itu.
- **R3 Uang.** Semua pembulatan/perbandingan uang lewat `Money`; semua pembanding qty lewat `Money::qty*`.
- **R4 Migrasi.** Aditif, idempoten per pernyataan (14.9), tanpa penulisan data selain baris kunci `sales` yang dibuat `NumberingLock::ensure()` (bukan migrasi).
- **R5 Turun-ke-bawah.** Tabel `numbering_locks` tak ada -> kunci no-op + warning (perilaku produksi sekarang); tabel `open_bills` tak ada -> `billsVisible=false`; `AssignProduct` membuang `note` kosong.
- **R6 Otorisasi.** Setiap method publik komponen bill: `abort_unless(OpenBillGate::canUse($user), 403)`; keputusan void/pembatalan dievaluasi di DALAM `mutate` terhadap baris terkunci.
- **R7 Jejak.** Setiap mutasi bill menulis `open_bill_events` di transaksi yang sama (`OpenBillService::log`).
- **R8 Sumber kebenaran.** DB; token/cache hanya pagar UX.

### 14.1 Hari bisnis, kunci, penomoran, SellingService (paket `numbering`)

```php
// config/pos.php
return [
  'business_timezone'          => env('POS_BUSINESS_TIMEZONE', 'Asia/Jakarta'),
  'stale_after_hours'          => 6,     // bill "Dari kemarin" hanya bila business_date < hari ini DAN umur > ini
  'unsent_alert_minutes'       => 3,     // chip "Belum ke dapur" menjadi merah setelah ini
  'cancel_sent_grace_minutes'  => 10,    // pembuat bill boleh membatalkan item terkirim sampai sekian menit sejak kiriman terakhir
  'active_context_ttl_minutes' => 30,    // pemulihan bill aktif setelah reload
];

namespace App\Support;
final class BusinessDay {
  public static function timezone(): string;                       // config valid (timezone_identifiers_list) atau 'Asia/Jakarta'
  public static function today(?\Carbon\CarbonInterface $now = null): string;   // 'Y-m-d' hari bisnis
  /** UTC 'Y-m-d H:i:s'. null/''/tak terbaca = now(). Carbon = ->utc(). String tanpa zona = UTC. 'Z'/'+07:00' dihormati lalu ->utc(). 'Y-m-d' saja = 00:00:00 UTC. Mikrodetik dibuang. */
  public static function normalize(\Carbon\CarbonInterface|string|null $moment): string;
  public static function dateOf(\Carbon\CarbonInterface|string|null $moment): string;   // hari bisnis 'Y-m-d' dari normalize($moment)
  public static function startUtc(string $businessDate): \Illuminate\Support\Carbon;
  public static function endUtc(string $businessDate): \Illuminate\Support\Carbon;      // eksklusif
  /** @return array{0:\Illuminate\Support\Carbon,1:\Illuminate\Support\Carbon} */
  public static function range(?string $businessDate = null): array;
  public static function scopeDay($query, string $column, string $businessDate);        // where col >= start and col < end; bind 'Y-m-d H:i:s' UTC
  public static function label(string $businessDate, ?string $today = null): ?string;   // null bila hari ini; 'KEMARIN' bila kemarin; selain itu 'd/m'
}

namespace App\Services\Tenants;
final class NumberingLock {
  public const SALES = 'sales';
  public static function available(): bool;     // Schema::hasTable('numbering_locks'); hanya hasil true di-cache per proses
  public static function ensure(): void;        // di luar transaksi; SELECT biasa (autocommit); insertOrIgnore HANYA bila baris belum ada; positif di-cache per proses; tabel tak ada -> no-op + satu Log::warning
  public static function acquire(): void;       // di dalam transaksi: DB::table('numbering_locks')->where('name','sales')->lockForUpdate()->first(); baris tak ada -> insertOrIgnore lalu select for update ulang; tabel tak ada -> no-op
}
final class DailyNumber {
  /** Pemanggil memegang acquire(). max(sellings.daily_order_number di hari via BusinessDay::scopeDay('date')) ; bila null: max atas baris `date IS NULL` dengan scopeDay('created_at'); lalu max(open_bills.number di business_date) ; hasil = max(semua)+1. Pembacaan open_bills dalam try/catch + hasTable yang di-cache positif. */
  public static function next(string $businessDate): int;
}
final class CustomerNameService {
  public static function generateDailyCustomerName(?string $name, ?string $businessDate = null, ?int $excludeSellingId = null, ?int $excludeBillId = null): ?string;
  // hitung "Nama" / "Nama (2)" dari sellings (scopeDay('date') + cadangan created_at untuk date null) DAN open_bills status open pada business_date; perilaku lama dikunci tes (mis. "Meja (5)") tidak berubah.
}
// app/Exceptions/SaleBusyException.php : final class SaleBusyException extends \RuntimeException { render($request): response (JSON 503 bila expectsJson) }

class SellingService {
  /**
   * $hooks = [
   *   'before'                     => ?Closure(): void,            // di dalam transaksi, sesudah acquire()
   *   'after'                      => ?Closure(Selling): void,     // di dalam transaksi, sesudah SellingCreated & RecalculateEvent
   *   'keep_customer_name'         => bool,                        // lewati penomoran "(2)"
   *   'verify_stock'               => bool,                        // StockGuard::assert di dalam transaksi
   *   'stock_override_product_ids' => int[],                       // produk yang boleh minus (override resmi)
   * ]
   */
  public function create(array $data, array $hooks = []): Selling;
  public function mapProductRequest(array $data): array;   // Money::round pada total_price/tax_price/money_changes; TIDAK memanggil Voucher::reduceUsed; menyimpan id voucher yang terpakai di $this->voucherToConsume
}
```
Urutan `create()` (persis): (1) `$data['date']=BusinessDay::normalize(...)`; (2) `NumberingLock::ensure()`; (3) loop maks 2 percobaan: `DB::beginTransaction()`; (4) `NumberingLock::acquire()` PERNYATAAN PERTAMA; (5) `hooks['before']`; (6) `StockGuard::assert($data['products'], ['override'=>...])` bila `verify_stock`; (7) `$s = new Selling; $s->numberingLocked = true; $s->keepCustomerName = ...; $s->fill($data); $s->save();` (`Selling::creating`: `daily_order_number` kosong -> `DailyNumber::next(BusinessDay::dateOf($s->date))`; nama -> `CustomerNameService` kecuali `keepCustomerName`); (8) `SellingCreated::dispatch`; (9) `RecalculateEvent::dispatch`; (10) konsumsi voucher: `Voucher::whereKey($id)->where('kuota','>',0)->decrement('kuota')` harus mengubah 1 baris, kalau 0 -> `ValidationException` kunci `voucher` (rollback); (11) `hooks['after']($s)`; (12) `DB::commit()`. Pengecualian -> `rollBack`; `QueryException` errno 1213/1205 -> ulang satu kali, lalu `SaleBusyException`. `Selling` mendapat properti transien `public bool $numberingLocked = false; public bool $keepCustomerName = false;` (bukan kolom). `Selling::creating` tetap sebagai cadangan di luar `SellingService` (acquire hanya bila `DB::transactionLevel()>0` dan `!numberingLocked`). Lainnya: `SellingObserver::creating` -> `Selling::query()->count()`, laci kas null-aman -> `ValidationException` 'Cash drawer is not opened'; `VoucherService::applyable` -> `Carbon::parse(BusinessDay::today())`, `reduceUsed()` dihapus pemakainya tetapi method dibiarkan; `SellingOverview::getSalesToday` -> `BusinessDay::range()`; `AssignProduct` -> `if (blank($productRequest['note'] ?? null)) unset($productRequest['note'])`. `config/app.php` TIDAK diubah.

### 14.2 Uang, total, stok, izin, jenis metode (paket `domain-support`)

```php
namespace App\Support;
final class Money {
  public static function round(float|int|string|null $v, int $scale = 2): float;
  public static function parse(mixed $v): float;                 // floatval(preg_replace('/[^\d.]/','',(string)$v)) (perilaku Livewire lama)
  public static function gte(float $a, float $b): bool;          // round(a,2) >= round(b,2)
  public static function eq(float $a, float $b): bool;
  public static function qty(float $v): float;                   // round 3 desimal
  public static function qtyEq(float $a, float $b): bool;
}
namespace App\Services\Tenants;
final class OrderTotals {
  public static function lineTotal($line): float;               // priceUnit ? Money::round(priceUnit.selling_price*qty) : Money::round($line->price ?? 0)
  public static function unitPrice($line): float;               // qty>0 ? Money::round(lineTotal/qty) : lineTotal
  public static function unitIsExact($line): bool;              // Money::eq(unitPrice*qty, lineTotal)  (UI/cetak mengawali "~" bila false)
  /** @return array{subtotal:float,line_discount:float,tax_price:float,header_discount:float,total_price:float,amount_due:float,qty:float,count:int}
   *  tax_price = Money::round(subtotal*taxPercent/100); total_price = Money::round(subtotal+tax_price) (nilai yang disimpan di sellings.total_price);
   *  amount_due = max(0, Money::round(total_price - line_discount - header_discount)) */
  public static function compute(\Illuminate\Support\Collection $lines, float $taxPercent, float $headerDiscount = 0.0): array;
}
final class OrderLinePricing {
  /** Baris BILL: qty naik -> oldPrice + (new-old)*currentUnitPrice; qty turun -> oldPrice/oldQty*newQty; hasil Money::round. Baris keranjang pribadi tetap perilaku lama (selling_price*qty). */
  public static function repriceOnQtyChange(float $oldQty, float $oldPrice, float $newQty, float $currentUnitPrice): float;
}
final class PaymentPlanner {
  /** @param array{payed_money?:mixed,member_id?:mixed,due_date?:mixed} $in
   *  @return array{kind:string,payed_money:float,money_changes:float}
   *  kredit DULU: member_id & due_date wajib; payed_money = clamp(Money::parse(in), 0, amountDue); money_changes=0.
   *  exact: payed_money = amountDue; money_changes = 0 (angka klien diabaikan).
   *  cash: payed_money = Money::parse(in) atau amountDue bila kosong/0; Money::gte(payed, amountDue) wajib; money_changes = Money::round(max(0, payed-amountDue)).
   *  @throws \Illuminate\Validation\ValidationException kunci payed_money|member_id|due_date */
  public static function plan(\App\Models\Tenants\PaymentMethod $method, float $amountDue, array $in): array;
}
final class StockGuard {
  /** @param array<int,array{product_id:int,qty:float|int|string,price_unit_id?:int|null}> $lines
   *  @param array{override?:int[]} $opts
   *  Agregasi per produk (qty*priceUnit.stock untuk baris satuan-harga); Product::withTrashed()->whereIn()->lockForUpdate();
   *  melewati is_non_stock/service dan produk di $opts['override']; aturan kurang = aturan CheckProductStock ($product->stock < kebutuhan).
   *  Produk terhapus: pesan 'Product :name was deleted'; stok kurang: 'Stock of :name is not enough (left :left)'.
   *  @throws \Illuminate\Validation\ValidationException kunci products.{index}.qty (kunci yang sama dengan API lama) */
  public static function assert(array $lines, array $opts = []): void;
}
final class OpenBillGate {
  public static function canUse(\App\Models\Tenants\User $u): bool;                       // $u->can('create selling')
  public static function canVoid(\App\Models\Tenants\OpenBill $b, \App\Models\Tenants\User $u): bool;   // belum pernah dikirim: pembuat | update selling | delete selling ; sudah dikirim: canCancelSent
  public static function canCancelSent(\App\Models\Tenants\OpenBill $b, \App\Models\Tenants\User $u): bool;   // delete selling | update selling | (b.created_by == u.id && b.kitchen_sent_at >= now - cancel_sent_grace_minutes)
  public static function canOverrideStock(\App\Models\Tenants\User $u): bool;             // update selling (owner lolos via Gate::after)
}
// PaymentMethod::kind(): string  -> 'credit' bila is_credit; 'cash' bila is_cash && !is_wallet && !is_debit; selain itu 'exact'. Tidak pernah dari nama.
// ReceivableService::create: total_receivable = Money::round($selling->total_price - $selling->discount_price - ($selling->total_discount_per_item ?? 0)); rest_receivable = Money::round(total_receivable - payed_money). (hanya piutang baru; tanpa penulisan ulang)
// CheckProductStock: pesan 'Stock of :name is not enough (left :left)'; produk tak ditemukan 'The product is not found.' tetap; kunci validasi products.N.qty tetap.
// OrderLineSorter: sort/group menerima CartItem|OpenBillItem|SellingDetail (duck-typed, tanpa query).
// TransactionSellingStoreRequest::store memanggil create($data, ['verify_stock' => true]).
```

### 14.3 Skema DB persis (MySQL; semua `bigint unsigned` = `id()`/`foreignId`)

Berkas migrasi dan urutan (nama berkas EKSAK):
1. `2026_10_14_000001_add_pos_performance_indexes.php` (ditulis ulang, 14.9)
2. `2026_10_15_000001_add_note_to_cart_items_and_selling_details_tables.php` (dikerjakan ulang memakai `SafeMigration`)
3. `2026_10_15_000002_create_open_bills_table.php`
4. `2026_10_15_000003_create_open_bill_items_table.php`
5. `2026_10_15_000004_create_open_bill_events_table.php`
6. `2026_10_15_000005_create_numbering_locks_table.php`
7. `2026_10_15_000006_add_date_index_to_sellings_table.php`
HAPUS: `2026_10_15_000002_add_listing_indexes_to_products_table.php`.

```
cart_items      ADD note varchar(255) NULL                         (tanpa AFTER)
selling_details ADD note varchar(255) NULL

open_bills                       ENGINE=InnoDB (utf8mb4 default koneksi)
  id                    bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY
  number                int unsigned NOT NULL
  business_date         date NOT NULL
  status                varchar(12) NOT NULL DEFAULT 'open'          -- open|paid|void (BUKAN ENUM MySQL)
  version               int unsigned NOT NULL DEFAULT 1
  customer_name         varchar(255) NULL
  table_id              bigint unsigned NULL   FK tables(id)  ON DELETE SET NULL
  member_id             bigint unsigned NULL   FK members(id) ON DELETE SET NULL
  note                  text NULL
  tax_percent           decimal(6,3) NOT NULL DEFAULT 0.000         -- dibekukan saat bill dibuat
  discount_price        double NOT NULL DEFAULT 0
  kitchen_batch_count   smallint unsigned NOT NULL DEFAULT 0
  last_kitchen_batch    mediumtext NULL                              -- JSON batch terakhir yang di-ACK (termasuk table_label)
  pending_cancel        mediumtext NULL                              -- JSON [{ref,product_id,name,qty,note,price_unit_id,removed_by,removed_at,reason}] baris terkirim yang DIHAPUS
  kitchen_sent_at       datetime NULL
  created_by            bigint unsigned NULL   FK users(id) ON DELETE SET NULL
  paid_by               bigint unsigned NULL   FK users(id) ON DELETE SET NULL
  voided_by             bigint unsigned NULL   FK users(id) ON DELETE SET NULL
  paid_at               datetime NULL
  voided_at             datetime NULL
  void_reason           varchar(255) NULL
  selling_id            bigint unsigned NULL   FK sellings(id) ON DELETE SET NULL
  created_at, updated_at timestamp NULL
  UNIQUE open_bills_day_number_unique   (business_date, number)
  INDEX  open_bills_status_day_index    (status, business_date)
  UNIQUE open_bills_selling_id_unique   (selling_id)
  FK     open_bills_table_id_foreign, open_bills_member_id_foreign, open_bills_created_by_foreign, open_bills_paid_by_foreign, open_bills_voided_by_foreign, open_bills_selling_id_foreign  (nama bawaan Laravel, semua <= 64)

open_bill_items                  ENGINE=InnoDB
  id                    bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY
  open_bill_id          bigint unsigned NOT NULL  FK open_bills(id) ON DELETE CASCADE
  product_id            bigint unsigned NOT NULL  FK products(id)   (RESTRICT, sama dengan cart_items)
  user_id               bigint unsigned NULL      FK users(id) ON DELETE SET NULL      -- siapa menambah
  qty                   double NOT NULL
  price                 double NULL                -- total baris sebelum diskon (semantik cart_items.price)
  discount_price        double NULL
  price_unit_id         bigint unsigned NULL      FK price_units(id) ON DELETE SET NULL
  note                  varchar(255) NULL
  sent_qty              double NOT NULL DEFAULT 0
  sent_note             varchar(255) NULL
  created_at, updated_at timestamp NULL
  UNIQUE open_bill_items_bill_product_unique (open_bill_id, product_id)

open_bill_events                 ENGINE=InnoDB  (append-only)
  id                    bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY
  open_bill_id          bigint unsigned NOT NULL  FK open_bills(id) ON DELETE CASCADE
  user_id               bigint unsigned NULL      FK users(id) ON DELETE SET NULL
  action                varchar(24) NOT NULL      -- konstanta di OpenBillEvent: created, header, line_added, line_qty, line_removed, line_note, line_discount, sent, sent_manual, cancel_sent, void, paid, pay_with_unsent, stock_override, reprint
  product_id            bigint unsigned NULL      -- tanpa FK (snapshot)
  product_name          varchar(255) NULL
  qty_from              double NULL
  qty_to                double NULL
  reason                varchar(255) NULL
  detail                text NULL                 -- JSON kecil
  created_at            timestamp NULL
  INDEX open_bill_events_bill_index (open_bill_id, id)

numbering_locks                  ENGINE=InnoDB
  name                  varchar(32) NOT NULL PRIMARY KEY            -- satu baris: 'sales' (dibuat NumberingLock::ensure(), bukan migrasi)

sellings (ALTER, opsional, boleh dilewati)
  INDEX sellings_date_number_index (date, daily_order_number)
```
Ukuran kunci (utf8mb4): `open_bills_status_day_index` = 12*4+2+3 = 53 B; `open_bills_day_number_unique` 7 B; `open_bill_items_bill_product_unique` 16 B; `open_bill_events_bill_index` 16 B; PK `numbering_locks` 32*4+2 = 130 B; `sellings_date_number_index` ~ 8+4 B. Semua << 3072 B. Nama indeks terpanjang 35 karakter (<= 64). Tipe FK cocok dengan induk `id()` (bigint unsigned).

### 14.4 Model

```php
namespace App\Enums; enum OpenBillStatus: string { case Open='open'; case Paid='paid'; case Void='void'; }
namespace App\Models\Tenants;
class OpenBill extends Model {   // guarded ['id']; casts: status=OpenBillStatus, business_date='date:Y-m-d', tax_percent='float', discount_price='float', pending_cancel='array', last_kitchen_batch='array', paid_at/voided_at/kitchen_sent_at='datetime'
  items(): HasMany; events(): HasMany; table(); member(); creator(); payer(); voider(); selling();
  scopeOpen($q); label(): string (customer_name ?: "Meja {table.number}" ?: "Bill #".str_pad(number,3,'0',STR_PAD_LEFT)); formattedNumber(): string ("Order #012");
}
class OpenBillItem extends Model { /* guarded ['id']; HasOrderLine; casts qty/price/discount_price/sent_qty float; bill(); product() (withTrashed); priceUnit(); */ }
class OpenBillEvent extends Model {   // guarded ['id']; const UPDATED_AT = null; casts detail array; konstanta action (lihat skema); public static function record(OpenBill $b, string $action, ?User $by, array $attrs = []): self
}
```

### 14.5 OpenBillService, presenter, pengecualian (paket `open-bill-backend`)

Pengecualian di `App\Exceptions\OpenBill\`: `BillConflictException` (versi berubah; property `public int $serverVersion`), `BillClosedException` (bukan open), `BillEmptyException`, `BillForbiddenException` (gate), `BillReasonRequiredException`. Semua `extends \DomainException` dengan pesan sudah diterjemahkan. `App\Services\Tenants\PayResult` = `final class PayResult { public function __construct(public Selling $selling, public bool $alreadyPaid = false, public ?array $kitchen = null) {} }`.

```php
namespace App\Services\Tenants;
final class OpenBillService {
  public function __construct(private SellingService $sellingService) {}

  public function createFromCart(User $by, array $header): OpenBill;
  //  $header: customer_name?, table_id?, member_id?, note?, discount_price?  (R1: ensure -> tx -> acquire -> number=DailyNumber::next(BusinessDay::today()) -> nama final via CustomerNameService (menghitung bill open) -> tax_percent = Setting default_tax -> create
  //  -> pindahkan CartItem::cashier() milik $by (gabung product_id ganda: jumlah qty & price) ke open_bill_items, hapus keranjang pribadi -> event 'created'. Kosong -> BillEmptyException. SQLSTATE 23000 pada (business_date, number) -> ulang 1x.
  public function find(int $id): OpenBill;                        // items.product (withTrashed) + items.product.category + items.priceUnit + table + member + creator
  /** tx + lockForUpdate bill + status open (else BillClosedException; paid -> BillClosedException) + $fn($lockedBill) + version++ ; opts: ['sales_lock'=>bool]: true -> ensure, tx, acquire() DULU lalu kunci bill (R1) */
  public function mutate(int $billId, \Closure $fn, array $opts = []): mixed;
  public function log(OpenBill $bill, string $action, ?User $by, array $attrs = []): void;     // tulis OpenBillEvent (dipanggil di dalam mutate)

  // --- baris (hanya kunci (2)(3); tanpa expectedVersion) ---
  public function addLine(int $billId, Product $product, float $qty, ?int $priceUnitId, ?string $note, User $by): OpenBillItem;
  //   qty = selisih yang ditambahkan; baris sudah ada -> qty+=; harga lewat OrderLinePricing; bila pending_cancel punya entri untuk produk itu -> sent_qty := entri.qty, sent_note := entri.note, entri dibuang (netting); StockGuard TIDAK dipanggil di sini (validasi cepat tetap di CartInteraction::validateStock).
  public function setLineQty(int $billId, int $itemId, float $qty, User $by, ?string $reason = null): ?OpenBillItem;
  //   qty <= 0 -> hapus; qty < sent_qty (Money::qtyEq/<): butuh OpenBillGate::canCancelSent(locked bill) else BillForbiddenException, dan $reason wajib (BillReasonRequiredException); sent_qty TIDAK diturunkan (BATAL diturunkan dari selisih); hapus baris terkirim -> entri pending_cancel (ref unik 8 hex) + event 'line_removed'; reduksi -> event 'cancel_sent'.
  public function setLineNote(int $billId, int $itemId, ?string $note, User $by): void;
  public function setLineDiscount(int $billId, int $itemId, ?float $discount, User $by): void;
  public function restoreLine(int $billId, array $snapshot, User $by): void;      // untuk Undo (lewat addLine)

  // --- header ---
  public function updateHeader(OpenBill $bill, array $header, int $expectedVersion, User $by): OpenBill;
  //   expectedVersion wajib (BillConflictException); bila customer_name berubah -> mutate(sales_lock:true) dan nama dihitung ulang (exclude bill ini); table_id/note/member/discount tanpa kunci penjualan; event 'header'.

  // --- dapur (dua fase) ---
  /** MURNI (tanpa tulis). null bila tidak ada selisih. */
  public function kitchenBatch(OpenBill $bill): ?array;
  //   array{ batch_no:int(kitchen_batch_count+1), kind:'new'|'addition'|'cancel_only'|'change_only',
  //          items:list<{item_id,product_id,name,qty(selisih),total_qty,note,category,category_id}>,
  //          cancelled:list<{ref,source:'reduce'|'removed',item_id:?int,product_id,name,qty,note}>,
  //          changed:list<{item_id,product_id,name,note,was}>, moved:?{from:?string,to:?string}, table_label:?string, pending_count:int,
  //          ack:{bill_id,batch_no,items:list<{item_id,to_qty,to_note}>,cancel_refs:list<string>,table_label:?string} }
  //   ack.items memuat SEMUA baris yang sent_qty/sent_note-nya akan berubah: baris tambahan (to_qty=qty), baris dengan reduksi (to_qty=qty sekarang) dan baris changed (to_note); entri pending_cancel yang dicetak masuk ack.cancel_refs.
  //   selisih: delta = Money::qty(qty - sent_qty) > 0 -> items; qty < sent_qty -> cancelled(reduce, ref "r{item_id}", qty = sent_qty-qty); catatan beda (null == '') dengan qty == sent_qty -> changed; pending_cancel -> cancelled(removed); moved bila kitchen_batch_count>0 && label meja sekarang != last_kitchen_batch.table_label
  public function pendingCount(OpenBill $bill): int;                              // items + cancelled + changed + (moved?1:0); 0 bila kitchen_batch_count==0 && tak ada item
  public function ackKitchenBatch(int $billId, array $ack, User $by, string $mode = 'printed'): OpenBill;
  //   mutate; idempoten: ack.batch_no <= kitchen_batch_count -> kembalikan tanpa ubah; batch_no > count+1 -> BillConflictException; selain itu set sent_qty/sent_note per ack.items, buang pending_cancel yang ref-nya di ack.cancel_refs, kitchen_batch_count=batch_no, last_kitchen_batch=payload ringkas (termasuk table_label), kitchen_sent_at=now, event 'sent' ($mode 'manual' -> 'sent_manual').
  public function lastKitchenBatch(OpenBill $bill): ?array;                       // last_kitchen_batch untuk "Cetak ulang tiket terakhir"

  // --- void ---
  /** reason wajib (min 3 karakter, maks 255). Gate dievaluasi pada baris terkunci. Mengembalikan payload tiket void (semua baris sent_qty>0 sebagai cancelled + reason) atau null bila tak pernah terkirim. Baris TETAP disimpan. */
  public function void(OpenBill $bill, User $by, string $reason, int $expectedVersion): ?array;

  // --- bayar ---
  public function pay(OpenBill $bill, array $payment, int $expectedVersion, User $by, array $opts = []): PayResult;
  //   $payment HANYA: payment_method_id, payed_money, member_id, due_date, voucher. $opts: ['stock_override'=>bool]. $by harus == auth()->user() (observer memakai auth()).
  //   (1) bill sudah paid dengan selling_id -> PayResult(alreadyPaid:true) tanpa efek lain. (2) pre-cek di luar transaksi: status, versi, item tidak kosong, metode ada (else ValidationException payment_method_id), laci kas bila cash_drawer_enabled (ValidationException 'Cash drawer is not opened').
  //   (3) $date = BusinessDay::normalize(null); $sameDay = bill.business_date === BusinessDay::dateOf($date). (4) products[] dari baris (product_id, qty, price, discount_price, price_unit_id, note); tax = bill.tax_percent; discount_price = bill.discount_price; friend_price=false; amount_due = OrderTotals; PaymentPlanner::plan(...); mapProductRequest.
  //   (5) SellingService::create($data + ['date'=>$date] + ($sameDay ? ['daily_order_number'=>bill.number] : []), hooks): before = baca ulang bill FOR UPDATE (status open, versi == expected, tanda tangan baris sama dengan saat dibangun, laci kas) ; after = bill.status=paid, paid_by, paid_at, selling_id, version++, event 'paid' (+ 'pay_with_unsent' bila pendingCount>0) (+ 'stock_override'); 'keep_customer_name'=>$sameDay; 'verify_stock'=>true; 'stock_override_product_ids' = produk baris sent_qty>0 hanya bila opts.stock_override && OpenBillGate::canOverrideStock($by).
  //   TANPA transaksi luar. Mengembalikan PayResult dengan ->kitchen = null.
  public function totals(OpenBill $bill): array;                                 // OrderTotals::compute(items, bill.tax_percent, bill.discount_price)
  /** 3 query konstan (bill+relasi, item+produk+kategori+satuan, count) */
  public function listOpen(User $viewer, ?int $createdBy = null): \Illuminate\Support\Collection;
  public function printPayload(OpenBill $bill, string $mode, ?array $batch = null, bool $reprint = false, ?User $by = null): array;   // 'bill'|'kitchen'
}
final class OpenBillPresenter {
  public static function payload(OpenBill $bill, string $mode, ?array $batch, bool $reprint, ?User $by): array;
  //  Bentuk Selling + : id (bill id), job_key ('k{id}-{batch_no}' | 'b{id}-v{version}' | '{mode}{id}-r' bila reprint), daily_order_number, formatted_daily_order_number, number_label ('#012'), business_date_label (null bila hari ini; 'KEMARIN'/'d/m'), customer_name, table{number}, member, note, note_plain, user{name}, created_at_label, printed_at_label (zona bisnis), subtotal, tax, tax_price, discount_price, total_discount_per_item, amount_due, selling_details[{qty,price(total baris),discount_price,note,product{id,sku,name,category{id,name}|null}}] (mode kitchen: hanya baris batch dengan qty=selisih dan total_qty), kitchen{kind,batch_no,reprint,cancelled[],changed[],moved,table_label,void_reason|null,is_void}, price_stale (per baris) 
  public static function row(OpenBill $bill, array $totals, User $viewer, bool $kitchenEnabled): array;
  //  id, number, number_label, business_date_label, label, table_label, customer_name, creator_name, items_count, qty_total, amount_due, age_minutes, stale (aturan DA/KUX-I10), crossed_midnight (business_date<today && !stale), sent (kitchen_batch_count>0), unsent (kitchenEnabled && (kitchen_batch_count==0 || pendingCount>0)), unsent_count, unsent_age_minutes (dari updated_at), version, can_void, can_cancel_sent
}
```

### 14.6 Komponen `Cashier` dan `CartInteraction` (paket `cashier-livewire`)

Kontrak plan.md 11.3 tetap untuk draft pesanan baru (`CashierDraftService`: hanya pesanan BARU yang belum disimpan), `commitCustomerName`, `restoreCartItem`, `selectTable`, `saveCustomTable`, `storeCart`, `resetOrder`, `getViewData`. Tambahan/perubahan:

```php
#[Locked] public ?int $openBillId = null;        // null = keranjang pribadi "Order baru"
#[Locked] public ?int $openBillVersion = null;
#[Locked] public bool $paymentPinned = false;    // true selama modal bayar terbuka: syncBill() no-op
public ?array $openBill = null;                  // id, number, number_label, label, version, sent(bool), batches(int), stale(bool), items_count, pending_count
public string $paymentToken = '';
public bool $billsEnabled = true;                // Setting open_bill_enabled (bawaan true) -> hanya mengatur PEMBUATAN (Simpan/Kirim)
public bool $billsVisible = true;                // tabel open_bills ada && (billsEnabled || jumlah bill open > 0)
public bool $kitchenEnabled = false;             // About business_type == 'fnb'
public bool $printKitchenOnPay = true;           // saklar modal bayar (diatur $wire.set)
public bool $stockOverride = false;              // konfirmasi override stok (diatur $wire.set), hanya efektif bila gate
public ?array $kitchenPreview = null;            // {pending:int} untuk modal bayar fnb konteks bill

public function openBill(int $billId): void                       // hanya status open; gate; muat header ke cartDetail; konteks bill; refreshCart; OpenBillContext::remember; dispatch 'open-bill-changed'
public function leaveBill(): void                                 // label "Kembali ke order baru"; restoreDraft
public function saveBill(): void                                  // konteks pribadi: createFromCart -> RESET ke order baru -> dispatch 'bill-saved' {id,number,label,sent:false}; BillEmptyException -> notifikasi "Add the bill first"; keranjang kosong karena respons hilang -> "Bill may already exist, check the bills list"
public function sendToKitchen(?int $billId = null, bool $takeaway = false): void
//   fnb tanpa meja & tanpa nama & !$takeaway (konteks pribadi atau bill) -> dispatch 'takeaway-prompt' dan berhenti; konteks pribadi -> createFromCart; kitchenBatch (murni); null -> notifikasi 'Nothing new to send' + tombol cetak ulang; selain itu dispatch 'bill-print' {mode:'kitchen', bill, reprint:false, ack, job_key} ; kemudian RESET ke order baru + 'bill-saved' {sent:true}.
#[On('kitchen-ack')] public function ackKitchen(array $ack, string $mode = 'printed'): void   // OpenBillService::ackKitchenBatch; dispatch 'open-bills-changed'
public function printProvisional(?int $billId = null): void       // dispatch 'bill-print' mode 'bill'
public function reprintKitchen(?int $billId = null): void         // HANYA last_kitchen_batch, reprint=true, tanpa ack, event 'reprint'
public function cancelSent(int $itemId, float $newQty, string $reason): void   // setLineQty dengan gate+reason
public function voidBill(string $reason, ?int $billId = null): void
#[On('open-bill-selected')] public function onOpenBillSelected(int $id): void
#[On('open-bill-pay')] public function onOpenBillPay(int $id): void           // openBill + syncCartForPayment + open-modal proceed-the-payment
#[On('open-bill-changed')] public function onBillChildChanged(int $id): void  // anak (CartItemNote/PriceSetting) -> muat ulang: BACA VERSI DULU, baris sesudahnya
public function syncCartForPayment(): void        // PIN versi (versi dibaca dulu), paymentToken=uuid, paymentPinned=true, kitchenPreview
public function paymentClosed(): void             // paymentPinned=false (dipanggil dari penutupan modal)
public function syncBill(): void                  // wire:poll.20s.visible; no-op bila !bill atau paymentPinned; memuat ulang hanya bila versi DB != openBillVersion; JANGAN menimpa input kotor (klien menunda)
public function proceedThePayment(SellingService $sellingService): void   // TANDA TANGAN TETAP
```
`proceedThePayment` (kedua jalur): (a) muat baris; (b) `friend_price=false`; (c) `$method = PaymentMethod::find(...)` (jalur bill: tidak ada -> ValidationException; jalur langsung: fallback Cash lama); (d) `PaymentPlanner::plan($method, amount_due, [...])` — GANTI cabang `wallet||debit||!is_cash` lama; (e) validator sisanya (kredit: member, due_date); (f) jalur bill: `OpenBillService::pay(bill, payment, openBillVersion, user, ['stock_override'=>...])`; jalur langsung: `SellingService::create($data, ['verify_stock'=>true, 'before' => kunci baris keranjang + cocokkan tanda tangan (id,qty,price,discount_price,note) yang dibaca di (a) atau `CartChangedException`, 'after' => `CartItem::cashier()->delete()`])` DI DALAM transaksi; (g) token (PAY-C3): validasi dulu; `Cache::has("paydone:{u}:{t}")` -> buka modal sukses penjualan itu, selesai; `Cache::add("paylock:{u}:{t}",1,30)` gagal -> return; `finally Cache::forget("paylock:..")`; sukses -> `Cache::put("paydone:{u}:{t}", selling_id, 600)`; kegagalan apa pun -> `paymentToken = Str::uuid()`; (h) pesan `ValidationException` sampai ke modal (`@error`); `BillConflictException` -> notifikasi + browser `bill-conflict {id, serverVersion, label, amount_due}`; `SaleBusyException`/`CartChangedException` -> notifikasi. Sukses jalur bill dengan `printKitchenOnPay && pending>0`: hitung `kitchenBatch` SEBELUM pay, setelah sukses dispatch `bill-print` dapur (tanpa ack) lalu `selling-created`. `calculateTotalPrice()` memakai `OrderTotals::compute` (+ diskon header `cartDetail['discount_price']`), pajak = `bill.tax_percent` pada konteks bill, selain itu `Setting default_tax`. Cek laci kas di awal (pesan, bukan 500).

`CartInteraction` (aditif; `POS` dan `Pages/CartItem` tidak berubah): `protected function activeBillId(): ?int` (default null), `lineModel()`, `lineQuery()`, `withLineLock()` untuk konteks pribadi; pada konteks bill `addCart/reduceCart/deleteCart/addDiscountPricePerItem/restoreCartItem` MENDELEGASIKAN ke `OpenBillService::addLine/setLineQty/setLineDiscount/restoreLine` (tidak menyentuh `cart_items`). Reduksi di bawah `sent_qty` atau hapus baris terkirim tidak langsung; stepper "-" pada `qty == sent_qty` memanggil dialog; hanya `cancelSent(itemId,newQty,reason)` yang mengurangi. `cart-item-removed` membawa `undoable` (true juga untuk baris terkirim). Voucher memakai `BusinessDay::today()`.

`OpenBillContext` (`App\Services\Tenants\OpenBillContext`): `remember(int $userId, int $billId)`, `recall(int $userId): ?int`, `forget(int $userId)`; cache `cashier-open-bill:{userId}` TTL `pos.active_context_ttl_minutes`; `mount()` memulihkan hanya bila bill masih `open`. `PriceSetting`: `public Model $cartItem` + `authorizeLine()`; setelah ubah diskon dispatch `open-bill-changed` bila konteks bill. `CartItemNote`: `#[Locked] string $context ('cart'|'bill')`, `#[Locked] ?int $billId`; konteks bill -> `OpenBillService::setLineNote`; sesudah simpan dispatch `open-bill-changed {id}`. `ProductBrowser`: render menambah `committed` (map product_id -> SUM(open_bill_items.qty) bill open, satu query ber-`whereIn` produk yang tampil; dalam try/catch + hasTable cache positif) agar kartu menampilkan "Tersisa N setelah bill terbuka".
`App\Exceptions\CartChangedException extends \DomainException`.

### 14.7 Komponen `OpenBills` (paket `open-bills-component`)

```php
namespace App\Livewire\Cashier;
class OpenBills extends Component {
  public bool $open = false; public string $filter = 'all';   // all|mine|unsent|stale
  public string $search = '';
  public function mount(): void;                    // abort_unless canUse; hitung count + unsent_count (query ber-indeks)
  public function setOpen(bool $open): void;        // daftar hanya dimuat saat $open
  public function setFilter(string $filter): void;
  #[On('open-bills-changed')] public function refresh(): void;
  public function select(int $billId): void;        // dispatch('open-bill-selected', id:)->to(Cashier::class); tutup
  public function pay(int $billId): void;           // dispatch('open-bill-pay', id:)->to(Cashier::class); tutup
  public function printProvisional(int $billId): void;   // browser 'bill-print' mode bill
  public function reprintKitchen(int $billId): void;     // browser 'bill-print' kitchen reprint (last_kitchen_batch)
  public function previewBill(int $billId, string $mode): void;    // browser 'bill-preview'
  public function voidBill(int $billId, string $reason, int $expectedVersion): void;
  public function render();                          // view('livewire.cashier.open-bills', ['bills'=>rows terfilter/dicari, 'count'=>int, 'unsentCount'=>int, 'today'=>string])
}
```
Setiap method: `OpenBillGate::canUse` (403), termasuk jalur render yang dipakai `wire:poll`. Pencarian: nomor, nama, meja (server, setelah `listOpen`, tanpa query tambahan). Urutan: unsent dulu, lalu `business_date, number`.

### 14.8 Event, JS, tata letak cetak

Browser (server -> klien): `bill-print {mode:'bill'|'kitchen', bill:object, reprint:bool, ack:?object, job_key:string}`, `bill-preview {mode, bill}`, `bill-saved {id,number,label,sent:bool}`, `open-bill-changed {id,number,version}`, `bill-conflict {id,serverVersion,label,amount_due}`, `takeaway-prompt {number}`, `cart-item-removed {...,undoable:bool}`, `selling-created`, `order-reset`, serta event plan.md 11.3. Livewire: `open-bill-selected {id}`, `open-bill-pay {id}`, `open-bills-changed`, `kitchen-ack {ack,mode}`, `product-scanned`, `products-refresh`. Klien (partial -> bootstrap): `lakasir-print-failed {mode, jobKey, payload, ack, reason}` (bootstrap menampilkan toast permanen [Coba lagi] [Tandai terkirim tanpa cetak untuk dapur]).

`window.LakasirReceipt` (`version: 2`; method v1 TETAP):
```js
print(payload, opts?: {buttonId?:string, reprint?:boolean, mode?:'receipt'|'bill'|'kitchen', showPrices?:boolean, jobKey?:string}): Promise<boolean>
//  ANTREAN serial (satu rantai Promise); sebelum tiap pekerjaan menunggu window._lakasirIsPrintingNow bersih (maks 8 dtk, cek 100 ms); tidak pernah menjatuhkan pekerjaan.
//  pagar ganda: peta jobKey -> Promise selama 3 dtk; kunci = opts.jobKey ?? `${mode}:${payload.id ?? payload.daily_order_number}`; pemanggilan kedua dengan kunci sama mengembalikan Promise yang sama.
//  resolve(false) pada kegagalan (printer tak ada/ditolak/timeout) + window.dispatchEvent('lakasir-print-failed'); resolve(true) HANYA bila Printer.print() benar-benar dijalankan tanpa exception.
build(printer, payload, opts?: {mode?, reprint?, printerData?, showPrices?}): Printer     // murni
preview(payload, opts?): string                                                          // via TextPrinter (32/48 kolom), sama persis dengan build()
groupDetails, htmlToPlain, toPrintable, wrapText, itemName                                // tetap
```
Tata letak (kolom W = 32 (58 mm) / 48 (80 mm)); `~` bila `unit*qty != total`:
```
ITEM (receipt, bill)        Nasi Goreng Spesial            <- nama dibungkus
                            2 x 25.000           50.000     <- kiri qty x satuan ("2 x ~3.333,33" bila tak pas), kanan total; ditumpuk bila kiri+kanan > W
                              Diskon (2.000)
                              > pedas, tanpa telur
BILL SEMENTARA              judul 'BILL SEMENTARA' + 'BELUM DIBAYAR'; Order #nomor (+ label tanggal bila bukan hari ini); Subtotal / Pajak / Diskon / TOTAL (amount_due); penutup 'BUKAN BUKTI PEMBAYARAN'; tanpa baris bayar/kembali
TIKET DAPUR                 [SALINAN bila reprint]  (atas dan bawah)
                            baris besar (size(1,1) bold, maks 16 kolom efektif): 'MEJA 12' atau 'BAWA PULANG #012' atau nama
                            'DAPUR - PESANAN BARU' | 'DAPUR - TAMBAHAN - KIRIMAN KE-2' | 'DAPUR - PEMBATALAN' | 'DAPUR - BILL DIBATALKAN' (+ alasan)
                            Order #012  Pelanggan  Kasir  Jam (printed_at_label)  [Tgl bila bukan hari ini]
                            'PINDAH: meja 4 -> 6' (bold) bila kitchen.moved
                            per kategori: '2 x Es Teh Manis' size(0,1)+bold, '(total 3)' bila total_qty != qty, '  > catatan'
                            'BATAL:' (bold)  '1 x Soto'     'UBAH CATATAN:' 'Nasi Goreng: pedas -> tidak pedas'     catatan pesanan
                            penutup 'Kiriman ke-2'; tanpa harga kecuali opts.showPrices ?? cfg.kitchenShowPrices
                            SELALU diakhiri size(0,0), style('normal'), align('left') sebelum cut()
```
Konfigurasi `LakasirReceiptConfig` tambahan: `kitchenShowPrices` (Setting `kitchen_ticket_show_price`, bawaan false) dan label (semua via `__()` kunci bagian 15): bill, provisional, notPaid, notProof, kitchen, newOrder, addition, additionN, cancelled, cancelOnly, voided, changed, changedNote, moved, takeaway, tableWord, billNo, itemDiscount, copy, shipmentN, totalN. Tanpa `innerHTML`/`outerHTML`/`insertAdjacentHTML`/`document.write`.

Bootstrap (`cashier-shell`): pendengar `bill-print` -> `const ok = await LakasirReceipt.print(d.bill,{mode:d.mode,reprint:d.reprint,jobKey:d.job_key}); if (ok && d.ack) Livewire.dispatch('kitchen-ack',{ack:d.ack,mode:'printed'})`; `lakasir-print-failed` -> toast permanen; `bill-preview` -> modal `receipt-preview-modal` isi `<pre id=receipt-preview-text>` dari `LakasirReceipt.preview`; `bill-saved` -> toast >= 8 dtk dengan aksi [Bayar] [Buka] (dan [Kirim ke dapur] bila `sent:false` di fnb).

### 14.9 Migrasi, `SafeMigration`, `pos:preflight` (paket `data-layer`)

```php
namespace App\Support;
final class SafeMigration {
  public static function isSqlite(): bool;
  public static function lockTimeout(int $seconds = 10): void;                       // hanya mysql/mariadb: SET SESSION lock_wait_timeout = N
  public static function retryOnLock(\Closure $fn, int $attempts = 3, int $sleepSeconds = 3): mixed;   // ulang pada errno 1205/1213
  public static function addColumn(string $table, string $column, \Closure $define): void;   // hasColumn guard + lockTimeout + retryOnLock; $define(Blueprint)
  public static function index(string $table, array|string $columns, string $name): void;    // Schema::hasIndex guard + lockTimeout + retry
  public static function unique(string $table, array|string $columns, string $name): void;
  public static function foreign(string $table, string $column, string $refTable, string $onDelete = 'set null', string $refColumn = 'id'): void;  // non-sqlite: Schema::getForeignKeys guard menurut kolom; sqlite: no-op
  public static function dropIndexIfExists(string $table, string $name): void;
  public static function hasOtherIndexLeading(string $table, string $column, string $exceptIndex): bool;
}
```
Pola WAJIB berkas pembuat tabel: `if (! Schema::hasTable(T)) { Schema::create(T, fn => $t->engine='InnoDB'; kolom saja + (bila sqlite) FK inline); }` lalu SELALU `SafeMigration::unique/index/foreign(...)` satu per satu (idempoten; memperbaiki keadaan setengah jadi). `down()` = `Schema::dropIfExists` dengan komentar "dev saja". Migrasi perf: `up()` -> `SafeMigration::index()` empat kali dengan NAMA YANG SAMA (`stocks_pos_lookup_index`, `cart_items_user_product_index`, `products_pos_listing_index`, `products_sku_index`); `down()` -> untuk `stocks` dan `cart_items`: bila `!hasOtherIndexLeading(table, 'product_id'|'user_id', komposit)` buat dulu `stocks_product_id_index` / `cart_items_user_id_index`, baru `dropIndexIfExists`; products langsung. Migrasi `000001` dan `000006` memakai `addColumn`/`index` (timeout + retry).

`php artisan pos:preflight [--pre]` (`App\Console\Commands\PosPreflight`, BACA-SAJA, tidak menulis, exit 0/1):
- `--pre` (SEBELUM migrasi): cetak fakta (DB name, `VERSION()`, `@@system_time_zone`, `@@global.time_zone`, `@@transaction_isolation`, `@@innodb_lock_wait_timeout`, `@@innodb_default_row_format`, `@@sql_mode`, jumlah baris `sellings`/`selling_details`, `CACHE_DRIVER`, engine/row_format tabel yang tidak InnoDB/Dynamic); wajib ada tabel `users, products, stocks, sellings, selling_details, cart_items, migrations`; hitung migrasi PENDING (berkas di `database/migrations` yang tidak ada di tabel `migrations`): HARUS subset dari tujuh berkas yang diizinkan (`2026_10_14_000001` dan `2026_10_15_000001`..`2026_10_15_000006`; yang sudah Ran tidak dihitung); selain itu FAIL dan daftar migrasi tak diizinkan dicetak ("JANGAN jalankan migrate"). Migrasi apa pun SEBELUM `2026_10_15_000001` yang berstatus Pending selain `2026_10_14_000001` = FAIL.
- tanpa `--pre` (SESUDAH): semua tujuh berkas berstatus Ran (000006 hanya WARN bila hilang); tabel/kolom/indeks/FK ada (`hasTable/hasColumn/hasIndex/getForeignKeys`); tabel baru InnoDB; `sellings_date_number_index` hanya WARN bila hilang; kolom `note` ada.

### 14.10 Selector uji (data-testid)

Dari bagian 7.8 plus: `save-bill-only`, `send-kitchen`, `provisional-bill`, `takeaway-prompt`, `takeaway-confirm`, `bill-saved-toast`, `kitchen-print-failed`, `kitchen-print-retry`, `kitchen-mark-sent`, `kitchen-pending-strip`, `line-chip-{id}`, `cancel-sent-dialog`, `cancel-reason`, `cancel-confirm`, `bill-resume-chip`, `bills-search`, `bills-filter-unsent`, `bills-unsent-dot`, `bill-unsent-chip-{id}`, `bill-menu-{id}`, `pay-kitchen-switch`, `pay-stock-override`, `pay-conflict`, `pay-price-stale`.

### 14.11 Baris v2/v3-awal yang BATAL

- `final_contract.txt`: "SellingService::create(array $data): ... Cache::lock('selling-numbering'...)" (BATAL; 14.1); migrasi `2026_10_15_000002_add_listing_indexes_to_products_table` dan indeks `products_category_listing_index`/`products_show_name_index` (BATAL; berkas dihapus); `Selling::creating dan CustomerNameService TIDAK diubah` (BATAL; keduanya memakai BusinessDay).
- plan-v3 7.1 `NumberingLock::ensure(string $date)`/`acquire(string $date)` per hari (BATAL; satu kunci `sales`), `DailyNumber` fallback `OR` (BATAL), 7.3 `OpenBillService` lama dengan `registerCancellation`, `kitchenBatch` yang menulis, `void(?string $reason)`, `pay(...): Selling` (BATAL; 14.5), 7.4 `saveBill` "konteks pindah ke bill baru", `reprintKitchen(bool $all)`, `onOpenBillPay`, "konteks bill TIDAK dipulihkan" (BATAL; 14.6), 7.5 filter `all|mine|stale` (BATAL; `all|mine|unsent|stale`), 7.6 kunci anti-ganda `mode:id` yang menjatuhkan pekerjaan (BATAL; antrean), 7.7 sebagian kunci terjemahan (diganti bagian 15), 6.4-6.5 daftar rilis (diganti bagian 18), 9 paket (diganti bagian 16).


---

## 15. Tabel terjemahan SEMUA string UI baru (EN kunci -> ID); satu pemilik `cashier-shell` (`lang/id.json`, `lang/en.json`; `en.json`: nilai = kunci)

Aturan: kunci yang SUDAH ada di `lang/id.json` mempertahankan nilainya kecuali tercantum di sini (tabel ini menang). `:name`, `:n`, dst. adalah placeholder Laravel. Paket lain TIDAK mengedit `lang/*`; kunci yang kurang dilaporkan ke `cashier-shell`. `LangKeysTest` memindai `__('...')` pada view dan kelas PHP baru. Kosakata: "Bill" (objek), "Bill sementara" (cetak), tidak ada kata "Bon". Tabel menggantikan 7.7.

### 15.1 Bill, dapur, daftar

| EN (kunci) | ID |
|---|---|
| Open bills | Bill terbuka |
| Bills | Bill |
| Save bill | Simpan bill |
| Save only | Simpan saja |
| Send to kitchen | Kirim ke dapur |
| Save and print ticket | simpan + cetak tiket |
| Provisional bill | Bill sementara |
| Bill saved | Bill tersimpan |
| Bill #:number | Bill #:number |
| Bill #:number (:label) saved | Bill #:number (:label) tersimpan |
| Bill #:number saved. NOT sent to the kitchen yet. | Bill #:number disimpan. BELUM dikirim ke dapur. |
| New order | Order baru |
| Unsaved order | Order baru (belum disimpan) |
| Back to new order | Kembali ke order baru |
| Resuming Bill #:number (:label) | Melanjutkan Bill #:number (:label) |
| Open this bill | Buka bill ini |
| Open | Buka |
| Void bill | Batalkan bill |
| Void reason | Alasan pembatalan |
| Bill voided | Bill dibatalkan |
| A reason is required | Alasan wajib diisi |
| Bill already paid | Bill sudah dibayar |
| Bill is closed | Bill sudah ditutup |
| Bill was changed on another device | Bill diubah di perangkat lain |
| Reload bill | Muat ulang bill |
| New total | Total baru |
| Nothing new to send | Tidak ada item baru untuk dikirim |
| Reprint last kitchen ticket | Cetak ulang tiket terakhir |
| Last sent at :time | Terakhir dikirim :time |
| Sent to kitchen | Sudah ke dapur |
| Not sent to kitchen | Belum ke dapur |
| Not sent to kitchen :minutes min | Belum ke dapur :minutes mnt |
| Includes changes from another device | Termasuk perubahan dari perangkat lain |
| From yesterday | Dari kemarin |
| Past midnight | Lewat tengah malam |
| Yesterday | Kemarin |
| All bills | Semua bill |
| My bills | Bill saya |
| Unsent bills | Belum ke dapur |
| No open bills | Tidak ada bill terbuka |
| Search table, name or number | Cari meja, nama, atau nomor |
| Table already has an open bill | Meja ini sudah punya bill terbuka |
| Open that bill | Buka bill itu |
| Age | Usia |
| :minutes min | :minutes mnt |
| Preview | Pratinjau |
| Print preview | Pratinjau cetak |
| Add the bill first | Tambahkan item dulu |
| The bill may already exist, check the bills list | Bill mungkin sudah tersimpan, cek daftar bill |
| Bill not found | Bill tidak ditemukan |
| Unsent changes | Perubahan belum dikirim |
| Open bill (:label) | Bill terbuka (:label) |
| Pay bill | Bayar bill |
| Print provisional bill | Cetak bill sementara |
| Move or merge tables? Do not void the bill: change the table instead (the kitchen receives MOVED). | Mau pindah meja? Jangan dibatalkan: ubah mejanya saja (dapur menerima PINDAH). |
| Only the owner or a manager can cancel items already sent to the kitchen | Hanya pemilik atau manajer yang dapat membatalkan item yang sudah dikirim ke dapur |
| You are not allowed to do this | Anda tidak berhak melakukan ini |
| Table or name is empty. The ticket will read TAKEAWAY #:number. | Meja atau nama belum diisi. Tiket akan tertulis BAWA PULANG #:number. |
| Fill the table | Isi meja |
| Send as takeaway | Kirim sebagai bawa pulang |
| This product is already in the bill with another unit | Produk ini sudah ada di bill dengan satuan lain |
| Left :left after open bills | Tersisa :left setelah bill terbuka |
| Bill list could not be loaded | Daftar bill tidak dapat dimuat |
| Bills are unavailable until the update is finished | Fitur bill belum tersedia sampai pembaruan selesai |

### 15.2 Item bill, pembatalan item terkirim

| EN (kunci) | ID |
|---|---|
| New | Baru |
| Sent :sent, new :new | Terkirim :sent - baru :new |
| Sent | Terkirim |
| Cancelled :qty | Batal :qty |
| Note changed | Catatan berubah |
| Cancel sent item | Batalkan item yang sudah dikirim |
| Remove :name? The kitchen already received :qty. The kitchen is only told when you press Send to kitchen. | Hapus :name? Dapur sudah menerima :qty. Dapur baru diberi tahu saat Anda menekan Kirim ke dapur. |
| Remove | Hapus |
| Back | Kembali |
| Undo | Urungkan |
| :count cancellations not yet told to the kitchen | Ada :count pembatalan yang belum diberitahukan ke dapur |
| Wrong input | Salah input |
| Customer cancelled | Pelanggan batal |
| Out of stock | Stok habis |
| Other | Lainnya |
| Write the reason | Tulis alasan |

### 15.3 Cetak (dipakai `LakasirReceiptConfig.labels` dan `OpenBillPresenter`)

| EN (kunci) | ID |
|---|---|
| NOT PAID | BELUM DIBAYAR |
| NOT A PROOF OF PAYMENT | BUKAN BUKTI PEMBAYARAN |
| PROVISIONAL BILL | BILL SEMENTARA |
| KITCHEN | DAPUR |
| NEW ORDER | PESANAN BARU |
| ADDITION - SHIPMENT :n | TAMBAHAN - KIRIMAN KE-:n |
| CANCELLATION | PEMBATALAN |
| BILL VOIDED | BILL DIBATALKAN |
| CANCELLED | BATAL |
| CHANGED NOTE | UBAH CATATAN |
| MOVED: table :from -> :to | PINDAH: meja :from -> :to |
| TAKEAWAY #:number | BAWA PULANG #:number |
| TABLE :number | MEJA :number |
| Shipment :n | Kiriman ke-:n |
| (total :n) | (total :n) |
| COPY | SALINAN |
| Opened :time | Dibuka :time |
| Thank you | Terima kasih |
| Show prices on the kitchen ticket | Tampilkan harga di tiket dapur |
| Kitchen ticket not printed. Check the printer, then try again. | Tiket dapur belum tercetak. Cek printer, lalu Coba lagi. |
| Try again | Coba lagi |
| Mark as sent without printing | Tandai terkirim tanpa cetak |
| Printing is busy, please wait | Printer sedang bekerja, mohon tunggu |

### 15.4 Pembayaran

| EN (kunci) | ID |
|---|---|
| Payment | Pembayaran |
| Amount due | Total tagihan |
| Cash received | Uang diterima |
| Exact amount | Uang pas |
| Short by | Kurang |
| Confirm payment received | Konfirmasi pembayaran diterima |
| Due date | Jatuh tempo |
| Down payment | Uang muka |
| Receivable remaining | Sisa piutang |
| Member is required for credit | Member wajib untuk kredit |
| Enter the received amount | Masukkan uang yang diterima |
| Payment failed | Pembayaran gagal |
| Cash drawer is not opened | Laci kas belum dibuka |
| Order items | Rincian pesanan |
| Show items | Lihat rincian |
| Hide items | Sembunyikan rincian |
| Exact payment | Pembayaran pas |
| Clear | Hapus |
| Processing... | Memproses... |
| Print kitchen ticket now (:n unsent changes) | Cetak tiket dapur sekarang (:n perubahan belum dikirim) |
| :n changes will NOT be told to the kitchen | :n perubahan TIDAK akan diberitahukan ke dapur |
| Kitchen ticket printed too | Tiket dapur ikut dicetak |
| The bill changed while paying. New total :amount. | Bill berubah saat pembayaran. Total baru :amount. |
| Continue even though stock is short (stock goes negative) | Lanjutkan walau stok kurang (stok akan minus) |
| The price changed since the item was ordered | Harga berubah sejak item dipesan |
| Down payment cannot exceed the total | Uang muka tidak boleh melebihi total |
| The received money must be at least the total | Uang diterima harus minimal sebesar total |
| Payment is being processed | Pembayaran sedang diproses |
| Payment already recorded | Pembayaran sudah tercatat |
| Payment method not found | Metode pembayaran tidak ditemukan |
| Stock of :name is not enough (left :left) | Stok :name tidak cukup (tersisa :left) |
| Product :name was deleted | Produk :name sudah dihapus; hapus barisnya dari bill |
| The system is busy, please try again | Sistem sedang sibuk, coba lagi |
| The cart changed, review it and pay again | Keranjang berubah, periksa lagi lalu bayar ulang |
| Voucher is no longer available | Voucher sudah tidak tersedia |

### 15.5 Yang tetap dari plan.md 11.4 (tidak diulang)

Kunci dari plan.md 11.4 (chip kategori, tampilan kotak/daftar, catatan item, nama pelanggan, dock keranjang, Urungkan, dst.) tetap, KECUALI: "Provisional bill"/"NOT PAID"/"Close bill"/"Save as new bill"/"Remove item already sent to kitchen?"/"Reprint kitchen ticket" dari plan-v3 7.7 DIBATALKAN dan diganti baris di atas.


---

## 16. PAKET KERJA FINAL v3 (menggantikan bagian 9 dan plan.md 11.5): 5 gelombang, 16 paket, `owner_files` saling lepas

Berkas padanan: `final_packages_v3.json` (sumber kebenaran; bagian ini dihasilkan darinya). Disjoint diperiksa otomatis: 125 berkas, tidak ada yang dimiliki dua paket. Aturan umum: setiap paket mengedit HANYA berkas yang dimilikinya; kontrak bagian 14 adalah satu-satunya antarmuka; migrasi hanya DITULIS (paket `verify` yang menjalankan); jangan menjalankan pest bersamaan dengan proses lain yang memakai `database/testing.sqlite`. Kerja tidak di-commit antar gelombang kecuali orkestrator memutuskan.

| Gelombang | Paket (paralel dalam gelombang) | Alasan urutan |
|---|---|---|
| 1 | `data-layer`, `domain-support`, `numbering`, `receipt-print`, `design` | tidak saling bergantung selain lewat kontrak 14 (nama kelas cukup) |
| 2 | `open-bill-backend`, `open-bills-component`, `cashier-livewire`, `tests-core` | membaca kode NYATA wave 1 |
| 3 | `product-view`, `cashier-shell`, `open-bill-view`, `payment-view`, `tests-bills` | membaca kode wave 2 + mock v4 |
| 4 | `tests-cashier` | butuh semua view |
| 5 | `verify` (lalu code-reviewer, test-runner, uiux-analyst, debugger sesuai alur tim) | satu-satunya yang menjalankan migrasi dan pest |

Perubahan paket dari v3-awal: `data-layer` dipecah menjadi `data-layer` (skema, model, gerbang deploy, dokumen) dan `domain-support` (uang, total, stok, izin); `open-bills-component` dipisah dari `open-bill-backend` (service paling kritis diberi review sendiri); `tests-core` mendapat tes uang/stok/preflight; `tests-bills` mendapat tes event dan kunci.

### 16.1 Pekerjaan wave-1 yang belum ter-commit: DIPERTAHANKAN / DIKERJAKAN ULANG (menggantikan bagian 8)

| Berkas | Keputusan v3 final | Pemilik |
|---|---|---|
| `Concerns/HasOrderLine`, `PlainText`, `OrderLineSorter` | DIPERTAHANKAN; sorter diperluas duck-typing `OpenBillItem` | domain-support |
| `CashierDraftService` | DIPERTAHANKAN, dipersempit: hanya pesanan BARU yang belum disimpan | domain-support |
| `CartItem`, `SellingDetail`, `Product` (escapeLike, searchInPos, inCategory, isStockUnlimited) | DIPERTAHANKAN | domain-support |
| `TransactionSellingStoreRequest`, `SellingDetailCollection`, `SellingController` | DIPERTAHANKAN (+ satu baris `verify_stock` di store) | domain-support |
| `Selling.php` (`loadForReceipt`, `notePlain`) | DIPERTAHANKAN; `creating`, flag transien DIKERJAKAN ULANG | numbering |
| `SellingService.php` | DIKERJAKAN ULANG (hapus `Cache::lock`; kunci `sales`, hook, verify_stock, voucher atomik) | numbering |
| `SellingDetailsRelationManager`, `ViewSelling`, `view-selling.blade.php` | DIPERTAHANKAN (verifikasi browser di `verify`) | receipt-print |
| `receipt-print.blade.php` (v1) | DIPERTAHANKAN sebagai dasar, DIPERLUAS ke v2 (antrean, mode, preview) | receipt-print |
| `ProductBrowser.php`, `CartItemNote.php` | DIPERTAHANKAN + diperluas (committed, context/billId) | cashier-livewire |
| `CartInteraction.php`, `Cashier.php` | logika DIPERTAHANKAN (restoreDraft, commitCustomerName, syncCartForPayment, restoreCartItem, resetOrder); `proceedThePayment` DIKERJAKAN ULANG; bill/dapur DITAMBAH | cashier-livewire |
| `TableProduct.php` | tetap DIHAPUS | cashier-livewire (sudah terhapus) |
| Migrasi `2026_10_15_000001` (note) | niat DIPERTAHANKAN, isi DITULIS ULANG dengan `SafeMigration` | data-layer |
| Migrasi `2026_10_15_000002_add_listing_indexes_to_products_table` | DIHAPUS; nomor dipakai `create_open_bills_table` | data-layer |
| Migrasi `2026_10_14_000001` (ter-commit) | DITULIS ULANG di tempat | data-layer |
| View kasir, bootstrap, lang, tes | BELUM DIMULAI (wave 3-4) | lihat paket |

Prasyarat wave 2: jalankan `php -l` pada semua berkas wave-1 yang dipertahankan dan hapus referensi ke `Cache::lock` dan `TableProduct`; `git status` harus hanya memuat berkas milik paket wave 1.

### 16.2 Rincian paket


### Gelombang 1

**data-layer** (gelombang 1)

owner_files:
- `database/migrations/2026_10_14_000001_add_pos_performance_indexes.php`
- `database/migrations/2026_10_15_000001_add_note_to_cart_items_and_selling_details_tables.php`
- `database/migrations/2026_10_15_000002_add_listing_indexes_to_products_table.php (DELETE)`
- `database/migrations/2026_10_15_000002_create_open_bills_table.php`
- `database/migrations/2026_10_15_000003_create_open_bill_items_table.php`
- `database/migrations/2026_10_15_000004_create_open_bill_events_table.php`
- `database/migrations/2026_10_15_000005_create_numbering_locks_table.php`
- `database/migrations/2026_10_15_000006_add_date_index_to_sellings_table.php`
- `app/Support/SafeMigration.php`
- `app/Console/Commands/PosPreflight.php`
- `app/Enums/OpenBillStatus.php`
- `app/Models/Tenants/OpenBill.php`
- `app/Models/Tenants/OpenBillItem.php`
- `app/Models/Tenants/OpenBillEvent.php`
- `docs/deploy-open-bill.md`

tugas: WAVE 1 - skema MySQL-aman, model, gerbang deploy, dokumen rollout. Kontrak = plan-v3.md bagian 14 (menang atas bagian 7 dan plan.md 11.3), terjemahan = bagian 15, alasan keputusan = bagian 13. Pekerjaan REPO: edit HANYA owner_files paket ini; jangan menjalankan migrasi/pest/artisan yang menulis ke database kecuali paket `verify`. PHP: $env:Path="$env:USERPROFILE\.config\herd\bin;$env:Path" lalu php -l pada setiap berkas PHP. PHP 8.4, tipe parameter dan return wajib, `$guarded=['id']` untuk model baru.
TULIS migrasi saja; JANGAN menjalankannya ke database mana pun (paket verify yang menjalankan). Semua per plan-v3 14.3 dan 14.9.
(1) app/Support/SafeMigration.php persis API 14.9: isSqlite, lockTimeout (SET SESSION lock_wait_timeout hanya pada mysql/mariadb), retryOnLock (ulang pada errno 1205/1213, 3x, tidur 3 dtk), addColumn, index, unique (guard Schema::hasIndex), foreign (non-sqlite: guard Schema::getForeignKeys menurut kolom; sqlite no-op), dropIndexIfExists, hasOtherIndexLeading. Semua operasi ALTER pada tabel berisi data memakai lockTimeout + retryOnLock.
(2) TULIS ULANG di tempat 2026_10_14_000001: up() memakai SafeMigration::index untuk keempat indeks dengan NAMA YANG SAMA (stocks_pos_lookup_index, cart_items_user_product_index, products_pos_listing_index, products_sku_index); down() idempoten: untuk stocks dan cart_items pastikan indeks FK pengganti (stocks_product_id_index pada product_id, cart_items_user_id_index pada user_id) ada SEBELUM membuang indeks komposit (MySQL error 1553), pakai dropIndexIfExists. KERJAKAN ULANG 2026_10_15_000001 (kolom note string(255) NULL, tanpa after) dengan SafeMigration::addColumn.
(3) HAPUS 2026_10_15_000002_add_listing_indexes_to_products_table.php (belum diterapkan di mana pun, belum ter-commit).
(4) BUAT 000002 open_bills, 000003 open_bill_items, 000004 open_bill_events, 000005 numbering_locks (kolom name varchar(32) PRIMARY KEY), 000006 sellings_date_number_index (date, daily_order_number). Skema EKSAK dari 14.3: tipe kolom, DEFAULT konstan pada setiap NOT NULL, status varchar(12) (bukan ENUM), tax_percent decimal(6,3), last_kitchen_batch dan pending_cancel mediumText, $table->engine='InnoDB', nama indeks eksplisit <= 64 karakter, TANPA ->after(), ->change(), DB::table(...)->insert/update. Pola WAJIB (13.3 M-A): Schema::hasTable guard -> Schema::create hanya kolom + PK (FK inline hanya bila SafeMigration::isSqlite()) -> LALU SafeMigration::unique/index/foreign satu per satu (idempoten; memperbaiki keadaan setengah jadi pada MySQL). down() = dropIfExists + komentar "dev saja, jangan di produksi".
(5) Enum OpenBillStatus; model OpenBill, OpenBillItem (HasOrderLine, product() memakai withTrashed), OpenBillEvent (const UPDATED_AT=null, konstanta action, static record()) sesuai 14.4; label()/formattedNumber() per kontrak.
(6) app/Console/Commands/PosPreflight.php: `pos:preflight [--pre]` BACA-SAJA per 14.9 (fakta DB, gerbang pending-subset-tujuh-berkas, cek tabel/kolom/indeks/FK, exit code 0/1). Hitung pending dari berkas database/migrations vs tabel migrations lewat app('migrator') tanpa menjalankan apa pun.
(7) docs/deploy-open-bill.md (Indonesia, untuk Randy; bagian 18 plan-v3 adalah isinya): langkah 0-9, 4 perintah SQL fakta, SQL baca-saja daftar bill terbuka, perintah yang DILARANG, tabel "migrasi X gagal: keadaan apa, aman diulang?", catatan hari pertama penomoran (DA-I9), aturan laci kas (bill masuk laci saat bayar, atas nama pembayar), bill lintas tengah malam, kebijakan harga/pajak bill, catatan dasbor dan restore zona (M7, M9), produk jangan dihapus permanen saat ada bill terbuka, kill-switch open_bill_enabled dan rollback (bayar/batalkan bill dulu).
Jangan menyentuh berkas milik paket lain. php -l semua berkas.

**domain-support** (gelombang 1)

owner_files:
- `app/Support/Money.php`
- `app/Services/Tenants/OrderTotals.php`
- `app/Services/Tenants/OrderLinePricing.php`
- `app/Services/Tenants/OrderLineSorter.php`
- `app/Services/Tenants/PaymentPlanner.php`
- `app/Services/Tenants/StockGuard.php`
- `app/Services/Tenants/OpenBillGate.php`
- `app/Services/Tenants/ReceivableService.php`
- `app/Services/Tenants/PlainText.php`
- `app/Services/Tenants/CashierDraftService.php`
- `app/Models/Tenants/Concerns/HasOrderLine.php`
- `app/Models/Tenants/PaymentMethod.php`
- `app/Models/Tenants/CartItem.php`
- `app/Models/Tenants/SellingDetail.php`
- `app/Models/Tenants/Product.php`
- `app/Rules/CheckProductStock.php`
- `app/Http/Requests/Tenants/Sellings/TransactionSellingStoreRequest.php`
- `app/Http/Resources/SellingDetailCollection.php`
- `app/Http/Controllers/Api/Tenants/Transaction/SellingController.php`

tugas: WAVE 1 - uang, total, stok, izin, jenis metode (plan-v3 14.2). Kontrak = plan-v3.md bagian 14 (menang atas bagian 7 dan plan.md 11.3), terjemahan = bagian 15, alasan keputusan = bagian 13. Pekerjaan REPO: edit HANYA owner_files paket ini; jangan menjalankan migrasi/pest/artisan yang menulis ke database kecuali paket `verify`. PHP: $env:Path="$env:USERPROFILE\.config\herd\bin;$env:Path" lalu php -l pada setiap berkas PHP. PHP 8.4, tipe parameter dan return wajib, `$guarded=['id']` untuk model baru.
(1) Money (round/parse/gte/eq/qty/qtyEq). (2) OrderTotals persis 14.2 (lineTotal dengan satuan-harga, unitPrice, unitIsExact, compute dengan total_price dan amount_due yang dibulatkan Money::round; kompatibel dengan matematika lama Cashier::calculateTotalPrice dan mapProductRequest: pajak dari subtotal sebelum diskon). (3) OrderLinePricing::repriceOnQtyChange. (4) PaymentPlanner::plan: KREDIT dulu (member_id dan due_date wajib; payed_money = clamp(parse, 0, amountDue); money_changes 0), exact (payed_money = amountDue, abaikan angka klien), cash (kosong/0 -> amountDue; Money::gte wajib; kembalian dibulatkan); ValidationException berkunci payed_money/member_id/due_date dengan pesan terjemahan. (5) StockGuard::assert persis 14.2 (agregasi per produk, withTrashed + lockForUpdate, override, kunci products.{i}.qty, pesan menyebut nama). (6) OpenBillGate (canUse, canVoid, canCancelSent dengan jendela pos.cancel_sent_grace_minutes, canOverrideStock). (7) PaymentMethod::kind() credit > cash > exact dari flag, tidak pernah dari nama. (8) ReceivableService: total_receivable = jumlah sesudah diskon (13.3 M-B) dan rest_receivable dengan Money::round; hanya perilaku piutang BARU. (9) CheckProductStock: pesan menyebut nama produk dan sisa stok; kunci dan aturan tidak berubah. (10) OrderLineSorter menerima CartItem|OpenBillItem|SellingDetail tanpa query. (11) TransactionSellingStoreRequest::store memakai create($data, ['verify_stock'=>true]); API tetap aditif (note). (12) PERTAHANKAN HasOrderLine, PlainText, CashierDraftService (komentar: hanya pesanan baru yang belum disimpan), CartItem/SellingDetail/Product (escapeLike, searchInPos, inCategory, isStockUnlimited), SellingDetailCollection, SellingController apa adanya. Jangan menyentuh Selling.php, SellingService.php (milik numbering).

**numbering** (gelombang 1)

owner_files:
- `config/pos.php`
- `app/Support/BusinessDay.php`
- `app/Services/Tenants/NumberingLock.php`
- `app/Services/Tenants/DailyNumber.php`
- `app/Services/Tenants/CustomerNameService.php`
- `app/Services/Tenants/SellingService.php`
- `app/Models/Tenants/Selling.php`
- `app/Observers/SellingObserver.php`
- `app/Listeners/AssignProduct.php`
- `app/Services/VoucherService.php`
- `app/Filament/Tenant/Resources/SellingResource/Widgets/SellingOverview.php`
- `app/Exceptions/SaleBusyException.php`
- `.env.example`
- `.env.herd.example`

tugas: WAVE 1 - hari bisnis, satu kunci penjualan, SellingService (plan-v3 14.0 R1-R2, R5 dan 14.1). Kontrak = plan-v3.md bagian 14 (menang atas bagian 7 dan plan.md 11.3), terjemahan = bagian 15, alasan keputusan = bagian 13. Pekerjaan REPO: edit HANYA owner_files paket ini; jangan menjalankan migrasi/pest/artisan yang menulis ke database kecuali paket `verify`. PHP: $env:Path="$env:USERPROFILE\.config\herd\bin;$env:Path" lalu php -l pada setiap berkas PHP. PHP 8.4, tipe parameter dan return wajib, `$guarded=['id']` untuk model baru.
config/app.php TIDAK DIUBAH (timezone tetap UTC).
(1) config/pos.php persis 14.1; dokumentasikan POS_BUSINESS_TIMEZONE di kedua .env contoh. (2) BusinessDay persis 14.1 (timezone divalidasi terhadap timezone_identifiers_list dengan fallback Asia/Jakarta; normalize; dateOf; startUtc/endUtc/range; scopeDay mengikat string 'Y-m-d H:i:s' UTC; label). (3) NumberingLock: SALES='sales'; ensure() = SELECT biasa dulu, insertOrIgnore HANYA bila baris belum ada, hasil positif di-cache statis per proses, tabel tidak ada -> no-op + SATU Log::warning (jangan cache hasil negatif); acquire() = lockForUpdate select baris 'sales' di dalam transaksi, buat bila hilang, no-op bila tabel tidak ada. (4) DailyNumber::next: query rentang scopeDay('date'); bila null -> fallback hanya baris date IS NULL dengan scopeDay('created_at'); plus max(open_bills.number) pada business_date dalam try/catch dengan hasTable di-cache positif; +1. (5) CustomerNameService: tanda tangan 14.1; hitung dari sellings (rentang) DAN open_bills status open; perilaku lama yang dikunci tes tidak berubah. (6) SellingService::create(array $data, array $hooks=[]) PERSIS urutan 14.1 (normalize date; ensure; loop 2x: beginTransaction; acquire() sebagai pernyataan pertama; before; StockGuard bila verify_stock; new Selling + flag transien + fill + save; SellingCreated; RecalculateEvent; konsumsi voucher atomik; after; commit); catch QueryException errno 1213/1205 -> rollBack + ulang 1x, lalu SaleBusyException; pengecualian lain rollBack dan lempar ulang. mapProductRequest: Money::round, TIDAK memanggil reduceUsed, simpan id voucher di $this->voucherToConsume (dibersihkan di create). Tidak ada Cache::lock. Komentar yang menjelaskan alasan urutan (MySQL REPEATABLE READ). (7) Selling: properti transien numberingLocked/keepCustomerName; creating() sebagai cadangan (acquire hanya bila DB::transactionLevel()>0 dan !numberingLocked; hari = BusinessDay::dateOf(date ?? created_at); nomor lewat DailyNumber bila kosong; nama lewat CustomerNameService kecuali keepCustomerName); pertahankan loadForReceipt/notePlain. (8) SellingObserver: Selling::query()->count(); laci kas null-aman -> ValidationException 'Cash drawer is not opened'. (9) AssignProduct: buang kunci note bila blank (alasan DA-C1). (10) VoucherService::applyable memakai Carbon::parse(BusinessDay::today()). (11) SellingOverview::getSalesToday memakai BusinessDay::range(). (12) SaleBusyException (render JSON 503 untuk request JSON). Jangan menyentuh berkas paket lain.

**receipt-print** (gelombang 1)

owner_files:
- `resources/views/filament/tenant/partials/receipt-print.blade.php`
- `resources/views/filament/tenant/resources/sellings/pages/view-selling.blade.php`
- `app/Filament/Tenant/Resources/SellingResource/Pages/ViewSelling.php`
- `app/Filament/Tenant/Resources/SellingDetailResource/RelationManagers/SellingDetailsRelationManager.php`
- `app/Filament/Tenant/Pages/Printer.php`

tugas: WAVE 1 - partial cetak bersama v2 (plan-v3 14.8; keputusan 2(C)(D); KUX-C1/I6/I13). Kontrak = plan-v3.md bagian 14 (menang atas bagian 7 dan plan.md 11.3), terjemahan = bagian 15, alasan keputusan = bagian 13. Pekerjaan REPO: edit HANYA owner_files paket ini; jangan menjalankan migrasi/pest/artisan yang menulis ke database kecuali paket `verify`. PHP: $env:Path="$env:USERPROFILE\.config\herd\bin;$env:Path" lalu php -l pada setiap berkas PHP. PHP 8.4, tipe parameter dan return wajib, `$guarded=['id']` untuk model baru. Jangan mengedit resources/js/*, public/js/*, lang/*.
Pertahankan API v1 dan aturan keamanan (tanpa innerHTML/outerHTML/insertAdjacentHTML/document.write; DOMParser inert; toPrintable; wrapText).
(1) ANTREAN cetak: print() = rantai Promise serial; sebelum tiap pekerjaan tunggu window._lakasirIsPrintingNow bersih (cek 100 ms, maks 8 dtk); pagar ganda berupa peta jobKey -> Promise selama 3 dtk (kunci opts.jobKey ?? mode:payload.id ?? daily_order_number; panggilan kedua mengembalikan Promise yang sama); TIDAK pernah menjatuhkan pekerjaan; resolve(false) + window.dispatchEvent(new CustomEvent('lakasir-print-failed',{detail:{mode,jobKey,payload,ack:opts.ack,reason}})) pada kegagalan (printer tak ada, ditolak, timeout); resolve(true) hanya bila Printer.print() benar-benar dijalankan tanpa exception (perbaiki printed=true palsu). (2) build(printer,payload,{mode,reprint,printerData,showPrices}) dengan pembangun bersama + tabel per mode sesuai tata letak 14.8: receipt (tata letak sekarang + baris item dua-baris), bill ('BILL SEMENTARA', 'BELUM DIBAYAR', Order #nomor + label tanggal, Subtotal/Pajak/Diskon/TOTAL=amount_due, 'BUKAN BUKTI PEMBAYARAN', tanpa baris bayar/kembali), kitchen (SALINAN atas+bawah bila reprint; baris besar MEJA n / BAWA PULANG #nomor / nama dengan size(1,1) bold maks 16 kolom efektif; judul DAPUR - PESANAN BARU | TAMBAHAN - KIRIMAN KE-n | PEMBATALAN | BILL DIBATALKAN (+alasan); Order #, pelanggan, kasir, jam printed_at_label, tanggal bila bukan hari ini; 'PINDAH: meja a -> b' tebal; per kategori 'qty x nama' size(0,1)+bold, '(total n)' bila total_qty != qty, catatan '  > ...'; BATAL: dan UBAH CATATAN:; catatan pesanan; 'Kiriman ke-n'; tanpa harga kecuali showPrices ?? cfg.kitchenShowPrices; SELALU diakhiri size(0,0), style('normal'), align left sebelum cut()). (3) Tata letak item: nama dibungkus; 'qty x satuan' kiri + total kanan, ditumpuk bila kiri+kanan > lebar; awalan '~' pada satuan bila round(unit*qty)!=total (total adalah otoritas); '  Diskon (x)'; '  > catatan'. Harga satuan = price/qty dengan price = total baris. (4) TextPrinter + LakasirReceipt.preview(payload,opts): string (build yang sama, 32/48 kolom, mengikuti lebar printer tersimpan). (5) version: 2; LakasirReceiptConfig menambah kitchenShowPrices dan semua label bagian 14.8/15.3 via __() (hanya kunci tabel 15.3). (6) Printer.php: Toggle kitchen_ticket_show_price + Setting::set mengikuti pola show_currency. (7) view-selling: pastikan kolom harga satuan tetap tampil dan tombol memakai LakasirReceipt.print; tidak ada referensi bill. (8) Uji fungsi murni dengan Node vm + printer palsu (JANGAN menulis di tests/: tests-cashier yang memiliki); simpan keluaran contoh 58 dan 80 mm (receipt, bill, kitchen new/addition/cancel-only/void/reprint, 300 karakter tanpa spasi, ESC/GS dalam catatan, antrean 100 ms antar dua pekerjaan keduanya sampai ke printer, jobKey berbeda dalam 3 dtk tetap tercetak) di scratchpad.

**design** (gelombang 1)

owner_files:
- `scratchpad/kasir-mock-v4.html (hanya scratchpad, bukan repo)`

tugas: WAVE 1 - mock v4 untuk Randy (UI Indonesia), turunan kasir-mock-v3.html, MEMAKAI SEMANTIK rencana (KUX-I1): pesanan baru = keranjang pribadi sampai disimpan; bill = konteks yang dibuka; bukan bill otomatis pada item pertama. Kosakata bagian 15 (Bill, Bill sementara, BELUM DIBAYAR, tanpa kata 'Bon'). Tulis hanya di scratchpad.
Tunjukkan: (1) panel Order baru vs panel bill dengan chip konteks kontras tinggi ('Bill #12 - Meja 5 [Kembali ke order baru]'), chip 'Order baru (belum disimpan)' berisi jumlah item saat bill terbuka, chip pemulihan 'Melanjutkan Bill #12'. (2) Bilah aksi fnb: [Kirim ke dapur] (sub-label 'simpan + cetak tiket') memimpin, [Simpan saja] sekunder, [Bill sementara], [Bayar]; retail: satu [Simpan bill]. (3) Setelah Simpan/Kirim layar kembali ke order baru kosong + toast >= 8 dtk 'Bill #12 (Meja 5) tersimpan' [Bayar] [Buka] (dan 'BELUM dikirim ke dapur' [Kirim ke dapur] untuk Simpan saja). (4) Prompt bawa pulang; ubin meja langsung berlaku; chip meja di header. (5) Daftar bill: pencarian, filter Semua/Bill saya/Belum ke dapur/Dari kemarin, baris dua-baris ringkas + satu aksi utama + menu (Bill sementara, Pratinjau, Cetak ulang tiket terakhir, Batalkan), chip 'Belum ke dapur' kuning -> merah setelah 3 mnt, titik kuning berhitung di bills-toggle, bill belum terkirim di atas, 30 bill (bukti scroll), label 'Lewat tengah malam'/'Dari kemarin', label tanggal di samping nomor. (6) Chip per baris (Baru, Terkirim 2 - baru 1, Batal 1, Catatan berubah), dialog batalkan item terkirim (alasan: Salah input/Pelanggan batal/Stok habis/Lainnya + teks; gerbang izin; teks 'Dapur baru diberi tahu saat Anda menekan Kirim ke dapur'), strip permanen 'Ada n pembatalan yang belum diberitahukan ke dapur', Urungkan 6 dtk dengan netting. (7) Dialog konflik: hanya [Muat ulang] + total baru. (8) Dialog void dengan alasan wajib + petunjuk 'Mau pindah meja? ...'. (9) Modal bayar tiga jenis (cash numpad/Uang pas/saran satu baris di 320/kembalian; exact; credit dengan member, jatuh tempo, uang muka, sisa piutang), saklar 'Cetak tiket dapur sekarang (n perubahan belum dikirim)' bawaan ON dan teks peringatan saat OFF, panel override stok, petunjuk harga berubah, konflik versi saat bayar, status memproses, ringkasan terlipat < 400 px, modal sukses 'Tiket dapur ikut dicetak'. (10) Toast gagal cetak permanen 'Tiket dapur belum tercetak...' [Coba lagi] [Tandai terkirim tanpa cetak]. (11) Pratinjau cetak + sampel monospace Struk, Bill sementara, Tiket dapur (baru, tambahan, pembatalan, void, PINDAH, salinan, bawa pulang) pada 58 dan 80 mm dengan 'qty x satuan = total' per baris. Viewport 320/375/768/1280, terang dan gelap, tanpa overflow horizontal, target 44 px (pengecualian terdokumentasi), aksen #c2410c untuk tombol teks putih, dialog a11y (role=dialog, aria-modal, focus trap, Esc, role=status/alert). Tulis design-notes-v4.md (ringkas: apa yang berubah dari v3 dan mengapa) di scratchpad. Ulangi pemeriksaan overflow v3 dan laporkan.


### Gelombang 2

**open-bill-backend** (gelombang 2)

owner_files:
- `app/Services/Tenants/OpenBillService.php`
- `app/Services/Tenants/OpenBillPresenter.php`
- `app/Services/Tenants/PayResult.php`
- `app/Exceptions/OpenBill/BillConflictException.php`
- `app/Exceptions/OpenBill/BillClosedException.php`
- `app/Exceptions/OpenBill/BillEmptyException.php`
- `app/Exceptions/OpenBill/BillForbiddenException.php`
- `app/Exceptions/OpenBill/BillReasonRequiredException.php`

tugas: WAVE 2 (setelah wave 1; baca kode NYATA wave 1) - paket paling kritis, ditulis untuk review ganda. Implementasi persis plan-v3 14.5 dan R1-R8 di 14.0. Kontrak = plan-v3.md bagian 14 (menang atas bagian 7 dan plan.md 11.3), terjemahan = bagian 15, alasan keputusan = bagian 13. Pekerjaan REPO: edit HANYA owner_files paket ini; jangan menjalankan migrasi/pest/artisan yang menulis ke database kecuali paket `verify`. PHP: $env:Path="$env:USERPROFILE\.config\herd\bin;$env:Path" lalu php -l pada setiap berkas PHP. PHP 8.4, tipe parameter dan return wajib, `$guarded=['id']` untuk model baru.
Aturan keras: (a) mutate() = DB transaksi + lockForUpdate pada baris open_bills + status harus open (selain itu BillClosedException) + closure + version++ ; opts sales_lock=true memanggil NumberingLock::ensure (di luar tx) -> tx -> acquire() PERTAMA -> kunci bill; ulang 1x pada 1213/1205. (b) createFromCart: urutan R1; DailyNumber::next(BusinessDay::today()); nama final via CustomerNameService (menghitung bill open); tax_percent = Setting default_tax; pindahkan CartItem milik $by (gabung product_id ganda); hapus keranjang pribadi; event created; BillEmptyException; ulang 1x pada SQLSTATE 23000. (c) addLine/setLineQty/setLineNote/setLineDiscount/restoreLine: hanya kunci bill (tanpa expectedVersion), harga lewat OrderLinePricing, netting pending_cancel, gerbang + alasan wajib untuk reduksi di bawah sent_qty dan hapus baris terkirim (gerbang dievaluasi pada baris TERKUNCI), sent_qty TIDAK diturunkan saat reduksi, event cancel_sent/line_removed. (d) kitchenBatch MURNI (tanpa tulis) dengan format 14.5 termasuk moved dan ack; ackKitchenBatch idempoten; Money::qty untuk semua pembanding. (e) updateHeader: expectedVersion wajib; perubahan customer_name memakai mutate(sales_lock:true). (f) void: alasan wajib; gerbang terhadap baris terkunci; payload tiket void. (g) pay(): HANYA field pembayaran dari klien; lines/diskon/pajak dari bill; friend_price=false; PaymentPlanner; $date dibuat SEKALI lewat BusinessDay::normalize(null) dan semua turunan dari nilai itu; SellingService::create dengan hooks before (kunci bill + verifikasi status/versi/tanda tangan baris/laci kas) dan after (tandai paid + event) dan verify_stock + override resmi; TANPA transaksi luar; bill paid -> PayResult(alreadyPaid); metode tidak ada -> ValidationException; $by harus == auth user. (h) listOpen(User $viewer) = 3 query konstan; OpenBillPresenter::payload/row persis 14.5 (zona bisnis, stale, crossed_midnight, unsent, can_*). Tulis juga komentar singkat urutan kunci di bagian atas OpenBillService. Jangan menyentuh Cashier.php, CartInteraction.php, model, atau migrasi.

**open-bills-component** (gelombang 2)

owner_files:
- `app/Livewire/Cashier/OpenBills.php`

tugas: WAVE 2 - komponen Livewire daftar bill persis plan-v3 14.7 (view livewire.cashier.open-bills ditulis paket open-bill-view; JANGAN membuat view). Kontrak = plan-v3.md bagian 14 (menang atas bagian 7 dan plan.md 11.3), terjemahan = bagian 15, alasan keputusan = bagian 13. Pekerjaan REPO: edit HANYA owner_files paket ini; jangan menjalankan migrasi/pest/artisan yang menulis ke database kecuali paket `verify`. PHP: $env:Path="$env:USERPROFILE\.config\herd\bin;$env:Path" lalu php -l pada setiap berkas PHP. PHP 8.4, tipe parameter dan return wajib, `$guarded=['id']` untuk model baru.
Setiap method publik (dan jalur render yang dipakai wire:poll) memulai dengan abort_unless(OpenBillGate::canUse(auth()->user()), 403); daftar dimuat hanya saat $open; filter all|mine|unsent|stale; pencarian server atas nomor/nama/meja tanpa query tambahan (memakai hasil OpenBillService::listOpen); unsent diurut lebih dulu; voidBill meneruskan alasan dan expectedVersion ke OpenBillService::void (gerbang ada di service) dan menampilkan pesan terjemahan untuk BillForbidden/BillConflict/BillClosed; dispatch event browser dan Livewire per 14.8; pay/select menutup drawer; count dan unsentCount murah (query ber-indeks). Jangan menyentuh Cashier.php.

**cashier-livewire** (gelombang 2)

owner_files:
- `app/Filament/Tenant/Pages/Cashier.php`
- `app/Filament/Tenant/Pages/Traits/CartInteraction.php`
- `app/Livewire/Cashier/ProductBrowser.php`
- `app/Livewire/Cashier/CartItemNote.php`
- `app/Livewire/PriceSetting.php`
- `app/Services/Tenants/OpenBillContext.php`
- `app/Exceptions/CartChangedException.php`

tugas: WAVE 2 (setelah wave 1; baca kode nyata; bergantung pada kontrak OpenBillService 14.5, jangan mengeditnya). Kontrak = plan-v3.md bagian 14 (menang atas bagian 7 dan plan.md 11.3), terjemahan = bagian 15, alasan keputusan = bagian 13. Pekerjaan REPO: edit HANYA owner_files paket ini; jangan menjalankan migrasi/pest/artisan yang menulis ke database kecuali paket `verify`. PHP: $env:Path="$env:USERPROFILE\.config\herd\bin;$env:Path" lalu php -l pada setiap berkas PHP. PHP 8.4, tipe parameter dan return wajib, `$guarded=['id']` untuk model baru.
Pertahankan logika wave-1 dan perluas persis plan-v3 14.6. (1) CartInteraction: activeBillId() (default null), lineModel(), lineQuery(), withLineLock() untuk keranjang pribadi; pada konteks bill addCart/reduceCart/deleteCart/addDiscountPricePerItem/restoreCartItem mendelegasikan ke OpenBillService::addLine/setLineQty/setLineDiscount/restoreLine (tanpa menyentuh cart_items); stok divalidasi cepat (validateStock) tetapi TIDAK otoritatif; stepper '-' pada qty==sent_qty memicu dialog (bukan pengurangan langsung); cart-item-removed membawa undoable (true juga untuk baris terkirim). POS.php dan Pages/CartItem harus tetap bekerja tanpa perubahan (default = keranjang pribadi; verifikasi dengan grep + smoke test Livewire). Voucher memakai BusinessDay::today(). (2) Cashier: seluruh properti dan method 14.6 (openBill, leaveBill, saveBill, sendToKitchen(?int,bool), ackKitchen, printProvisional, reprintKitchen, cancelSent, voidBill, onOpenBillSelected, onOpenBillPay, onBillChildChanged, syncCartForPayment (PIN versi: versi dibaca DULU), paymentClosed, syncBill (no-op saat paymentPinned), proceedThePayment dengan TANDA TANGAN TETAP). proceedThePayment: PaymentPlanner menggantikan cabang wallet||debit||!is_cash; friend_price=false dipaksa; token UX per 13.1 PAY-C3 (validasi dulu, paydone/paylock, finally forget, rotasi token saat gagal); jalur langsung memakai hooks before (kunci baris keranjang + cocokkan tanda tangan, CartChangedException) dan after (hapus keranjang di dalam transaksi) + verify_stock; jalur bill memanggil OpenBillService::pay dengan openBillVersion dan stockOverride; saat printKitchenOnPay dan pending>0 hitung kitchenBatch SEBELUM pay lalu dispatch bill-print dapur sesudah sukses; BillConflict -> notifikasi + bill-conflict {id,serverVersion,label,amount_due}; ValidationException sampai ke modal (@error payed_money, member_id, due_date, products.*.qty); SaleBusy/CartChanged -> notifikasi; cek laci kas -> pesan 'Cash drawer is not opened'. (3) saveBill/sendToKitchen: setelah selesai KEMBALI ke order baru kosong + dispatch bill-saved (KUX-C3); prompt bawa pulang (takeaway-prompt) bila fnb tanpa meja dan nama; sendToKitchen(billId) bekerja tanpa pindah konteks. (4) mount(): billsEnabled, billsVisible (tabel open_bills ada dan (billsEnabled || ada bill open)), kitchenEnabled, pemulihan konteks lewat OpenBillContext (chip 'Melanjutkan Bill'), restoreDraft hanya untuk order baru. (5) calculateTotalPrice via OrderTotals (+ diskon header cartDetail.discount_price; pajak bill.tax_percent pada konteks bill). (6) PriceSetting: public Model $cartItem + authorizeLine(), dispatch open-bill-changed pada konteks bill. CartItemNote: #[Locked] context + billId, konteks bill via OpenBillService::setLineNote, dispatch open-bill-changed. ProductBrowser: tambah map committed (SUM(open_bill_items.qty) bill open, satu query whereIn, try/catch + hasTable cache positif); selain itu tidak berubah (MAX 96, PAGE_SIZE 24). (7) OpenBillContext dan CartChangedException sesuai 14.6. Setiap aksi bill memakai OpenBillGate::canUse. Jangan mengedit view.

**tests-core** (gelombang 2)

owner_files:
- `tests/Unit/OrderLineSorterTest.php`
- `tests/Unit/PlainTextTest.php`
- `tests/Unit/BusinessDayTest.php`
- `tests/Unit/OrderTotalsTest.php`
- `tests/Unit/MoneyTest.php`
- `tests/Unit/PaymentPlannerTest.php`
- `tests/Feature/ProductSearchScopeTest.php`
- `tests/Feature/LoadForReceiptTest.php`
- `tests/Feature/ViewSellingNoteTest.php`
- `tests/Feature/DailyNumberingTest.php`
- `tests/Feature/NumberingLockTest.php`
- `tests/Feature/SellingServiceHooksTest.php`
- `tests/Feature/StockGuardTest.php`
- `tests/Feature/ReceivableDiscountTest.php`
- `tests/Feature/MigrationSchemaTest.php`
- `tests/Feature/PosPreflightTest.php`
- `tests/Feature/Http/Controllers/Api/Tenants/Transaction/SellingNoteTest.php`
- `tests/Feature/CustomerNameNumberingTest.php`
- `tests/Feature/Http/Controllers/Api/Tenants/Transaction/CustomerNameSellingTest.php`

tugas: WAVE 2 (tulis terhadap kode NYATA wave 1; jangan menjalankan pest bersamaan dengan proses lain yang memakai database/testing.sqlite). Kontrak = plan-v3.md bagian 14 (menang atas bagian 7 dan plan.md 11.3), terjemahan = bagian 15, alasan keputusan = bagian 13. Pekerjaan REPO: edit HANYA owner_files paket ini; jangan menjalankan migrasi/pest/artisan yang menulis ke database kecuali paket `verify`. PHP: $env:Path="$env:USERPROFILE\.config\herd\bin;$env:Path" lalu php -l pada setiap berkas PHP. PHP 8.4, tipe parameter dan return wajib, `$guarded=['id']` untuk model baru.
Pest: sorter (A-Z natural tak peka huruf, null terakhir, kategori sama-nama dipisah category_id, produk/kategori null aman, tanpa query, OpenBillItem diterima); PlainText; ProductSearchScope; loadForReceipt; ViewSelling (catatan di-escape, judul grup bila >= 2 kategori); API SellingNote (note aditif, 255 -> 422, permintaan lama tetap 200). Money (round/parse/gte/eq/qty). OrderTotals (satuan-harga, price null, pajak 11% dengan 10 subtotal ganjil, diskon baris dan header, min 0, sama dengan matematika lama, kombinasi 1/3). PaymentPlanner (credit/exact/cash; kredit dengan is_cash true DAN false mempertahankan uang muka; clamp; member+due_date wajib; exact mengabaikan angka klien; cash kurang ditolak; kembalian). PaymentMethod::kind semua kombinasi flag. BusinessDay (setTestNow 16:59:59Z -> 2026-10-15, 17:00:00Z -> 2026-10-16; zona Asia/Makassar menggeser batas ke 16:00Z; normalize: ISO 'Z' dan '+07:00', zona-less, tanggal-saja, null, sampah; scopeDay di SQLite). DailyNumbering (dua penjualan sebelum batas -> 1,2; sesudah -> 1; bill dan penjualan satu urutan; fallback created_at hanya saat date null; fixture lama lulus). CustomerNameNumbering ('Budi' 2x sebelum batas -> Budi, Budi (2); sesudah -> Budi; bill open dihitung; excludeBillId). NumberingLock (ensure idempoten; tidak ada query tulis saat baris ada; acquire membuat baris hilang; tabel tak ada -> no-op tanpa error; uji statis: SellingService tanpa Cache::lock, acquire() adalah pernyataan pertama setelah beginTransaction, tidak ada kode di app/ yang mengunci open_bills sebelum NumberingLock::acquire). SellingServiceHooks (before/after di dalam transaksi; exception di after me-rollback penjualan, stok, nomor, voucher; keep_customer_name; $data['date'] dinormalisasi ISO '+07:00' dan sampah; verify_stock dua kali berurutan dengan satu unit stok -> kedua gagal; override; voucher kuota 1 hanya terkonsumsi sekali dan gagal rollback bila habis; QueryException 1213 diulang 1x lalu SaleBusyException; AssignProduct tanpa note tidak menyentuh kolom note). StockGuard (agregasi, satuan-harga, produk terhapus bernama, non-stock dilewati, kunci products.N.qty). ReceivableDiscount (piutang memakai jumlah sesudah diskon; uang muka tercatat). MigrationSchemaTest (tabel/kolom/indeks ada setelah migrate; nama indeks <= 64; tidak ada ->after( di migrasi baru (grep berkas); semua migrasi baru idempoten dijalankan dua kali; migrasi perf down() tidak melempar). PosPreflightTest (--pre lulus pada skema segar; gagal bila ada migrasi pending tak diizinkan - simulasikan dengan berkas migrasi sementara di path uji; mode pasca gagal bila tabel hilang). EDIT dua berkas tes nama-pelanggan lama HANYA untuk membekukan waktu di tengah hari WIB (Carbon::setTestNow('2026-10-15 05:00:00', 'UTC')). Pakai uses(RefreshDatabaseWithTenant::class) dan Filament::setCurrentPanel(Filament::getPanel('tenant')).


### Gelombang 3

**product-view** (gelombang 3)

owner_files:
- `resources/views/livewire/cashier/product-browser.blade.php`
- `resources/views/livewire/cashier/partials/**`

tugas: WAVE 3 (setelah wave 1-2; baca kode nyata + kasir-mock-v4.html). Tidak berubah dari plan.md 11.5 'product-view' (kartu satu-DOM, CSS pv-* di partials/head.blade.php, tombol kotak/daftar, chip, cari, muat lebih banyak, input qty, stok tak terbatas, data-testid) DITAMBAH: kartu menampilkan 'Tersisa N setelah bill terbuka' (kunci 'Left :left after open bills') bila map committed dari ProductBrowser membuat sisa <= ambang stok rendah, hanya peringatan (tidak memblokir); peta qty kartu (Alpine store 'cart') mengikuti konteks aktif (event cart-qty-updated membawa peta konteks aktif); CSS head tidak bertabrakan dengan kelas baru (awalan pv-). Kontrak = plan-v3.md bagian 14 (menang atas bagian 7 dan plan.md 11.3), terjemahan = bagian 15, alasan keputusan = bagian 13. Pekerjaan REPO: edit HANYA owner_files paket ini; jangan menjalankan migrasi/pest/artisan yang menulis ke database kecuali paket `verify`. PHP: $env:Path="$env:USERPROFILE\.config\herd\bin;$env:Path" lalu php -l pada setiap berkas PHP. PHP 8.4, tipe parameter dan return wajib, `$guarded=['id']` untuk model baru.

**cashier-shell** (gelombang 3)

owner_files:
- `resources/views/filament/tenant/pages/cashier.blade.php`
- `resources/views/filament/tenant/pages/cashier/detail.blade.php`
- `resources/views/filament/tenant/pages/cashier/items.blade.php`
- `resources/views/filament/tenant/pages/cashier/total.blade.php`
- `resources/views/filament/tenant/pages/cashier/bootstrap.blade.php`
- `resources/views/filament/tenant/pages/cashier/cart-panel.blade.php`
- `resources/views/filament/tenant/pages/cashier/cart-item.blade.php`
- `resources/views/filament/tenant/pages/cashier/order-header.blade.php`
- `resources/views/filament/tenant/pages/cashier/actions.blade.php`
- `resources/views/livewire/cashier/cart-item-note.blade.php`
- `resources/views/livewire/price-setting.blade.php`
- `tailwind.config.js`
- `lang/id.json`
- `lang/en.json`

tugas: WAVE 3 - halaman kasir (plan.md 11.5 'cashier-shell' + plan-v3 14.6/14.8/15). Kontrak = plan-v3.md bagian 14 (menang atas bagian 7 dan plan.md 11.3), terjemahan = bagian 15, alasan keputusan = bagian 13. Pekerjaan REPO: edit HANYA owner_files paket ini; jangan menjalankan migrasi/pest/artisan yang menulis ke database kecuali paket `verify`. PHP: $env:Path="$env:USERPROFILE\.config\herd\bin;$env:Path" lalu php -l pada setiap berkas PHP. PHP 8.4, tipe parameter dan return wajib, `$guarded=['id']` untuk model baru.
(1) Baris keranjang: 'qty x satuan' + total baris (data-testid cart-item-unit-{id}, cart-item-total-{id}; awalan '~' bila tak pas), diskon baris, catatan, chip status dapur per baris pada bill yang pernah dikirim (line-chip-{id}); wire:key berawalan konteks ('cart-{id}' / 'bill{billId}-{id}'); <livewire:cashier.cart-item-note> menerima context + billId. (2) Header: chip konteks (bill-chip dengan 'Kembali ke order baru'; new-order-chip 'Order baru (belum disimpan)' + jumlah item; bill-resume-chip). (3) actions.blade.php: fnb -> [Kirim ke dapur] (sub-label 'simpan + cetak tiket', send-kitchen) memimpin + [Simpan saja] (save-bill-only) + [Bill sementara] (provisional-bill) + [Bayar]; retail -> [Simpan bill] (save-bill) + [Bill sementara] + [Bayar]; !billsEnabled menyembunyikan Simpan/Kirim (Bayar tetap); bills-toggle (+ bills-count, titik kuning bills-unsent-dot) tampil bila billsVisible dan membuka <livewire:cashier.open-bills />; strip permanen kitchen-pending-strip; dialog batalkan item terkirim (cancel-sent-dialog: alasan, teks 15.2, gerbang dari server); dialog konflik bill (hanya [Muat ulang] + total baru; TANPA 'Simpan sebagai bill baru'); prompt bawa pulang (takeaway-prompt); toast bill-saved >= 8 dtk dengan [Bayar] [Buka]/[Kirim ke dapur]; wire:poll.20s.visible memanggil syncBill hanya di konteks bill dan !paymentPinned (klien tidak menimpa input fokus/kotor). (4) bootstrap.blade.php: semua dari plan.md 11.3 + pendengar bill-print (await LakasirReceipt.print(...); ok && ack -> Livewire.dispatch('kitchen-ack')), lakasir-print-failed (toast permanen 'Tiket dapur belum tercetak...' [Coba lagi] [Tandai terkirim tanpa cetak] memanggil kitchen-ack mode manual), bill-preview, bill-conflict, bill-saved, takeaway-prompt, penutupan modal bayar -> $wire.paymentClosed(). (5) Sambungan sibling: @include pages.cashier.payment.modal, payment.success-modal, bills.preview-modal dan <livewire:cashier.open-bills /> (jangan membuat berkas di payment/ atau bills/). (6) lang/id.json dan lang/en.json: PEMILIK TUNGGAL; seluruh tabel plan.md 11.4 + plan-v3 bagian 15 (kunci pembatalan di 15.5 dihapus); tambahkan kunci yang dilaporkan paket lain. (7) price-setting view: otorisasi lewat komponen; token tailwind plan.md A23. (8) Aksesibilitas dialog (role, aria-modal, inert, focus trap, Esc, kembalikan fokus, role=status/alert), target 44 px, keyboard visualViewport, tanpa transform pada leluhur fixed. Verifikasi dengan php artisan view:cache setelah paket wave-3 lain mendarat.

**open-bill-view** (gelombang 3)

owner_files:
- `resources/views/livewire/cashier/open-bills.blade.php`
- `resources/views/filament/tenant/pages/cashier/bills/**`

tugas: WAVE 3 - view daftar bill (baca kasir-mock-v4.html dan OpenBills.php). Kontrak = plan-v3.md bagian 14 (menang atas bagian 7 dan plan.md 11.3), terjemahan = bagian 15, alasan keputusan = bagian 13. Pekerjaan REPO: edit HANYA owner_files paket ini; jangan menjalankan migrasi/pest/artisan yang menulis ke database kecuali paket `verify`. PHP: $env:Path="$env:USERPROFILE\.config\herd\bin;$env:Path" lalu php -l pada setiap berkas PHP. PHP 8.4, tipe parameter dan return wajib, `$guarded=['id']` untuk model baru.
livewire/cashier/open-bills.blade.php: drawer (bills-drawer; bottom sheet < md, panel samping >= md; tanpa transform pada leluhur fixed) berisi: kotak cari (bills-search), filter bills-filter-all|mine|unsent|stale, baris ringkas dua-baris (bill-row-{id}: nomor + label + meja, total amount_due, usia, pembuat, chip 'Sudah ke dapur'/'Belum ke dapur' (bill-unsent-chip-{id}; kuning, merah setelah pos.unsent_alert_minutes dengan umur 'Belum ke dapur 6 mnt'), 'Dari kemarin'/'Lewat tengah malam', label tanggal di samping nomor), SATU aksi utama (bill-open-{id} atau bill-pay-{id}) + menu (bill-menu-{id}: Bill sementara bill-print-{id}, Pratinjau, Cetak ulang tiket terakhir, Batalkan bill-void-{id}), chip meja cepat untuk fnb, rincian satuan 'qty x satuan = total' pada ekspansi, dialog void (alasan wajib, petunjuk 'Mau pindah meja?...', pesan server) dan dialog yang SELALU mengulang label + total bill; baris yang sedang fokus atau menunya terbuka tidak dirender ulang saat poll (wire:poll.20s.visible hanya saat drawer terbuka); keadaan kosong 'Tidak ada bill terbuka'; loading; a11y dialog (role=dialog, aria-modal, inert latar, focus trap, Esc, fokus kembali ke bills-toggle, role=status untuk hitungan, role=alert untuk error). bills/preview-modal.blade.php: modal receipt-preview-modal dengan <pre id=receipt-preview-text> (monospace, 32/48 kolom mengikuti printer tersimpan), tombol Cetak (LakasirReceipt.print dengan payload yang sama), tombol 'Kembali'. Semua string dinamis via {{ }}. Hanya kunci bagian 15 (laporkan yang kurang ke cashier-shell; jangan mengedit lang). Target sentuh >= 44 px kecuali pengecualian terdokumentasi.

**payment-view** (gelombang 3)

owner_files:
- `resources/views/filament/tenant/pages/cashier/payment/**`

tugas: WAVE 3 - alur pembayaran baru (plan-v3 keputusan 2(B) + 13.1; baca kasir-mock-v4.html dan Cashier.php nyata). Kontrak = plan-v3.md bagian 14 (menang atas bagian 7 dan plan.md 11.3), terjemahan = bagian 15, alasan keputusan = bagian 13. Pekerjaan REPO: edit HANYA owner_files paket ini; jangan menjalankan migrasi/pest/artisan yang menulis ke database kecuali paket `verify`. PHP: $env:Path="$env:USERPROFILE\.config\herd\bin;$env:Path" lalu php -l pada setiap berkas PHP. PHP 8.4, tipe parameter dan return wajib, `$guarded=['id']` untuk model baru.
Berkas: payment/modal.blade.php (id proceed-the-payment; sheet penuh di HP, lebar 5xl di desktop; saat dibuka: await $wire.syncCartForPayment(); saat ditutup: $wire.paymentClosed()), payment/summary.blade.php (rincian terlipat per kategori 'qty x satuan = total', diskon baris, catatan, pay-items-toggle; terlipat bawaan < 400 px), payment/script.blade.php (Alpine paymentFlow(): jenis dari $wire.paymentMethods[].kind (TIDAK dari nama), ubin pay-method-{id}, metode terakhir di localStorage 'lakasir.cashier.lastPaymentMethod' try/catch, panel cash (Uang pas, maks 3 saran SATU baris di 320 px, numpad pay-numpad-{key} tanpa keyboard sistem (inputmode none/readonly), keyboard fisik, pay-received, pay-change hijau atau 'Kurang Rp X' merah, pay-submit nonaktif sampai uang >= total dengan alasan terlihat), panel exact (pay-exact + konfirmasi eksplisit; mengirim TANPA angka klien), panel credit (member, jatuh tempo +14 hari, uang muka via numpad tak melebihi total, 'Sisa piutang' hanya bila selaras dengan server (disembunyikan bila ada diskon dan keputusan B10 = tolak)), saklar pay-kitchen-switch (fnb, konteks bill, kitchenPreview.pending>0, bawaan ON, teks peringatan saat OFF), panel pay-stock-override (muncul bila server menolak stok untuk baris yang sudah terkirim dan pengguna berhak), pay-conflict (versi berubah saat bayar: total baru + [Muat ulang]), pay-price-stale (petunjuk harga berubah), flag submitting menonaktifkan semua kontrol, submitPayment(): await LakasirPending.settle() lalu $wire.set(payment_method_id, payed_money, money_changes, due_date, member_id, printKitchenOnPay, stockOverride) lalu $wire.proceedThePayment(); kesalahan server ditampilkan di pay-error dari $errors (payed_money, member_id, due_date, products.*.qty bernama produk) dan teks notifikasi; layar tetap bila gagal dan token baru dipakai), payment/success-modal.blade.php (id success-modal: kembalian besar, tombol printReceiptButton memanggil window.LakasirReceipt.print(window._lakasirLastSelling,{buttonId:'printReceiptButton'}), 'Cetak tiket dapur' (penjualan langsung fnb, satu ketukan), 'Tiket dapur ikut dicetak' bila dicetak otomatis, 'Pratinjau', tutup; buka ulang modal ini untuk penjualan yang sudah tercatat (PayResult.alreadyPaid)). Tidak ada salinan kode cetak inline (hanya LakasirReceipt). Semua dinamis via {{ }}/x-text. Uang diformat window.moneyFormat atau Intl id-ID. Hanya kunci bagian 15.

**tests-bills** (gelombang 3)

owner_files:
- `tests/Feature/OpenBillServiceTest.php`
- `tests/Feature/OpenBillPayTest.php`
- `tests/Feature/OpenBillKitchenTest.php`
- `tests/Feature/OpenBillEventsTest.php`
- `tests/Feature/OpenBillsComponentTest.php`
- `tests/Feature/CashierOpenBillTest.php`
- `tests/Feature/PaymentFlowTest.php`

tugas: WAVE 3 (terhadap kode nyata wave 2; verify menjalankannya). Pest + Livewire::test. Kontrak = plan-v3.md bagian 14 (menang atas bagian 7 dan plan.md 11.3), terjemahan = bagian 15, alasan keputusan = bagian 13. Pekerjaan REPO: edit HANYA owner_files paket ini; jangan menjalankan migrasi/pest/artisan yang menulis ke database kecuali paket `verify`. PHP: $env:Path="$env:USERPROFILE\.config\herd\bin;$env:Path" lalu php -l pada setiap berkas PHP. PHP 8.4, tipe parameter dan return wajib, `$guarded=['id']` untuk model baru.
OpenBillServiceTest: createFromCart (nomor dari urutan bersama, keranjang kosong, duplikat digabung, BillEmptyException, nama (2) menghitung bill open, UNIQUE (business_date, number), tax_percent dibekukan); mutate (bill tertutup -> BillClosedException, version++); updateHeader (konflik; rename memakai kunci penjualan); listOpen 3 query konstan (5 vs 30 bill); void (alasan wajib untuk semua; matriks izin: pembuat/update/delete; bill terkirim: pembuat dalam 10 mnt via setTestNow, di luar jendela ditolak); baris bill tetap tersimpan; gerbang dievaluasi terhadap bill terkunci (pembuat tidak bisa void bill yang dikirim perangkat lain). OpenBillKitchenTest: batch 1 semua baris; tambah qty -> hanya selisih (kind addition); kurangi di bawah sent_qty -> BATAL turunan dan sent_qty tidak turun; minus lalu plus -> tidak ada BATAL/TAMBAHAN (netting); hapus baris terkirim lalu tambah produk yang sama -> netting lewat pending_cancel; catatan baris terkirim berubah -> changed; pindah meja -> moved + Kirim aktif tanpa perubahan item; tidak ada selisih -> null; ack idempoten (dua kali, batch lama, batch lompat -> BillConflict); batch tidak berubah tanpa ack (retry mencetak payload sama); ack manual; reprint = last_kitchen_batch tanpa perubahan status; 150 baris bercatatan panjang tersimpan utuh (MEDIUMTEXT); void ticket. OpenBillPayTest: bill belum dibayar TIDAK ada di sellings/DashboardController/CashierReportService/ProductReportService/SellingReportService/API GET selling dan stok tak berubah; setelah pay tepat 1 selling, stok berkurang sekali, bill paid + selling_id, listener berjalan (kredit dengan uang muka membuat piutang, is_cash true dan false), nomor sama hari = nomor bill + nama kept, lintas tengah malam (setTestNow) = nomor/nama baru, $data['date'] satu momen (lintas 16:59:59Z/17:00:00Z tidak menghasilkan nomor ganda), stok kurang -> rollback bill tetap open utuh dan voucher TIDAK terkonsumsi, dua pay() berurutan dengan satu unit stok -> satu berhasil, override stok hanya untuk baris terkirim + berhak, versi basi -> BillConflict tanpa efek, bayar ulang -> PayResult.alreadyPaid tanpa penjualan kedua, laci kas belum dibuka -> pesan bukan 500 (juga bila laci ditutup antara pre-cek dan insert), paid_by = pembayar, angka klien diabaikan (total_price/diskon/pajak dari bill; friend_price paksa false), pajak dibekukan (ubah default_tax setelah bill dibuat tidak mengubah), produk terhapus bernama, metode bayar hilang -> ValidationException, tiket dapur saat bayar. OpenBillEventsTest: setiap mutasi menulis event; cancel_sent mencatat siapa/alasan; paid + pay_with_unsent; stock_override. OpenBillsComponentTest: 403 untuk SETIAP method tanpa 'create selling' (termasuk render/poll), openBill-semacam select pada bill paid/void ditolak, filter+cari, event select/pay, void dialog, daftar lazy. CashierOpenBillTest: saveBill dari keranjang -> reset order baru + bill-saved; sendToKitchen mengirim bill-print dengan ack lalu ackKitchen menandai terkirim; cetak gagal (tanpa ack) -> belum terkirim dan kirim ulang menghasilkan batch sama; +,+,Kirim cepat tanpa konflik palsu; edit catatan/diskon lalu Kirim/Bayar tanpa konflik palsu (open-bill-changed); perangkat kedua mengubah bill -> bayar menolak dengan total baru; syncBill no-op saat paymentPinned; openBill mempertahankan keranjang pribadi; POS dan Pages/CartItem tetap mount; addCart di bill memvalidasi stok; token: gagal validasi lalu coba ulang berhasil, token terpakai membuka modal sukses yang sama; bayar langsung dari dua instance komponen (satu akun) -> satu penjualan (CartChangedException pada yang kedua); credit down payment dipertahankan. PaymentFlowTest: server menolak kredit tanpa member/due_date, payed_money di luar [0, amount_due] untuk kredit ditolak, cash/exact aturan; total stabil antar request (OrderTotals); tidak ada deteksi 'qris' dari nama.


### Gelombang 4

**tests-cashier** (gelombang 4)

owner_files:
- `tests/Feature/CashierProductBrowserTest.php`
- `tests/Feature/CashierItemNoteTest.php`
- `tests/Feature/CashierCartSortTest.php`
- `tests/Feature/CashierCustomerNameDraftTest.php`
- `tests/Feature/ReceiptPartialTest.php`
- `tests/Feature/LangKeysTest.php`
- `tests/Feature/CashierPerfTest.php`
- `tests/Feature/CashierViewStaticTest.php`
- `tests/Support/receipt-harness.mjs`
- `tests/Support/payment-harness.mjs`

tugas: WAVE 4 (setelah semua view mendarat; kode nyata). Kontrak = plan-v3.md bagian 14 (menang atas bagian 7 dan plan.md 11.3), terjemahan = bagian 15, alasan keputusan = bagian 13. Pekerjaan REPO: edit HANYA owner_files paket ini; jangan menjalankan migrasi/pest/artisan yang menulis ke database kecuali paket `verify`. PHP: $env:Path="$env:USERPROFILE\.config\herd\bin;$env:Path" lalu php -l pada setiap berkas PHP. PHP 8.4, tipe parameter dan return wajib, `$guarded=['id']` untuk model baru.
Port/perluas daftar plan.md 11.6 (ProductBrowser, CartItemNote termasuk konteks bill dan isolasi per kasir, urut keranjang stabil setelah commitCustomerName/syncCartForPayment/storeCart, draft hanya order baru, perf: CashierPerfTest dari tableSearch ke komponen anak, tiga skenario 5000 produk + waktu addCart + ukuran HTML induk di bawah PERF=1) PLUS: ReceiptPartialTest statis (tanpa innerHTML|outerHTML|insertAdjacentHTML|document.write, .cut() hanya di partial, tanpa @include partial di view, render hook terdaftar, tanpa handler keypress lama, tanpa rand() di key, awalan wire:key konteks ada, tidak ada salinan kode cetak inline di payment/ atau bills/); receipt-harness.mjs (Node vm + Printer palsu + TextPrinter): receipt/bill/kitchen pada 58 dan 80 mm: 'qty x satuan' + total per baris, '~' bila tak pas (3 x 3.333,33), diskon, catatan dibungkus, catatan 300 karakter tanpa spasi, kategori panjang, ESC/GS, kutip pintar, nama non-Latin -> SKU, kitchen tanpa harga default / dengan showPrices, BATAL/UBAH CATATAN/PINDAH, PEMBATALAN saja, BILL DIBATALKAN + alasan, '(total n)', SALINAN atas+bawah, baris besar MEJA/BAWA PULANG, perintah terakhir sebelum cut = size(0,0)+style normal+align left, bill tanpa baris bayar/kembali + 'BUKAN BUKTI PEMBAYARAN', antrean (dua pekerjaan 100 ms berurutan keduanya sampai ke printer; batch kedua dalam 3 dtk tetap tercetak; jobKey sama dalam 3 dtk -> satu cetak, Promise sama; kegagalan -> resolve(false) + event lakasir-print-failed), preview() == gabungan build(); payment-harness.mjs: aritmetika numpad/saran/kembalian/kurang paymentFlow() (diekstrak via vm). LangKeysTest: setiap __('...') di view dan kelas PHP baru ada di lang/id.json dan en.json. CashierViewStaticTest: ID tetap (printReceiptButton, qr-reader, edit-detail, proceed-the-payment, success-modal, modal-selected-table, qr-scanner-modal), semua testid bagian 14.10 dan 7.8 ada, tanpa {!! !!} pada data pengguna, tanpa catatan/nama mentah di @js tanpa escape, tanpa deteksi jenis dari nama ('qris'), dialog punya role/aria-modal, tidak ada 'Simpan sebagai bill baru'. Jangan menjalankan pest paralel dengan proses lain.


### Gelombang 5

**verify** (gelombang 5)

owner_files:
- (tidak ada; hanya baca, jalankan, laporkan)

tugas: WAVE 5 - setelah semua mendarat dan tak ada proses lain memakai database/testing.sqlite. Tulis keluaran HANYA di scratchpad. Kontrak = plan-v3.md bagian 14 (menang atas bagian 7 dan plan.md 11.3), terjemahan = bagian 15, alasan keputusan = bagian 13. Pekerjaan REPO: edit HANYA owner_files paket ini; jangan menjalankan migrasi/pest/artisan yang menulis ke database kecuali paket `verify`. PHP: $env:Path="$env:USERPROFILE\.config\herd\bin;$env:Path" lalu php -l pada setiap berkas PHP. PHP 8.4, tipe parameter dan return wajib, `$guarded=['id']` untuk model baru.
(1) Herd PHP 8.4 (php -v), php -l semua PHP baru/berubah, php artisan view:clear lalu view:cache sukses. (2) migrate:status pada DB dev; tinjau pending (AGENTS.md: sebagian menjatuhkan tabel); terapkan HANYA tujuh berkas milik fitur satu per satu dengan --path --force; jalankan php artisan pos:preflight --pre sebelum dan pos:preflight sesudah; jalankan tiap migrasi DUA kali (idempoten) dan sekali lagi setelah menghapus satu indeks/FK secara manual pada salinan (perbaikan keadaan setengah jadi). (3) Tinjau SQL MySQL: migrate --pretend --path=<berkas> dengan DB_CONNECTION=mysql dan kredensial dummy (bila tak bisa dirender tanpa server, nyatakan dan tulis DDL manual dari 14.3); simpan di scratchpad/mysql-pretend.sql; periksa: hanya create table/alter table add/create index; nama <= 64; ukuran kunci; tanpa after(); down() migrasi perf membuat indeks FK dulu; ingat pretend = kasus terburuk (bukan bukti). Bila ada MySQL/MariaDB lokal (herd services, docker, mysqld), JALANKAN migrasi pada salinan skema dengan beberapa baris, lalu dua proses PHP paralel: (a) membuat penjualan/bill -> tak ada nomor ganda; (b) membayar dua bill berbeda dengan satu unit stok -> tepat satu berhasil; (c) pay pada bill yang sama dua kali; (d) uji pos:preflight terhadap DB dengan migrasi destruktif pending (harus gagal). Bila tidak ada MySQL, laporan akhir WAJIB menyatakan 'jalur REPEATABLE READ hanya ditinjau, tidak diuji'. (4) npm run build. (5) php vendor/bin/pest penuh, lalu PERF=1 PERF_PRODUCTS=5000 tests/Feature/CashierPerfTest.php; bandingkan dengan baseline plan.md; ukur waktu penjualan dengan kunci global. (6) Browser (panel bawaan; kredensial dari seeder, jangan dicetak): 320/375/768/1280 terang+gelap tanpa overflow horizontal; jalur bahagia open bill (order baru -> Kirim ke dapur dengan Printer palsu perekam perintah -> kembali ke order baru + toast -> buka bill -> tambah item -> kirim lagi hanya selisih -> kurangi item terkirim (dialog+alasan) -> minus/plus netting -> pindah meja -> Bill sementara -> bayar tunai dengan numpad + tiket dapur ikut -> struk); konflik dua tab (hanya Muat ulang); bayar jenis cash/exact/credit (uang muka tercatat sebagai piutang); gagal cetak -> toast + Coba lagi; hitung ketukan; modal-di-sheet; konsol bersih; teks pratinjau == perintah printer yang direkam; 'qty x satuan = total' di keranjang, ringkasan bayar, daftar bill, pratinjau. (7) Grep statis: .cut() hanya di partial; tanpa innerHTML di partial; tanpa Cache::lock di app/; tanpa whereDate pada sellings di jalur POS; tak ada kode mengunci open_bills sebelum NumberingLock::acquire; config/app.php timezone tetap UTC. (8) Laporkan lulus/gagal, tangkapan layar, angka performa, tinjauan MySQL, temuan di luar scope, dan semua pertanyaan Randy yang masih terbuka.


---

## 17. KRITERIA PENERIMAAN (menggantikan bagian 10; tambahan atas plan.md 6 dan 11.6)

Setiap butir harus dibuktikan oleh tes otomatis (T), pemeriksaan browser (B), atau tinjauan terdokumentasi (R; dipakai hanya untuk jalur MySQL yang tak bisa diuji tanpa server).

### 17.1 Harga per item (permintaan Randy)
1. (T,B) `LakasirReceipt.preview` pada 58 dan 80 mm untuk mode `receipt` dan `bill`: setiap baris memuat `qty x satuan` dan total baris, diskon baris, catatan item; `~` bila `round(satuan*qty) != total`; mode `kitchen` TANPA harga bila setting mati dan DENGAN harga bila menyala.
2. (B) `cart-item-unit-{id}` / `cart-item-total-{id}`, rincian modal bayar, dan daftar bill menampilkan angka yang sama dengan pratinjau cetak.

### 17.2 Open Bill dan dapur
3. (T) Simpan -> kirim (batch 1 semua baris) -> tambah item -> kirim (hanya selisih, `kind=addition`) -> kurangi item terkirim (BATAL turunan; `sent_qty` tidak turun) -> ubah catatan terkirim (UBAH CATATAN) -> ubah meja (PINDAH; Kirim aktif tanpa perubahan item) -> bill sementara (tanpa baris bayar) -> bayar: `sellings` +1, stok berkurang tepat sekali, bill `paid` dengan `selling_id`, nomor order = nomor bill (hari sama), nama sama.
4. (T) Dua fase: batch tidak mengubah status sampai `ackKitchenBatch`; `ack` ganda idempoten; cetak gagal -> kirim ulang menghasilkan batch yang sama; "Tandai terkirim tanpa cetak" menulis event `sent_manual`.
5. (T) Netting: minus lalu plus pada baris terkirim TIDAK menghasilkan BATAL maupun TAMBAHAN; hapus lalu tambah produk yang sama net nol; Urungkan memulihkan.
6. (T,B) Setelah Simpan/Kirim layar kembali ke order baru kosong; toast `bill-saved` >= 8 dtk dengan [Bayar] [Buka]; produk berikutnya TIDAK masuk ke bill sebelumnya.
7. (T,B) Bayar bill fnb dengan perubahan dapur belum terkirim: saklar default ON; tiket dapur tercetak SEBELUM struk lewat antrean; OFF -> peringatan jumlah perubahan dan event `pay_with_unsent`.
8. (T) Bill belum dibayar tidak muncul di `DashboardController`, `CashierReportService`, `ProductReportService`, `SellingReportService`, `GET /transaction/selling` dan tidak mengubah stok, piutang, laci kas.
9. (T) Void: alasan wajib untuk SEMUA bill; matriks izin (pembuat/update selling/delete selling; pembuat bill terkirim hanya dalam 10 menit); baris tetap tersimpan; tiket void BILL DIBATALKAN + alasan; `openBill(id)` menolak bill `paid`/`void`; 403 untuk setiap method tanpa `create selling`.
10. (T) Pengurangan di bawah `sent_qty` dan hapus baris terkirim tanpa izin/alasan ditolak dan tidak menulis apa pun; dengan izin menulis event `cancel_sent`/`line_removed` dengan pengguna dan alasan.
11. (B) Daftar 30 bill: cari, filter, baris ringkas, satu aksi utama, baris fokus tidak dirender ulang, "Belum ke dapur" kuning->merah setelah 3 mnt, titik hitung di `bills-toggle`; a11y dialog lengkap.

### 17.3 Konkurensi, stok, kunci
12. (T) Versi: sesi A bayar/void/ubah header dengan versi lama -> `BillConflictException` tanpa efek samping; edit catatan/diskon lalu Kirim/Bayar pada satu perangkat TIDAK konflik palsu; +, +, Kirim cepat tanpa konflik; perangkat kedua mengubah -> bayar menolak dengan total baru; `syncBill` no-op selama `paymentPinned`.
13. (T) `pay()` dua kali berurutan dengan satu unit stok -> tepat satu berhasil; stok tidak negatif kecuali override resmi (baris terkirim + `update selling`/owner) dan override tercatat; stok kurang -> rollback total (bill tetap open, voucher tidak terkonsumsi, penomoran tak berubah).
14. (T) `pay()` pada bill yang sama dua kali -> satu penjualan; kedua mengembalikan `PayResult.alreadyPaid` dan membuka modal sukses yang sama; bayar langsung dari dua instance komponen satu akun -> satu penjualan (`CartChangedException`).
15. (T) Token: gagal validasi lalu coba ulang berhasil; token terpakai membuka struk yang ada; token dirotasi setelah gagal.
16. (T) Voucher dikonsumsi sekali, atomik, di dalam transaksi; gagal bayar = tidak terkonsumsi; kuota 1 dua pembayar paralel -> satu berhasil.
17. (T) Urutan kunci: uji statis bahwa `SellingService` tanpa `Cache::lock`, `acquire()` adalah pernyataan pertama setelah `beginTransaction`, dan tak ada kode `app/` yang mengunci `open_bills` sebelum `NumberingLock::acquire`; rename dan `createFromCart` memakai `sales_lock`.
18. (R/B bila MySQL tersedia) dua proses PHP paralel: tidak ada nomor ganda; dua bill satu stok -> satu berhasil. Bila tak ada MySQL, laporan akhir WAJIB menyatakan jalur REPEATABLE READ hanya ditinjau.
19. (T) Degrade: tanpa tabel `numbering_locks` penjualan tetap berhasil (+ warning); tanpa tabel `open_bills` `billsVisible=false` dan penjualan/numbering tetap jalan; penjualan tanpa catatan tidak menyentuh kolom `note`.

### 17.4 Pembayaran
20. (T) Kredit dengan metode `is_credit=1,is_cash=0` DAN `is_cash=1`: uang muka dipertahankan (clamp 0..amount_due), piutang = amount_due - uang muka (setelah diskon), `ReceivablePayment` bila uang muka > 0; member dan due_date wajib; di luar [0, amount_due] ditolak. Cash kurang ditolak; exact mengabaikan angka klien.
21. (T) Pajak 11% dengan 10 subtotal ganjil dan kombinasi 1/3: `OrderTotals` == `mapProductRequest` == validator (tak ada penolakan palsu pada pembayaran exact).
22. (T) Pay hanya menerima field pembayaran; total/diskon/pajak dari bill; `friend_price` dipaksa false; pajak bill dibekukan.
23. (T) Laci kas belum dibuka -> pesan terjemahan, bukan 500 (juga bila laci ditutup antara pre-cek dan insert); bill masuk laci saat bayar dengan `user` = pembayar.
24. (B) Modal bayar: `kind` dari flag; metode terakhir diingat; tombol bayar nonaktif dengan alasan terlihat; submitting menonaktifkan kontrol; pada 320 px saran nominal satu baris; ringkasan terlipat < 400 px; override stok dan konflik versi tampil.

### 17.5 Penomoran dan tengah malam
25. (T) Semua skenario E6 (16:59:59Z / 17:00:00Z, Asia/Makassar, tanggal-saja, null, ISO `Z`/`+07:00`, nama "Budi" 2x sebelum batas -> Budi, Budi (2); sesudah -> Budi); bill dibuat 23:59 WIB dibayar 00:10 WIB -> nomor baru, nama dinomori ulang; `pay()` melintasi batas di tengah eksekusi -> satu `date`, tanpa nomor ganda; bill dan penjualan berbagi satu urutan; dua pembuatan berurutan tak pernah menghasilkan nomor kembar; `UNIQUE (business_date, number)` ada.
26. (T) Penjualan dengan `date` kemarin (sinkronisasi offline) dan penjualan langsung tetap terserialisasi oleh kunci `sales` (uji statis + uji urutan).
27. (B) Label tanggal di samping nomor untuk bill bukan hari ini; `stale` hanya bila > 6 jam; struk bill lintas hari menampilkan jam/tanggal dibuka.

### 17.6 Migrasi dan MySQL
28. (T) `migrate:fresh` SQLite lulus; setiap migrasi baru dijalankan DUA kali tanpa error; perbaikan keadaan setengah jadi (hapus satu indeks/FK lalu migrasi ulang memulihkannya; FK diverifikasi di jalur non-SQLite lewat tinjauan DDL).
29. (R) `migrate --pretend` (koneksi mysql): hanya `create table`, `alter table add column`, `create index`; semua nama indeks <= 64; ukuran kunci < 3072 B; tanpa `after`; `down()` perf membuat indeks FK pengganti dulu; ditulis jelas bahwa pretend = kasus terburuk.
30. (T) `pos:preflight --pre` gagal (exit 1) bila ada migrasi pending selain tujuh berkas yang diizinkan, atau tabel inti hilang; sukses pada skema yang benar; `pos:preflight` pasca gagal bila tabel/kolom/indeks hilang; `000006` hanya WARN.
31. (T) `config/app.php` timezone tetap `UTC`.

### 17.7 Pipeline cetak
32. (T Node) Antrean: dua pekerjaan berjarak 100 ms keduanya sampai ke printer; batch kedua dalam 3 dtk tercetak (kunci per batch); `jobKey` sama dalam 3 dtk -> satu cetak dan Promise sama; kegagalan -> `resolve(false)` + event `lakasir-print-failed`; tidak ada pekerjaan yang jatuh senyap.
33. (T Node) Tiket dapur: perintah terakhir sebelum `cut()` = `size(0,0)`, `style('normal')`, `align('left')`; SALINAN atas+bawah saat reprint; PEMBATALAN bila hanya batal; BILL DIBATALKAN + alasan; MEJA/BAWA PULANG besar; `(total n)`; `preview()` == gabungan `build()`.
34. (B) Kegagalan cetak menampilkan toast permanen dengan [Coba lagi] [Tandai terkirim tanpa cetak]; "Tidak ada item baru" menawarkan [Cetak ulang tiket terakhir]; cetak ulang tidak mengubah status.

### 17.8 Tanpa regresi
35. Semua kriteria plan.md 6 dan 11.6 yang tidak dibatalkan; `POS` dan `Pages/CartItem` tetap memuat; konsol bersih; perf: `CashierPerfTest` PERF=1 tidak lebih buruk dari baseline plan.md; penjualan dengan kunci global diukur dan dilaporkan; tidak ada kueri per-baris baru (daftar bill 3 query, committed 1 query).

36. (manual, Randy, di Android Chrome sungguhan sesudah staging) keyboard sistem pada nama pelanggan, catatan item, dan alasan void tidak menutupi tombol aksi (`visualViewport`); numpad uang tidak memunculkan keyboard sistem; cetak ke printer Bluetooth sungguhan: dua tiket berurutan (dapur lalu struk) keduanya keluar. Tidak bisa diuji otomatis; dicatat sebagai pemeriksaan manual.

### 17.9 Tambahan tes (dari verdict; sudah masuk paket tests-*)
Stock recheck dalam transaksi; retry token setelah gagal validasi; edit catatan lalu Kirim tanpa konflik palsu; poll nonaktif saat bayar; guard cetak per batch; kredit uang muka dengan `is_cash` true/false; voucher tidak terkonsumsi saat pay gagal; hapus baris tanpa `delete selling` ditolak; double submit dari dua instance; antrean cetak; netting; PINDAH; bayar dengan perubahan belum terkirim mencetak dapur lalu struk; header PEMBATALAN; reprint SALINAN tanpa ubah status; aturan stale; daftar 30 bill (jumlah kueri + cari); prompt bawa pulang; 150 baris bercatatan panjang (MEDIUMTEXT); `OpenBillPayTest` kebocoran laporan.


---

## 18. ROLLOUT dan ROLLBACK untuk MySQL produksi (menggantikan 6.4 dan 6.5; isi `docs/deploy-open-bill.md`)

### 18.1 Prinsip
- **Migrasi aditif, idempoten per pernyataan, dijalankan SATU PER SATU dengan `--path`.** `php artisan migrate` tanpa batas DILARANG: ia menjalankan semua yang Pending, dan beberapa migrasi lama menjatuhkan kolom (`2025_10_03_042817_remove_barcode_from_products_table` menyalin lalu `dropColumn products.barcode`; `2024_06_04_082221_drop_expired_columns_in_products_table`; `2024_04_25_231131_create_abouts_table` memiliki `dropColumn` di cabang else; AGENTS.md sudah memperingatkan).
- **Gerbang keras `php artisan pos:preflight --pre`** menggantikan "baca keluaran --pretend dengan mata". `--pretend` dengan guard `hasTable/hasColumn/hasIndex` mencetak DDL untuk SEMUA objek (pada mode pretend `select` kosong, jadi guard selalu "belum ada"): keluarannya daftar kasus terburuk, BUKAN bukti bahwa sesuatu belum berjalan. Status sebenarnya hanya dari `migrate:status` / `pos:preflight`.
- **Jendela maintenance wajib** (`php artisan down`): menutup jalur "kode baru, skema lama" yang akan menggagalkan SEMUA penjualan, termasuk API mobile (yang tidak bisa disuruh reload). Kode juga turun-ke-bawah (R5) sebagai sabuk kedua.
- **Rollback = pasang kode lama; skema tidak di-rollback.** Kode lama mengabaikan tabel baru. Satu pengecualian bisnis: bill terbuka tidak terlihat oleh kode lama dan omzetnya belum tercatat, jadi bayar/batalkan dulu (18.4).

### 18.2 Daftar tugas Randy (urut; bahasa awam; hasil perintah dikirim ke saya)

**Langkah 0 - Fakta dan cadangan (SEBELUM menyentuh apa pun; boleh dikerjakan kapan saja)**
1. Cadangan: `mysqldump --single-transaction --routines --triggers -u USER -p NAMADB > backup-AAAA-BB-HH.sql` (lakukan SEBELUM `migrate:status` dilihat). Salin ke tempat lain. **Pulihkan ke database kosong (scratch) di server yang sama zona waktunya** dan hitung `SELECT COUNT(*) FROM sellings;` di sana: cadangan yang belum pernah dipulihkan bukan cadangan. (Restore ke server dengan zona waktu berbeda menggeser `created_at` TIMESTAMP terhadap `date` DATETIME; lakukan latihan di server berzona sama.)
2. Fakta (kirim hasilnya): `SELECT VERSION(), @@system_time_zone, @@global.time_zone, @@transaction_isolation, @@innodb_lock_wait_timeout, @@innodb_default_row_format, @@sql_mode;` dan `SELECT TABLE_NAME, ENGINE, ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND (ENGINE <> 'InnoDB' OR ROW_FORMAT NOT IN ('Dynamic','Compressed'));` (harus kosong) dan `SELECT COUNT(*) FROM sellings; SELECT COUNT(*) FROM selling_details; SELECT MAX(id) FROM sellings;` dan nilai `CACHE_DRIVER` pada `.env` produksi. Catat juga hash commit/tag yang sedang berjalan (`git rev-parse HEAD`) dan nama database di `.env` (`DB_DATABASE`).
3. Ruang disk: bandingkan ruang kosong dengan `SELECT data_length+index_length FROM information_schema.TABLES WHERE table_name='selling_details'` (MySQL 5.7 membangun ulang tabel saat `ADD COLUMN`; `ALGORITHM` tidak dipaksa).

**Langkah 1 - Pilih jam sepi, tutup toko**
4. `SHOW FULL PROCESSLIST;` dan `SELECT * FROM information_schema.innodb_trx;` -> tidak boleh ada transaksi/laporan panjang pada `sellings`, `selling_details`, `cart_items`, `stocks`, `products` (ALTER menunggu metadata lock dan SEMUA query berikutnya antre di belakangnya, tampak seperti mati total).
5. `php artisan down --retry=60` (halaman 503). Tunggu 30 detik.

**Langkah 2 - Pasang kode (belum menjalankan migrasi)**
6. `git pull` / unggah kode, `composer install --no-dev`, aset hasil `npm run build` (`public/build`).

**Langkah 3 - GERBANG (wajib lulus)**
7. `php artisan pos:preflight --pre`. Harus berakhir "PASS". Ia mencetak fakta database, memastikan ini database yang benar (tabel `sellings`, `selling_details`, `cart_items`, `products`, `stocks`, `users`, `migrations` ada) dan bahwa migrasi Pending hanya sebagian dari tujuh berkas fitur ini. **Bila FAIL (mis. daftar migrasi lama `remove_barcode...` pending): BERHENTI. Jangan jalankan migrate. Kirim keluarannya kepada saya. Pasang kembali kode lama, `php artisan up`.**
8. `php artisan migrate:status` (lihat dengan mata sebagai pemeriksaan kedua): semua migrasi sebelum `2026_10_15_000001` berstatus Ran, kecuali `2026_10_14_000001` boleh Pending.

**Langkah 4 - Migrasi satu per satu (hentikan pada kegagalan pertama)**
9. Jalankan berurutan (lewati yang sudah Ran):
```
php artisan migrate --path=database/migrations/2026_10_14_000001_add_pos_performance_indexes.php --force
php artisan migrate --path=database/migrations/2026_10_15_000001_add_note_to_cart_items_and_selling_details_tables.php --force
php artisan migrate --path=database/migrations/2026_10_15_000002_create_open_bills_table.php --force
php artisan migrate --path=database/migrations/2026_10_15_000003_create_open_bill_items_table.php --force
php artisan migrate --path=database/migrations/2026_10_15_000004_create_open_bill_events_table.php --force
php artisan migrate --path=database/migrations/2026_10_15_000005_create_numbering_locks_table.php --force
php artisan migrate --path=database/migrations/2026_10_15_000006_add_date_index_to_sellings_table.php --force
```
Setiap `ALTER` pada tabel berdata memasang `lock_wait_timeout` pendek dan mencoba ulang 3x; bila gagal dengan "Lock wait timeout exceeded" cari transaksi yang menahan (langkah 4), lalu ulangi PERINTAH YANG SAMA (aman diulang). Tabel baru kecil; migrasi harus selesai dalam hitungan detik.
10. `php artisan pos:preflight` (tanpa `--pre`) -> semua "PASS" (`sellings_date_number_index` hanya WARN bila hilang; boleh dilewati).

**Langkah 5 - Hidupkan**
11. `php artisan optimize:clear`, `php artisan view:clear`, restart PHP-FPM (membersihkan cache opcode/status tabel per proses), `php artisan up`.
12. Reload keras (Ctrl+Shift+R) di SETIAP perangkat kasir.

**Langkah 6 - Uji asap (10 menit) dan pembanding**
13. (a) satu penjualan tunai langsung; (b) satu bill: Kirim ke dapur, tambah item, kirim lagi (hanya tambahan), Bill sementara, bayar; (c) laporan harian cocok dengan uang masuk; (d) stok produk yang terjual berkurang tepat satu kali; (e) `SELECT COUNT(*) FROM sellings` dan `MAX(id)` naik sesuai jumlah transaksi uji dibanding angka langkah 0; (f) besok 00:05 WIB buat satu penjualan: nomor harian harus 1.

**Dilarang di produksi:** `php artisan migrate` tanpa `--path`, `migrate:rollback`, `migrate:fresh`, `migrate:refresh`, `db:wipe`, `ALGORITHM=INSTANT` mentah.

### 18.3 Bila langkah gagal di tengah jalan (tabel di `docs/deploy-open-bill.md`)

| Berkas | Keadaan bila gagal | Aman diulang? |
|---|---|---|
| `2026_10_14_000001` (perf) | Sebagian indeks sudah dibuat | Ya (guard `hasIndex` tiap indeks, nama sama) |
| `2026_10_15_000001` (note) | Satu dari dua kolom sudah ada | Ya (`hasColumn`) |
| `000002/3/4/5` (tabel baru) | Tabel ada tetapi FK/indeks belum | Ya: `Schema::create` dilewati, lalu tiap indeks/FK diperiksa dan ditambahkan satu per satu (error 1215 = tabel induk bukan InnoDB / tipe berbeda: kirim keluaran `SHOW CREATE TABLE`, jangan memaksa) |
| `000006` (indeks sellings) | Indeks belum ada | Ya; boleh dilewati, kode tetap benar |

### 18.4 Rollback (bahasa awam)
1. Tutup toko: `php artisan down`.
2. **Sebelum** memasang kode lama, daftar bill terbuka (baca-saja): `SELECT id, business_date, number, customer_name, created_at FROM open_bills WHERE status='open';` Selesaikan SEMUA: bayar (kode baru masih terpasang) atau batalkan dengan alasan, atau cetak Bill sementara dan catat manual. Bill terbuka tidak terlihat kode lama dan omzetnya belum ada di laporan.
3. Pasang kode lama (git checkout commit sebelumnya + `composer install` + aset lama), `php artisan optimize:clear`, restart PHP-FPM, `php artisan up`.
4. Skema TIDAK di-rollback. Kunci `open_bill_enabled=false` hanya menyembunyikan PEMBUATAN bill pada kode baru; daftar bill dan tombol Bayar tetap tampil selama masih ada bill terbuka. Jangan memakai `migrate:rollback`: `down()` menjatuhkan kolom `note` (kehilangan data catatan) dan tabel bill (kehilangan jejak).

### 18.5 Catatan perilaku yang harus ada di dokumen untuk Randy
- Hari pertama sesudah deploy: penjualan 00:00-07:00 WIB yang bernomor menurut hari UTC lama ikut terhitung ke hari bisnis baru; nomor berikutnya = maks + 1 atas keduanya (lompatan, bukan reset). Hanya berpengaruh bila toko/sinkronisasi offline aktif pada jam itu; mulai tengah malam berikutnya reset tepat 1.
- Bill melintasi tengah malam: penjualan, stok, laci, dan penomoran mengikuti HARI PEMBAYARAN dengan nomor baru; tiket dapur nomor lama vs struk nomor baru; laporan hari pesan tidak memuat pesanan itu (pertanyaan B1).
- Harga bill = harga saat dipesan (baris biasa); menambah qty menghargai selisih dengan harga hari ini; baris satuan-harga dihitung ulang saat bayar; pajak dibekukan saat bill dibuat; diskon header milik bill.
- Laci kas: bill masuk ke laci yang terbuka saat BAYAR dan atas nama pembayar; menutup laci tidak memperingatkan bill terbuka (daftar bill terbuka sebelum menutup laci).
- Dasbor memakai zona profil/`created_at` untuk "hari ini" (tidak diubah); penomoran memakai zona bisnis; untuk Asia/Jakarta keduanya sama.
- Produk jangan dihapus permanen saat masih ada di bill terbuka (error FK mentah, sama seperti keranjang hari ini).
- Penjualan yang dihapus tidak memulihkan stok (perilaku lama); bill `paid` yang penjualannya dihapus tetap read-only.
- API mobile: aditif; hanya pesan galat stok lebih panjang (kunci `products.N.qty` tidak berubah); `date` ISO dari aplikasi dinormalisasi ke UTC.


---

## 19. PERTANYAAN UNTUK RANDY (menggantikan 0.5 dan 7.9; tidak memblokir implementasi; default dipakai bila belum dijawab)

### 19.1 Fakta produksi (bukan keputusan; hanya Randy yang bisa membacanya; hasilnya menentukan keselamatan rollout)
| # | Pertanyaan / perintah | Mengapa penting |
|---|---|---|
| F1 | Commit atau tag apa yang berjalan di produksi, dibangun sebelum atau sesudah penghapusan tenancy (`0457dfd`, 2026-10-14)? Database apa yang ditunjuk `.env` (`DB_DATABASE`), dan apakah tabel `migrations`-nya memuat nama migrasi tenant (mis. `2025_10_03_042817_remove_barcode_from_products_table`)? Kirim `php artisan migrate:status` atau `php artisan pos:preflight --pre`. | Menentukan migrasi mana yang Pending; satu migrasi lama menjatuhkan kolom. Gerbang `pos:preflight --pre` berhenti bila ada yang tak diizinkan. |
| F2 | Apakah `2026_10_14_000001_add_pos_performance_indexes` sudah Ran di produksi? (Ia ter-commit 2026-10-15 setelah deploy kemarin.) | Menentukan apakah perintah pertama pada langkah 4 dijalankan. |
| F3 | Hasil `SELECT VERSION(), @@system_time_zone, @@global.time_zone, @@transaction_isolation, @@innodb_lock_wait_timeout, @@innodb_default_row_format, @@sql_mode;`, query engine/row-format (harus kosong), `COUNT(*)` `sellings`/`selling_details`, `MAX(sellings.id)`. | Versi/engine/format menentukan keamanan DDL; isolasi dan lock timeout menentukan perilaku kunci. |
| F4 | `CACHE_DRIVER` produksi. | Cache hanya pagar UX sekarang, tetapi draft dan Setting memakainya. |
| F5 | Apakah toko, atau aplikasi mobile (sinkronisasi offline), membuat penjualan antara 00:00-07:00 WIB atau dengan `date` hari lalu? | Menentukan seberapa penting lompatan nomor hari deploy dan kunci global. |
| F6 | Topologi printer: satu printer di tablet kasir, printer dapur terpisah di perangkat lain, atau pelayan memesan dari HP tanpa printer? | Tiket dicetak di perangkat yang menekan tombol; HP tanpa printer memakai "Tandai terkirim tanpa cetak" (default sampai ada antrean printer dapur). |
| F7 | Apakah beberapa kasir/perangkat memakai SATU akun login yang sama? | Menentukan seberapa serius keranjang pribadi dipakai bersama (sekarang dilindungi hook dan kunci keranjang). |

### 19.2 Keputusan produk (bawaan = yang sudah dibangun; ubah bila tidak cocok)
| # | Pertanyaan | Bawaan yang direkomendasikan | Bila diubah |
|---|---|---|---|
| B1 | Bill dibuka 23:55 dan dibayar 00:05: omzet/stok/laci dan nomor ikut hari PEMBAYARAN atau hari PESAN? | Hari pembayaran (nomor baru; label tanggal tampil di daftar/tiket cetak ulang) | Hari pesan butuh jalur backdate penjualan: pekerjaan tambahan, risiko laporan |
| B2 | Siapa boleh membatalkan item yang sudah dikirim ke dapur dan membatalkan bill terkirim? | Pengguna dengan izin `delete selling` atau `update selling`, atau pembuat bill dalam 10 menit sejak kiriman terakhir; alasan SELALU wajib dan tercatat | Hanya pemilik (lebih ketat) atau semua kasir (hanya jejak audit) |
| B3 | Stok kurang saat bill dibayar padahal makanan sudah disajikan: blokir atau izinkan? | Izinkan dengan override eksplisit oleh pengguna `update selling`/pemilik (stok minus, tercatat); selain itu blokir dengan nama produk | Blokir selalu: kasir tak bisa menerima uang untuk makanan yang sudah dimakan |
| B4 | Harga dan pajak bill: tetap seperti saat dipesan, atau selalu harga saat bayar? | Harga saat dipesan (selisih qty tambahan memakai harga hari ini); pajak dibekukan saat bill dibuat | Selalu harga saat bayar: bon sementara bisa berbeda dari struk |
| B5 | Setelah Simpan atau kirim pertama ke dapur: layar kembali ke order baru kosong? | Ya (mencegah item pelanggan berikutnya masuk bill sebelumnya) | Tetap di dalam bill + peringatan "Tambah ke Bill #12?" |
| B6 | Kosakata: "Bill / Bill sementara" atau "Bon"? | Bill / Bill sementara ("bon" berarti hutang di warung dan aplikasi punya Kredit) | Mengganti kunci terjemahan saja |
| B7 | Bawa pulang: tanpa meja dan tanpa nama = tertulis BAWA PULANG, dengan prompt konfirmasi bila keduanya kosong? | Ya | Wajib isi meja atau nama |
| B8 | Pindah meja setelah kirim mencetak "PINDAH: meja 4 -> 6" di tiket berikutnya; gabung/pisah bill tidak didukung sekarang? | Ya | Gabung/pisah = fitur lanjutan |
| B9 | Harga di tiket dapur? | Tidak tampil (toggle di Pengaturan Printer) | Nyalakan toggle |
| B10 | Diskon pada penjualan kredit: piutang memakai jumlah SESUDAH diskon (memperbaiki angka lama yang lebih besar dari tagihan)? | Ya (hanya piutang baru; tanpa penulisan ulang data) | Tolak -> modal menyembunyikan "Sisa piutang" saat ada diskon dan perilaku lama dipertahankan |
| B11 | Pembulatan rupiah: pertahankan 2 desimal seperti sekarang (pajak 11% dari 33.333 = 3.666,63) atau bulatkan ke rupiah penuh? | Pertahankan (tidak mengubah data/laporan lama; "Uang pas" mengisi angka server) | Pembulatan: perubahan perilaku laporan, butuh keputusan akuntansi |
| B12 | Label "Dari kemarin" setelah berapa jam? | 6 jam setelah tengah malam; sebelum itu "Lewat tengah malam" | Ubah `pos.stale_after_hours` |

Catatan: "apakah metode kredit ditandai `is_cash`" tidak lagi menentukan hasil (kredit dicabangkan lebih dulu); tidak perlu dijawab.

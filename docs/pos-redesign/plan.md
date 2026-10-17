# Rencana: Kasir v2 (thumbnail list, filter kategori, catatan per item, sort kategori, nama pelanggan, cetak terpadu)

Branch `feat/pos-redesign-perf`, repo `C:\webapp\lakasir-rnd` (Laravel 11.x, Filament 3.3.50, Livewire 3.8.0, SQLite, Herd PHP 8.4).
Author: tech-analyst. Status: spec + plan, no repo file was edited (read-only phase).

> **REVISI (setelah 2 verdict devils-advocate): bagian 11 "Revision after challenge" di akhir file adalah sumber kebenaran.** Bila bagian 2, 3, 4, 5, 6, 7, 8, 9 bertentangan dengan bagian 11, bagian 11 yang berlaku. Perubahan terbesar: daftar produk dan catatan item menjadi komponen Livewire anak (bukan trait di komponen Cashier), partial cetak dipasang lewat render hook (bukan `@include`), paket kerja dipecah menjadi 9 paket dalam 3 gelombang.

## 0. Ringkasan untuk Randy (Indonesia)

1. Overflow stepper `+/-` dan gambar: akar masalahnya lebar tetap (`xl:w-[200px]`, stepper 36+26+36 px di kartu ~150 px). Diganti kartu `min-w-0` + `overflow-hidden`, stepper `grid` 3 kolom, gambar `aspect-ratio` + `object-cover`.
2. Dua tampilan produk: **Kotak** (kartu) dan **Daftar ringkas** (baris 56 px dengan thumbnail 40 px). Pilihan disimpan di `localStorage` per perangkat.
3. Chip kategori (scroll horizontal, "Semua" di depan) digabung dengan kotak cari (AND). Daftar produk jadi Livewire custom (bukan tabel Filament) dengan "muat lebih banyak" otomatis.
4. Nama pelanggan: penyebab hilang/berubah ditemukan (bagian 2). Perbaikan: nama disimpan apa adanya, penomoran "(2)" hanya dilakukan sekali di server saat transaksi dibuat, draft nama/meja/catatan disimpan per kasir sehingga tidak hilang saat reload.
5. Catatan per item: kolom `note` di `cart_items` dan `selling_details`, tampil di keranjang, struk, tampilan detail penjualan, dan API (aditif).
6. Keranjang dan struk diurutkan per kategori (A-Z, lalu nama produk; tanpa kategori paling akhir) dengan judul kelompok. Struk cetak dibuat satu fungsi JS bersama (partial Blade) yang dipakai halaman kasir dan halaman detail penjualan.
7. Tidak ada pertanyaan yang memblokir. Default yang dipilih ada di bagian 9 (bisa diubah belakangan).

---

## 1. Temuan penting dari kode (bukan tebakan)

### 1.1 Alur data

| Topik | Fakta (file:baris) |
|---|---|
| Kategori produk | `products.category_id` dibuat `foreignId()->constrained()` NOT NULL (`2022_08_07_062328_create_products_table.php:18`), tidak pernah dibuat nullable. `categories` hanya `id, name, timestamps`, tidak ada kolom urutan. Jadi "tanpa kategori" hampir tidak mungkin secara DB, tetapi kode pengurut harus tetap null-safe (taruh paling akhir). Urut: nama kategori A-Z natural case-insensitive. `ProductCollection` (API) sudah mengirim `category` dan `category_id`. |
| Cart -> selling | `Cashier::proceedThePayment` (Cashier.php:330) membentuk `products[]` dari `CartItem` (product_id, qty, price, discount_price, price_unit_id) -> `SellingService::mapProductRequest` -> `SellingService::create` -> `Selling::create($data)` -> event `SellingCreated` -> `AssignProduct` (Listeners/AssignProduct.php) membuat `SellingDetail` dengan `$sellingDetail->fill($productRequest)`. `SellingDetail` memakai `$guarded = []`, jadi kolom `note` baru otomatis ikut ter-`fill` begitu kolom ada. |
| API | `POST /api/transaction/selling` -> `TransactionSellingStoreRequest` (rules `products.*.*`, tidak ada `note`) -> `SellingService::create(all())` (jalur yang sama). Response lewat `SellingCollection` + `SellingDetailCollection` (tanpa `note`). Test: `tests/Feature/Http/Controllers/Api/Tenants/Transaction/SellingControllerTest.php`, `CustomerNameSellingTest.php`. |
| Pembaca selling_details | `view-selling.blade.php` (tabel + JS cetak), `ViewSelling.php` (load relasi), `SellingDetailResource/RelationManagers/SellingDetailsRelationManager.php`, `SellingCollection/SellingDetailCollection`, `CashierReportService`, `ProductReportService`, `SellingReportService` (agregat saja, tidak perlu catatan), `Cashier::proceedThePayment` (dispatch `selling-created` dengan `sellingDetails.product`), `FillSellingMissingValue` (command, abaikan). Tidak ada view invoice/PDF lain; `pages/selling/print-receipt.blade.php` hanya halaman demo USB ("Hello, thermal printer!") dan route `/member/sellings/{selling}/print` (routes/web.php:28). Tidak disentuh. |
| Cetak struk | Dua salinan JS inline hampir identik: `handleCashierPrintReceipt` (cashier.blade.php:854-1010) dan `handlePrintReceipt` (view-selling.blade.php:141-285). Keduanya memakai `getPrinter()` (resources/js/app.js, Filament asset `custom-javascript`) dan class `Printer` (resources/js/printer.js, `table/text/size/align/style/cut/print`, `lineWidth` 32 atau 48). Catatan: `public/js/app/custom-javascript.js` adalah salinan terpublikasi dari `resources/js/app.js` (`php artisan filament:assets`), jadi JS baru ditaruh di partial Blade supaya tidak butuh langkah build aset. `view-selling` mendefinisikan `function formatReceiptMoney` global yang menimpa `window.formatReceiptMoney` milik app.js (hard-code "Rp "). Struk sekarang TIDAK mencetak catatan global. |
| Pemakai CartInteraction | `Cashier`, `POS` (V2, override `refreshCart` sendiri), `CartItem` page (override `refreshCart`). Perubahan trait wajib kompatibel dengan POS/CartItem: hanya menambah method baru, tidak mengubah signature publik (`addCart(Product, ?array)`, `reduceCart(Product)`, `deleteCart(CartItem)`, `clearCart()`, `addCartUsingScanner(string)`). |
| Tailwind | Kelas Tailwind dipindai dari `resources/views/**/*.blade.php` dan `app/Filament/**`; CSS di-build Vite (`public/build`). Kelas baru butuh `npm run build` (paket verifikasi yang menjalankan). |

### 1.2 Penyebab nama pelanggan hilang atau berubah (dengan bukti)

1. **Nama di modal pembayaran tidak ikut terkirim pada klik "Pay it" di Safari/iOS (dan saat fokus tetap di input).** Input: `<x-filament::input wire:model.blur="cartDetail.customer_name">` (cashier.blade.php:427). Di Livewire 3.8 `.blur` hanya menyalin nilai ke state klien saat event `blur` (livewire.js:3482, 10928), dan Alpine hanya mem-flush update tertunda saat event `submit` form (livewire.js:3330). Tombol "Pay it" adalah `type="button"` dengan `x-on:click="submitPayment"` (bukan submit), dan `submitPayment()` hanya men-set `payment_method_id`, `payed_money`, `money_changes`. Tombol di Safari/iOS tidak mengambil fokus saat disentuh sehingga `blur` tidak terjadi dan nilai nama belum ada di state saat `proceedThePayment()` jalan. Hasil: transaksi tersimpan tanpa nama. Perbaikan: `submitPayment()` membaca nilai input lewat `x-ref` dan memanggil `$wire.set('cartDetail.customer_name', value, false)` sebelum `proceedThePayment()` (pola yang sama dengan `payed_money`).
2. **Modal "Edit detail" tidak menyimpan saat ditutup selain lewat tombol Save.** `TextInput::customer_name` deferred (tanpa `live`), tersimpan hanya via `storeCart` (form `wire:submit.prevent`). Menutup modal lewat klik di luar atau ESC meninggalkan teks di DOM tetapi panel tetap menampilkan "-". Nilai itu baru terkirim diam-diam bersama request Livewire berikutnya (misalnya tambah item), sehingga nama "muncul sendiri" belakangan. Perbaikan: `commitCustomerName()` dipanggil pada `change`/Enter/blur dan saat modal ditutup.
3. **Penomoran harian diterapkan di 4 tempat dan menjadi "pratinjau" yang bisa berubah.** `updatedCartDetailCustomerName` (Cashier.php:323), `storeCart` (:238), `proceedThePayment` (:346), dan `Selling::creating` (Selling.php:48). Karena `generateDailyCustomerName` menurunkan basis nama dengan membuang akhiran ` (n)` lalu menghitung dari DB, hasilnya idempoten selama DB tidak berubah (tidak bertambah menjadi "Budi (2) (2)"), tetapi: (a) kolom input ikut diubah menjadi "Budi (2)" saat kasir masih mengetik/menyentuh field; (b) setiap kali kasir menekan Save di modal Edit detail untuk mengubah catatan saja, nama dihitung ulang dan bisa melompat ke "Budi (3)" bila kasir lain sempat menyimpan "Budi (2)"; (c) nama yang memang berakhiran angka dalam kurung, misalnya "Meja (5)", dipotong menjadi "Meja" bila belum ada duplikat (`CustomerNameService` baris `if ($existingNames->isEmpty()) return $baseName;`) sehingga terlihat "rusak" (perilaku ini dikunci test, tidak diubah); (d) nomor final tetap ditentukan `Selling::creating`, jadi pratinjau dan hasil akhir bisa berbeda.
4. **State header order (nama, meja, catatan, member) hanya ada di properti Livewire.** `mount()` mengisi ulang semuanya (Cashier.php:83-160) pada load awal dan di akhir `proceedThePayment` (:426). Tombol "Reload" di menu kasir (`document.location.reload()`) dan refresh browser menghapus nama/meja/catatan sementara item keranjang tetap ada di DB (kondisi tidak konsisten). Setelah perf work, aksi keranjang memakai `refreshCart()` (tidak lagi `mount()`), jadi "mount() dipanggil ulang setelah aksi keranjang" sudah tidak terjadi di tree sekarang; tetap ada: load awal, reload, dan akhir pembayaran.
5. `storeCart`/`proceedThePayment` membungkus `getState()` dengan `try/catch (\Throwable) {}` kosong dan `array_filter` membuang nilai kosong sebelum merge: kegagalan validasi form (mis. diskon non-numerik) hilang tanpa pesan dan kolom yang dikosongkan tidak pernah menimpa state tersimpan. Perbaikan: jangan menelan exception; pakai `$this->storeCartForm->getState()` di dalam try yang menampilkan notifikasi.

### 1.3 Temuan di luar scope (dicatat, tidak dikerjakan kecuali disebut)

- `RefreshThePage::getListeners()` memanggil `calculateTotalPrice()` di setiap request, dan `private float $discount_price` tidak persisten: voucher dan diskon manual yang dihitung di `storeCart` hilang pada request berikutnya (total ter-reset ke total tanpa voucher/diskon). `mapProductRequest` menghitung ulang di server sehingga nominal akhir benar, tetapi angka yang ditampilkan bisa tidak sinkron. Lapor ke Randy sebagai item terpisah.
- `CartInteraction::updateCart` memakai `$cartItem->product->qty` (atribut tidak ada) dan tidak dipanggil dari view: kode mati.
- `addCart` dengan `amount = 0` menghapus `CartItem::whereProductId(...)->first()` TANPA scope `cashier()` (bisa menghapus keranjang kasir lain, atau TypeError bila null). `reduceCart` crash jika item sudah tidak ada. Keduanya diperbaiki oleh paket backend karena file-nya sama (perubahan kecil).
- `Product::heroImage` fallback ke gambar `cdn4.iconfinder.com` (butuh internet, lambat). Daftar produk memakai placeholder SVG lokal bila `hero_images` kosong.
- Tidak ada thumbnail ter-resize; gambar penuh dimuat meski ditampilkan 40 px. Mitigasi: `loading="lazy"` + `decoding="async"` + batas 24 baris per halaman. Pembuatan thumbnail = tindak lanjut opsional, di luar scope.

---

## 2. Keputusan arsitektur

### (a) Daftar produk: Livewire custom (trait di komponen Cashier) vs tabel Filament

**Rekomendasi: ganti tabel Filament dengan trait `ProductBrowser` di komponen `Cashier` (bukan komponen anak).**

Alasan: tabel Filament (`TableProduct`) memaksa paginasi 12 baris dengan klik halaman, layout `Stack`/`contentGrid` yang tidak bisa diubah menjadi baris ringkas, lebar gambar tetap (sumber overflow), action modal "insert_amount" per baris, dan tidak punya chip kategori. Hasil riset: kontrol markup penuh diperlukan untuk grid/list, chip, dan stepper responsif.

Menjaga perf: query tetap `Product::query()->withStockTotal()->sellableInPos()` (satu subquery stok per baris sudah di-index `stocks_pos_lookup_index`), ditambah filter kategori (indeks baru `products_category_listing_index`), `limit(N + 1)` untuk mendeteksi `hasMore` tanpa `count(*)`, `orderBy name, id`. Peta qty keranjang tetap satu query (`cartQtyMap`). Batas maksimum 240 baris, selebihnya kasir memakai cari/kategori. Anggaran: render awal dan `addCart` tidak boleh menambah query seiring jumlah produk (tes jumlah query konstan).

Alternatif yang ditolak: komponen Livewire anak untuk daftar produk (mengisolasi query dari aksi keranjang). Lebih hemat untuk `addCart`, tetapi menambah state sinkron (peta qty lewat event) dan risiko morph Alpine. **Rencana B** bila `CashierPerfTest` menunjukkan `addCart` melambat dibanding baseline: pindahkan trait menjadi komponen anak `App\Livewire\Cashier\ProductBrowser` dengan `$wire.$parent.addCart`.

Fitur tabel Filament yang dipertahankan: pencarian SKU/nama/barcode (barcode memakai prefix match, aktif saja), kolom stok dan peringatan stok rendah (`minimum_stock_nofication`), nonaktifkan stok untuk `is_non_stock`. Fitur baru: Enter di kotak cari dengan kode barcode/SKU persis langsung menambah ke keranjang (kompatibel scanner USB keyboard). Dialog "insert amount" dihapus (sesuai mock yang sudah disetujui; qty bisa diketik di keranjang).

### (b) Tempat pengurutan

- **Keranjang**: koleksi PHP, bukan join SQL. `CartInteraction::loadCartItems()` memuat `with(['product.category','priceUnit'])` lalu `OrderLineSorter::sort()`. Alasan: N kecil (puluhan), kolasi DB-agnostik (SQLite BINARY vs MySQL), helper yang sama dipakai `SellingDetail`, dan tidak merusak hidrasi koleksi Eloquent Livewire (urutan kunci dipertahankan oleh `EloquentCollectionSynth::loadCollection`). View memakai `OrderLineSorter::group()` pada setiap render sehingga urutan tidak bergantung pada hidrasi.
- **Cetak dan detail penjualan**: urut di server satu kali. `Selling::loadForReceipt()` memuat `sellingDetails.product.category` lalu mengurutkan relasi dengan `OrderLineSorter`. JSON yang dikirim ke JS (`selling-created` dan `window._lakasirViewSelling`) sudah terurut; JS hanya mengelompokkan baris berurutan berdasarkan `product.category.name` TANPA mengurutkan lagi (satu komparator saja, menghindari beda `localeCompare` vs PHP). Judul kelompok dicetak hanya jika ada >= 2 kategori berbeda pada pesanan.
- Komparator: kunci `[kategori null ? 1 : 0, strnatcasecmp(kategori), strnatcasecmp(nama produk), id baris]`. Pesanan baru juga tersimpan terurut karena `products[]` dibentuk dari keranjang terurut.
- Karena urutan keranjang berubah dari "terbaru di atas" ke "per kategori", item yang baru ditambah bisa berada di tengah daftar: `addCart` mengirim event browser `cart-item-touched` dan view menggulir plus menyorot baris itu.

### (c) Catatan per item

- Kolom: `cart_items.note` string(255) nullable, `selling_details.note` string(255) nullable.
- Normalisasi di model (mutator pada trait `HasOrderLine`): buang karakter kontrol (`[\x00-\x1F\x7F]` jadi spasi, mencegah injeksi perintah ESC/POS), rapikan spasi, `trim`, potong 255, kosong jadi `null`. Sama untuk jalur API karena lewat model.
- Livewire: `#[Renderless] setItemNote(int $cartItemId, ?string $note = null): void`. Mencari `CartItem::cashier()->find($id)` (item kasir lain atau tidak ada = diam saja), mengisi `note`, `save()`. Renderless: tanpa render ulang dan tanpa `refreshCart()`/`mount()`, jadi tidak ada flicker, tidak ada query daftar produk, dan tidak mengganggu fokus textarea. Klien memanggil dari Alpine dengan debounce 600 ms dan flush saat `blur`; tampilkan indikator "Tersimpan".
- `addCart`/`reduceCart` memakai `updateOrCreate` yang hanya mengisi `qty`, `price`: catatan tidak tertimpa.
- Alur akhir: `proceedThePayment` menambah `'note' => $cartItem->note` di `products[]`; `AssignProduct` mengisi `selling_details.note`; API menerima `products.*.note` (nullable|string|max:255) dan `SellingDetailCollection` mengembalikan `note`.
- Wajib: `wire:key` stabil pada baris keranjang (`"cart-item-{$item->id}"`). Kode lama memakai `key="{{ rand() }}"` dan id input `...-qty-{{ rand() }}` yang membuat DOM dibuat ulang tiap render dan akan menghapus textarea catatan yang sedang diketik.

### (d) Persistensi nama pelanggan

Satu tempat penomoran: **`Selling::creating`** (sudah berlaku untuk API dan kasir; tidak diubah). Seluruh penomoran di `Cashier` dihapus: hook `updatedCartDetailCustomerName`, pemanggilan di `storeCart` dan `proceedThePayment`. State menyimpan teks yang diketik kasir (di-`trim`, maks 255).

- `commitCustomerName(?string $name)`: set `cartDetail.customer_name` (null bila kosong), simpan draft, dan hitung `customerNameHint` (hanya di sini dan di `storeCart`, bukan di setiap render): `generateDailyCustomerName($name)` bila berbeda dari ketikan, tampil sebagai "Akan dicatat sebagai Budi (2)". Hanya informasi, tidak mengubah isi input.
- `CashierDraftService` (cache file, kunci `cashier-draft:{userId}`, TTL 12 jam) menyimpan `customer_name, note, member_id, table_id`. Dimuat di `mount()`, ditulis di `storeCart`, `commitCustomerName`, `selectTable`, `saveCustomTable`; dihapus di `clearCart` dan setelah pembayaran sukses. Tidak butuh migrasi dan tahan reload.
- `submitPayment()` (JS) selalu mem-flush nilai input nama: `$wire.set('cartDetail.customer_name', ref.value, false)`.
- `proceedThePayment` menambah validasi `customer_name` nullable|string|max:255 dan tidak lagi menelan exception.
- Test lama `cashier Livewire component auto formats duplicate customer names` (menegaskan `Andi (2)` sesudah `set`) harus diubah: setelah `set` nilai tetap `Andi`; `Andi (2)` muncul di baris `sellings` setelah pembayaran.

Catatan global (`note`): ganti `RichEditor` dengan `Textarea` (maks 500 karakter) karena HTML tidak bisa dicetak di thermal. Tampilan memakai `{{ }}` + `whitespace-pre-line` (hapus `{!! !!}` di `detail.blade.php:36`). Data lama berisi HTML: cetak/tampil melewati `htmlToPlain`/`strip_tags`.

### (e) Cetak terpadu

Partial Blade tunggal `resources/views/filament/tenant/partials/receipt-print.blade.php` (berisi `<script>`, idempoten dengan guard `window.LakasirReceipt?.version`), di-`@include` sekali oleh `cashier.blade.php` dan `view-selling.blade.php`. API di bagian 4.5. Kedua inline JS lama dihapus. Test statis menjaga: string `.cut()` dan `printerAction`/`handleCashierPrintReceipt`/`handlePrintReceipt` hanya ada di partial.

### (f) Migrasi

1. `2026_10_15_000001_add_note_to_cart_items_and_selling_details_tables.php`
2. `2026_10_15_000002_add_category_index_to_products_table.php` (index `products_category_listing_index` pada `category_id, show`)

Penerapan ke DB dev (`database/database.sqlite`) hanya oleh paket verifikasi, dengan `php artisan migrate --path=database/migrations/<file>` per file (AGENTS.md memperingatkan migrasi pending lain dapat men-drop tabel), setelah proses test suite lain selesai.

---

## 3. Spesifikasi UI (untuk paket desain dan paket view)

Mock acuan (disetujui "lumayan bagus"): `scratchpad/kasir-mock.html`. Perubahan wajib:

- Breakpoint: `< md` (768) satu kolom + dock bawah + panel pesanan sebagai bottom sheet; `>= md` produk di kiri, pesanan tetap di kanan (lebar `md:w-[22rem] lg:w-[26rem]`, bukan 1/3 layar). Satu DOM panel pesanan (CSS yang berubah), bukan dua salinan, agar id dan `wire:key` tidak ganda.
- Header produk: kotak cari (placeholder `Search (SKU, name, barcode)`), tombol scan kamera, toggle tampilan (ikon grid/list, `data-testid="view-toggle-grid|list"`).
- Chip kategori: `Semua` + kategori (A-Z), satu baris scroll horizontal (`overflow-x-auto`, `snap-x`, scrollbar disembunyikan), aktif = `bg-lakasir-primary text-white`. `data-testid="category-chip-all|{id}"`.
- Kartu (grid): `grid-cols-[repeat(auto-fill,minmax(9.5rem,1fr))] gap-2`; kartu `min-w-0 overflow-hidden`. Gambar: wrapper `relative aspect-[4/3] overflow-hidden rounded-lg bg-gray-100` dan `<img class="absolute inset-0 h-full w-full object-cover" loading="lazy" decoding="async">`; tanpa gambar = SVG placeholder lokal. Nama maks 2 baris (`line-clamp-2`), harga satu baris `tabular-nums truncate`. Aksi: bila belum di keranjang = tombol `+` 44 px (lebar penuh kanan); bila sudah = stepper `grid grid-cols-[2.75rem_minmax(0,1fr)_2.75rem]` dengan angka `min-w-0 text-center tabular-nums truncate` sehingga angka 4 digit tetap muat tanpa overflow (perbaikan poin 1).
- Baris ringkas (list): tinggi 56 px, thumbnail 40x40 `shrink-0 rounded-md object-cover`, nama `min-w-0 flex-1 truncate`, baris kedua kecil: harga + stok, kanan: tombol `+` atau stepper 36 px. Seluruh target sentuh >= 40 px.
- Keranjang: dikelompokkan per kategori (judul kecil huruf kapital `data-testid="cart-group-{slug}"`), tiap baris: nama, harga, stepper, tombol catatan (ikon pensil; bila ada isi tampil teks miring "tanpa gula" di bawah nama), hapus, tombol harga (`<livewire:price-setting>` tetap). Textarea/input catatan membuka inline di baris itu (`data-testid="cart-item-note-{id}"`).
- Chip header order (nama pelanggan, meja, catatan pesanan, diskon, voucher, member) tetap; nama pelanggan menampilkan `customerNameHint` kecil di bawahnya.
- Mode gelap: pakai kelas `dark:` Tailwind seperti halaman sekarang.
- ID DOM yang harus tetap: `printReceiptButton`, `qr-reader`, modal `edit-detail`, `proceed-the-payment`, `success-modal`, `modal-selected-table`, `qr-scanner-modal`.
- localStorage: kunci `lakasir.cashier.productView` = `grid` | `list` (default `grid`; dibungkus try/catch).
- Infinite scroll: elemen sentinel `x-intersect.margin.200px="$wire.loadMoreProducts()"` hanya bila `$hasMoreProducts` + tombol "Load more" sebagai cadangan; guard `wire:loading` agar tidak memicu ganda.

---

## 4. KONTRAK

### 4.1 Skema DB

```
cart_items.note          string(255) NULL   (after discount_price)
selling_details.note     string(255) NULL
products INDEX products_category_listing_index (category_id, show)
```
Migrasi memakai guard `Schema::hasColumn`/`hasIndex`; `down()` membalik.

### 4.2 Model dan service (paket backend)

`App\Models\Tenants\Concerns\HasOrderLine` (trait baru, dipakai `CartItem` dan `SellingDetail`):
- `note(): Attribute` (hanya `set`): `null` -> null; string -> ganti `[\x00-\x1F\x7F]+` dengan spasi, `\s+` jadi satu spasi, `trim`, `mb_substr(0,255)`, `''` -> null.
- `categoryName(): ?string` -> `$this->product?->category?->name` (method biasa, tidak di `$appends`).

`CartItem`: tambah `'note'` ke `$fillable`. `SellingDetail`: tidak ada perubahan selain trait.

`Selling::loadForReceipt(): static` -> `load(['sellingDetails.product.category','paymentMethod','user','member','table'])`, lalu `setRelation('sellingDetails', OrderLineSorter::sort($this->sellingDetails))`, `return $this`.

`Product`:
- `scopeSearchInPos(Builder $b, string $term): Builder` -> `where(function($q) use ($term){ $q->where('products.name','like',"%$term%")->orWhere('products.sku','like',"%$term%")->orWhereExists(fn($s)=>$s->selectRaw('1')->from('barcodes')->whereColumn('barcodes.product_id','products.id')->where('barcodes.is_active',true)->where('barcodes.code','like',"$term%")); })`. Escape `%` dan `_` pada `$term`. Term kosong = tidak memfilter.
- `scopeInCategory(Builder $b, ?int $categoryId): Builder` -> null = no-op, selain itu `where('products.category_id', $id)`.

`App\Services\Tenants\OrderLineSorter` (final, semua static):
- `sort(Illuminate\Support\Collection $lines): Collection` (mengembalikan kelas koleksi yang sama, `values()`), elemen `CartItem|SellingDetail` dengan `product.category` sudah dimuat (relasi yang tidak dimuat dianggap tanpa kategori; tidak boleh memicu query: pakai `relationLoaded`).
- `group(Collection $lines): Collection` -> daftar `['category' => ?string, 'lines' => Collection]` berurutan, kunci numerik, grup null terakhir.
- `compare($a, $b): int` (publik untuk test).

`App\Services\Tenants\CashierDraftService` (instance, `app()`):
- `load(int|string $userId): array` -> hanya kunci `customer_name, note, member_id, table_id` (default `[]`).
- `save(int|string $userId, array $fields): void` -> merge ke draft lama, whitelist kunci di atas, `Cache::put("cashier-draft:{$userId}", ..., now()->addHours(12))`; nilai null/'' menghapus kuncinya.
- `forget(int|string $userId): void`.

`Selling::creating` dan `CustomerNameService`: TIDAK diubah.

### 4.3 Komponen `App\Filament\Tenant\Pages\Cashier`

Kelas tidak lagi `implements HasTable`, tidak memakai `TableProduct`/`InteractsWithTable` (file `Traits/TableProduct.php` dihapus; trait baru `Traits/ProductBrowser.php`). `HasForms` tetap (`storeCartForm`).

Properti publik baru: 
```php
public string $productSearch = '';
public ?int $categoryId = null;
public int $productLimit = 24;           // const PRODUCT_PAGE_SIZE = 24, PRODUCT_MAX = 240
public array $categoryChips = [];        // list<array{id:int,name:string}> A-Z natural, hanya kategori yang punya produk show=true & belum soft-deleted
public ?string $customerNameHint = null;
```
Properti lama tetap: `cartItems` (Eloquent Collection, SUDAH terurut), `cartDetail`, `members`, `tableOption`, `paymentMethods`, `total_price`, `sub_total`, `tax`, dll. Dihapus: hook `updatedCartDetailCustomerName`.

Method publik (nama dan signature final):
```php
public function selectCategory(?int $categoryId = null): void      // id tak dikenal di $categoryChips -> null; productLimit = 24
public function loadMoreProducts(): void                            // productLimit = min(productLimit + 24, 240)
public function updatedProductSearch(): void                        // trim, productLimit = 24
public function searchEnter(): void                                 // Product::findByBarcodeOrSku(trim(productSearch)); ketemu -> addCartUsingScanner(code), productSearch=''
#[Renderless] public function setItemNote(int $cartItemId, ?string $note = null): void
public function commitCustomerName(?string $name = null): void     // trim, null bila kosong, simpan draft, set customerNameHint
public function storeCart(): void                                   // tanpa penomoran; tidak menelan exception; simpan draft; customerNameHint
public function selectTable(?int $tableId = null): void            // + simpan draft
public function saveCustomTable(?string $customNumber = null): void // + simpan draft
public function clearCart()                                         // + forget draft
public function proceedThePayment(SellingService $sellingService): void  // signature TIDAK berubah
public function mount()                                             // tetap publik; memuat draft
```
Perilaku `proceedThePayment`: `products[]` dari `$this->cartItems` (urutan terurut) dengan kunci `product_id, qty, price, discount_price, price_unit_id, note`; validator ditambah `'customer_name' => ['nullable','string','max:255']`; setelah sukses: hapus `CartItem::cashier()`, `forget` draft, reset order via metode privat `resetOrder()` (muat ulang keranjang kosong, isi ulang form default, `customerNameHint = null`, `productLimit = 24`) -- tidak memanggil `mount()` penuh (menghindari `PaymentMethod::firstOrCreate` dan query berulang); dispatch `close-modal` (proceed-the-payment), `open-modal` (success-modal), `selling-created` dengan `$selling->loadForReceipt()`.

`CartInteraction` (trait, aditif):
```php
protected function loadCartItems(): \Illuminate\Database\Eloquent\Collection  // CartItem::query()->with(['product.category','priceUnit'])->cashier()->get() lalu OrderLineSorter::sort(); dipakai mount() dan refreshCart()
protected function cartQtyMap(): array                                        // product_id => qty, satu query per request (menggantikan cartQtyFor yang private)
```
`addCart`: dispatch `cart-item-touched` (`productId:`) setelah sukses; `amount` kosong/0 menghapus item `->cashier()` (null-safe). `reduceCart`: null-safe, dispatch `cart-item-touched` bila item masih ada. `refreshCart()` tetap menjadi satu-satunya reload; POS dan CartItem page tidak berubah.

`Cashier::getViewData()` (override, `protected`) mengembalikan variabel Blade berikut (view TIDAK boleh memanggil method publik komponen untuk data):
```php
'products'        => \Illuminate\Support\Collection<Product>   // maks productLimit baris; tiap Product punya ->stock_total (float), ->hero_images (array), ->hero_image (url fallback)
'hasMoreProducts' => bool
'cartQty'         => array<int|string, float>                 // product_id => qty
'cartGroups'      => \Illuminate\Support\Collection           // OrderLineSorter::group($this->cartItems): [['category'=>?string,'lines'=>Collection<CartItem>], ...]
'cartCount'       => int                                      // jumlah baris keranjang
'cartQtyTotal'    => float                                    // total qty
'lowStockThreshold' => int                                    // Setting::get('minimum_stock_nofication', 10)
```
Urutan query produk: `Product::query()->select('products.*')->withStockTotal()->sellableInPos()->inCategory($categoryId)->when(trim($productSearch) !== '', fn($q)=>$q->searchInPos(trim($productSearch)))->orderBy('products.name')->orderBy('products.id')->limit($productLimit + 1)->get()`.

Event browser (dispatch dari Livewire): `cart-item-touched {productId:int}`, `selling-created {selling:object}` (sudah ada), Filament `open-modal`/`close-modal` (sudah ada). `selling.selling_details` terurut dan tiap elemen punya `note`, `product.name`, `product.category.name|null`.

Terjemahan (`lang/id.json`, `lang/en.json`): hanya paket view yang mengedit file ini. Kunci baru (kunci EN -> Indonesia):
`All`->`Semua`; `Grid view`->`Tampilan kotak`; `Compact list`->`Daftar ringkas`; `Load more`->`Muat lebih banyak`; `No products found`->`Produk tidak ditemukan`; `No products in this category`->`Belum ada produk di kategori ini`; `Item note`->`Catatan item`; `Add note`->`Tambah catatan`; `Item note placeholder`->`Contoh: tanpa gula, pedas, es dipisah`; `Order note`->`Catatan pesanan`; `Uncategorized`->`Tanpa kategori`; `Will be recorded as :name`->`Akan dicatat sebagai :name`; `Note saved`->`Catatan tersimpan`; `Saving...`->`Menyimpan...`; `View order`->`Lihat pesanan`; `:count items`->`:count item`; `Customer name saved`->`Nama pelanggan tersimpan`. Kunci yang sudah ada dipakai ulang tanpa diubah: `Note, Customer, Category, Cashier, Table, Payment method, General, Tax, Tax price, Subtotal, Discount, Total price, Payed money, Change, Search (SKU, name, barcode), Current Orders`. Pakai `__('Key')` (bukan `@lang` di dalam `@js`).

### 4.4 API (aditif, tanpa breaking change)

- `TransactionSellingStoreRequest::rules()`: tambah `'products.*.note' => ['nullable','string','max:255']`.
- `SellingDetailCollection::toArray`: tambah `'note' => $this->note`.
- `SellingController::index/store/show`: eager load `sellingDetails.product.category` (tanpa N+1; bentuk response tidak berubah).

### 4.5 Partial cetak `resources/views/filament/tenant/partials/receipt-print.blade.php` (paket receipt-print)

Di-include tanpa argumen: `@include('filament.tenant.partials.receipt-print')`. Partial membaca sendiri `About::first()`, `Setting::get('receipt_logo'|'receipt_paper_width'|'receipt_show_currency')`, `feature(SellingTax::class)`, `feature(Discount::class)`.

```js
window.LakasirReceiptConfig = {
  about: {shop_name, shop_location} | null,
  showCurrency: bool, logo: string|null, paperWidth: '58'|'80',
  tax: bool, discount: bool, settingsUrl: '/member/printer',
  labels: { cashier, table, paymentMethod, customer, general, tax, taxPrice, subtotal, discount,
            totalPrice, payedMoney, change, note, orderNote, uncategorized, copy,
            printerMissing, noTransaction, failed }   // semua via __()
};
window.LakasirReceipt = {
  version: 1,
  // Alur lengkap: getPrinter() -> notifikasi bila null -> new Printer(printerData) -> logo -> build() -> cut().print()
  // Kunci anti-ganda: window._lakasirHandlingReceipt (3 detik) + window._lakasirIsPrintingNow (dibaca saja).
  // opts: { buttonId?: string, reprint?: boolean }  reprint=true menambah baris "copy" (view-selling)
  print(selling: object, opts?: object): Promise<boolean>,
  // Murni (hanya memanggil method printer): dipakai test dengan printer palsu.
  build(printer: Printer, selling: object, opts?: object): Printer,
  groupDetails(details: object[]): Array<{category: string|null, lines: object[]}>, // tidak mengurutkan
  htmlToPlain(html: string): string,
  toPrintable(text: string): string // buang kontrol, NFKD, non-ASCII dibuang, spasi dirapikan
};
```
Tata letak `build()`: logo (bila driver bukan bluetooth) lalu nama toko, alamat, header; `Order #NNN` besar; garis; Kasir, Meja (bila ada), Pembayaran, Pelanggan (`customer_name` atau nama member atau "General"); **Catatan pesanan** (`htmlToPlain(selling.note)` dibungkus `printer.lineWidth`, hanya bila ada); garis; lalu untuk tiap kelompok: bila kelompok >= 2, judul `printer.style('bold').text('[ ' + NAMA KATEGORI + ' ]').style('normal')` (null -> label `uncategorized`); tiap baris tetap seperti struk lama (`table([nama, 'harga x qty'])`, diskon, total rata kanan) lalu bila `note`: `text('  > ' + note)` dibungkus pada `lineWidth`; setelah semua kelompok: garis, pajak (bila fitur), subtotal, diskon (bila fitur), total, bayar, kembali, footer, `cut()`. Semua string dinamis (nama produk, catatan, nama pelanggan, nama toko) melewati `toPrintable()`. Uang memakai `window.formatReceiptMoney` (app.js) dengan cadangan lokal; tidak lagi mendefinisikan `function formatReceiptMoney` global.

Pemanggil:
- `cashier.blade.php`: `x-on:click.prevent.stop="window.LakasirReceipt.print(window._lakasirLastSelling, { buttonId: 'printReceiptButton' })"` (listener `selling-created` yang menyimpan `window._lakasirLastSelling` tetap di view).
- `view-selling.blade.php`: `window.handlePrintReceipt = () => window.LakasirReceipt.print(window._lakasirViewSelling, { buttonId: 'printButton', reprint: true })` (nama global dan id tombol `printButton`/`printInvoice` tetap karena dipakai `ViewSelling::getHeaderActions`).

### 4.6 Detail penjualan (paket receipt-print)

- `ViewSelling::mount` dan `getRecord`: `$this->record->loadForReceipt()`.
- `view-selling.blade.php`: tabel item memakai `OrderLineSorter::group($record->sellingDetails)`; baris judul kelompok (`<tr><td colspan="5" ...>NAMA</td></tr>`) hanya bila >= 2 kelompok; di bawah nama produk, `@if($detail->note)<span class="text-sm">{{ $detail->note }}</span>@endif` (selalu di-escape); baris detail atas menampilkan `Order note` bila `$record->note` (`{{ strip_tags($record->note) }}`); `window._lakasirViewSelling = @js($record)` (relasi sudah termuat dan terurut).
- `SellingDetailsRelationManager` (SellingDetailResource): tambah kolom `product.category.name` (label `Category`) dan `note` (label `Note`, `wrap`), `modifyQueryUsing(fn($q)=>$q->with('product.category'))`.

### 4.7 Selector uji (data-testid) di view kasir

`product-card-{id}`, `product-row-{id}`, `category-chip-all`, `category-chip-{id}`, `view-toggle-grid`, `view-toggle-list`, `product-search`, `product-load-more`, `cart-group-{slug}`, `cart-item-{id}`, `cart-item-note-toggle-{id}`, `cart-item-note-{id}`, `customer-name-input` (modal pembayaran dan modal edit), `customer-name-hint`, `cart-dock`, `cart-sheet`.

---

## 5. Paket kerja (owner_files saling lepas)

| ID | Pemilik file (hanya ini yang boleh diedit) | Isi |
|---|---|---|
| `backend` | `database/migrations/2026_10_15_000001_*.php`, `database/migrations/2026_10_15_000002_*.php`, `app/Models/Tenants/{CartItem,SellingDetail,Selling,Product}.php`, `app/Models/Tenants/Concerns/HasOrderLine.php`, `app/Services/Tenants/{OrderLineSorter,CashierDraftService}.php`, `app/Filament/Tenant/Pages/Cashier.php`, `app/Filament/Tenant/Pages/Traits/{CartInteraction,ProductBrowser}.php`, hapus `Traits/TableProduct.php`, `app/Http/Requests/Tenants/Sellings/TransactionSellingStoreRequest.php`, `app/Http/Resources/SellingDetailCollection.php`, `app/Http/Controllers/Api/Tenants/Transaction/SellingController.php`, `app/Listeners/AssignProduct.php` (hanya bila ternyata perlu; `fill` seharusnya cukup) | Bagian 4.1-4.4, perbaikan addCart/reduceCart, `loadCartItems`, draft, `commitCustomerName`, `setItemNote`, `getViewData`. Jangan menyentuh `Selling::creating`, `CustomerNameService`, `POS.php`, `CartItem.php` (page). |
| `cashier-view` | `resources/views/filament/tenant/pages/cashier.blade.php`, `resources/views/filament/tenant/pages/cashier/**` (detail, items, total + partial baru: `product-browser`, `cart-panel`, `cart-item`, `payment-modal`, `scripts`, dst.), `resources/views/livewire/price-setting.blade.php`, `lang/id.json`, `lang/en.json` | Seluruh markup halaman kasir: layout responsif (mock), grid/list + localStorage, chip kategori, cari, load-more/infinite scroll, kartu/baris tanpa overflow, keranjang terkelompok kategori, catatan per item, nama pelanggan (flush saat bayar, `commitCustomerName`, hint), `wire:key` stabil, hapus mutasi `$sub_total` di `items.blade.php`, Textarea untuk catatan global (`{{ }}`), pemanggilan `LakasirReceipt.print`, hapus seluruh JS cetak inline, `@include('filament.tenant.partials.receipt-print')`. Menambah kunci terjemahan pada 4.3. |
| `receipt-print` | `resources/views/filament/tenant/partials/receipt-print.blade.php` (baru), `resources/views/filament/tenant/resources/sellings/pages/view-selling.blade.php`, `app/Filament/Tenant/Resources/SellingResource/Pages/ViewSelling.php`, `app/Filament/Tenant/Resources/SellingDetailResource/RelationManagers/SellingDetailsRelationManager.php` | Bagian 4.5 dan 4.6. Tidak menyentuh `resources/js/*`, `public/js/*`, `print-receipt.blade.php` (demo USB), `routes/web.php`. |
| `design` | `scratchpad/kasir-mock-v2.html` (hanya di scratchpad, bukan repo) | Mock hi-fi v2 (HP 375, tablet 768, desktop 1280; terang dan gelap): grid dan list, chip kategori, stepper tanpa overflow, keranjang terkelompok + catatan item, bottom sheet, contoh struk cetak berkelompok. Dipakai paket view sebagai rujukan visual dan ditinjau Randy. |
| `tests` | `tests/**` file BARU: `tests/Unit/OrderLineSorterTest.php`, `tests/Feature/CashierProductBrowserTest.php`, `tests/Feature/CashierItemNoteTest.php`, `tests/Feature/CashierCartSortTest.php`, `tests/Feature/CashierCustomerNameDraftTest.php`, `tests/Feature/ReceiptPartialTest.php`, `tests/Feature/ViewSellingNoteTest.php`, `tests/Feature/Http/Controllers/Api/Tenants/Transaction/SellingNoteTest.php`; serta DUA edit pada file lama: `tests/Feature/CustomerNameNumberingTest.php` (test terakhir) dan `tests/Feature/CashierPerfTest.php` (`tableSearch`/`setPage` -> `productSearch`/`loadMoreProducts`) | Daftar test di bagian 7. Menulis saja sampai backend selesai; eksekusi oleh paket verifikasi. |
| `verify` | tidak punya file repo; keluaran hanya ke scratchpad | Bagian 8 (migrasi dev, build, jalankan pest, cek browser, perf). |

Urutan: gelombang 1 paralel (`design`, `backend`, `receipt-print`, `cashier-view`, `tests` ditulis berdasarkan kontrak); gelombang 2: `verify`; lalu `devils-advocate`, `code-reviewer`, `test-runner`, `uiux-analyst` sesuai alur kerja tim.

---

## 6. Kriteria penerimaan per kebutuhan

| # | Penerimaan |
|---|---|
| 1 | Pada lebar 320, 375, 768, 1280: tidak ada scroll horizontal halaman; stepper dengan qty `9999` dan harga `Rp 1.250.000` tetap di dalam kartu; gambar tidak keluar kartu (cek `scrollWidth <= clientWidth` pada kartu dan `img`). |
| 2 | Toggle grid/list berfungsi, pilihan bertahan setelah reload (localStorage), baris list tinggi >= 48 px dan thumbnail <= 48 px. |
| 3 | Klik chip memfilter, "Semua" me-reset; kombinasi chip + teks = AND; kategori tanpa produk tampil pesan kosong; `searchEnter` menambah item untuk barcode persis. |
| 4 | Nama yang diketik tidak berubah sampai transaksi dibuat; reload mempertahankan nama/meja/catatan/member; klik "Pay it" tanpa blur tetap menyimpan nama; transaksi kedua "Budi" hari yang sama tersimpan "Budi (2)" dan struk memuat nama final. |
| 5 | Catatan item tersimpan (debounce), tidak hilang saat +/- qty, tampil di keranjang, modal pembayaran, struk cetak, halaman detail penjualan, API; item kasir lain tidak bisa diubah; karakter kontrol dibuang. |
| 6 | Keranjang dan struk terurut kategori A-Z lalu nama; judul kelompok hanya jika >= 2 kategori; tanpa kategori di akhir. |
| 7 | `grep -rn "\.cut()" resources/views` hanya menemukan partial; kedua halaman mencetak lewat `LakasirReceipt.print`; tombol cetak kasir dan detail penjualan tetap bekerja (printer palsu menangkap perintah). |
| 8 | Jumlah query render awal dan `addCart` konstan terhadap jumlah produk (24 vs 5000); cari "kopi" pada 5000 produk <= ~0.2 s (`PERF=1 pest tests/Feature/CashierPerfTest.php`); tidak ada N+1 baru. |

---

## 7. Daftar test (paket tests)

1. `OrderLineSorterTest` (unit, objek tiruan): urutan kategori A-Z natural case-insensitive, null terakhir, nama produk, id sebagai penentu, `group()` bentuk keluaran, relasi tidak dimuat = tanpa kategori dan tanpa query.
2. `CashierProductBrowserTest`: pencarian nama/SKU/barcode aktif (barcode nonaktif tidak cocok), filter kategori, kombinasi, `loadMoreProducts` + `hasMoreProducts`, batas 240, produk habis stok tidak tampil, `selectCategory` id asing -> null, `searchEnter` menambah item, jumlah query konstan (24 vs 100 produk) pada render dan `addCart`.
3. `CashierItemNoteTest`: `setItemNote` tersimpan, trim, kosong -> null, 300 karakter dipotong 255, karakter kontrol dibuang, item kasir lain tidak berubah, catatan bertahan setelah `addCart`/`reduceCart`, mengalir ke `selling_details.note` lewat `proceedThePayment`, hilang bersama `clearCart`.
4. `CashierCartSortTest`: `cartItems` terurut kategori lalu nama setelah `addCart` berurutan acak; `selling_details` terbentuk terurut; `loadForReceipt()` mengurutkan data lama yang tidak terurut.
5. `CashierCustomerNameDraftTest`: `set cartDetail.customer_name` tetap apa adanya (tanpa nomor); `commitCustomerName` + `storeCart` dua kali tidak mengubah nilai; `customerNameHint` terisi bila ada duplikat; draft dimuat pada `mount` baru; draft dihapus setelah bayar; `proceedThePayment` dengan nama "Andi" saat sudah ada "Andi" menghasilkan `sellings.customer_name = "Andi (2)"`; nama kosong -> null.
6. `ReceiptPartialTest`: partial dirender dan berisi `window.LakasirReceipt`, `version`, `groupDetails`; test statis: string `.cut()` hanya ada di partial, `cashier.blade.php` dan `view-selling.blade.php` tidak berisi `new (window.Printer` / `handleCashierPrintReceipt` / `function formatReceiptMoney`.
7. `ViewSellingNoteTest`: Livewire `ViewSelling` menampilkan catatan item (di-escape: `<script>` tidak aktif), judul kategori hanya bila >= 2 kategori, catatan pesanan HTML lama tampil sebagai teks.
8. `SellingNoteTest` (API): `products.*.note` tersimpan dan dikembalikan, > 255 ditolak (422), kosong -> null, request lama tanpa `note` tetap lolos.
9. Ubah `CustomerNameNumberingTest` (test Livewire terakhir) dan `CashierPerfTest` sesuai bagian 4.3; test numbering API/service lainnya tidak berubah.

Semua test baru memakai `uses(RefreshDatabaseWithTenant::class)` dan `Filament::setCurrentPanel(Filament::getPanel('tenant'))` seperti test yang ada. Jangan menjalankan pest selama proses lain memakai `database/testing.sqlite`.

---

## 8. Verifikasi (paket verify)

1. `$env:Path="$env:USERPROFILE\.config\herd\bin;$env:Path"`; `php -v` harus 8.4.
2. Setelah suite lain selesai: `php artisan migrate:status`; terapkan hanya dua migrasi baru dengan `--path`. `php artisan view:clear`.
3. `npm run build` (kelas Tailwind baru), tidak perlu `filament:assets` karena `resources/js/*` tidak berubah.
4. `php vendor/bin/pest` penuh; lalu `PERF=1 PERF_PRODUCTS=5000 php vendor/bin/pest tests/Feature/CashierPerfTest.php` dan bandingkan dengan baseline (search/paginate ~0.2 s, `addCart` tidak melambat).
5. Browser (panel Browser/Chrome; kredensial dari seeder, jangan ditampilkan): viewport 320, 375, 768, 1280, terang dan gelap; cek overflow lewat `javascript_tool`, toggle grid/list + reload, chip, catatan item, nama pelanggan lewat tombol Bayar tanpa blur, reload mempertahankan draft, keranjang terkelompok.
6. Cetak: pasang `Printer` palsu di konsol (rekam `addCommand`), panggil `LakasirReceipt.print` pada data contoh dua kategori dan satu kategori; pastikan judul kelompok, baris catatan, tidak ada byte kontrol dari catatan, dan dua halaman memakai fungsi yang sama.
7. Laporkan ke thread: daftar lulus/gagal, angka perf, tangkapan layar, dan temuan di bagian 1.3.

---

## 9. Asumsi dan keputusan default (bisa diubah, tidak memblokir)

1. Struk pelanggan dan struk dapur adalah satu cetakan yang sama dengan pengelompokan kategori (tidak ada tiket dapur terpisah). Jika perlu tiket dapur terpisah tanpa harga, itu pekerjaan lanjutan (mode `kitchen` pada `LakasirReceipt.build`).
2. Urutan kategori: A-Z (tidak ada kolom urutan). Jika perlu urutan manual (mis. Minuman dulu), butuh kolom `categories.sort_order` dan layar pengaturannya.
3. Catatan item satu baris (newline diratakan), maks 255 karakter. Tidak ada preset catatan cepat.
4. Draft kasir memakai cache file (TTL 12 jam), bukan tabel baru. Voucher dan diskon manual tidak disimpan dalam draft.
5. Catatan global berubah dari RichEditor ke Textarea teks polos.
6. Dialog "Insert amount" dihapus; jumlah diubah lewat stepper atau input qty di keranjang.
7. Daftar produk tanpa paginasi nomor halaman (load-more, maks 240).

## 10. Risiko untuk devils-advocate

R1 Kelas Tailwind baru tidak muncul tanpa `npm run build`. R2 Setiap request merender ulang seluruh komponen termasuk daftar produk (sama seperti sebelumnya; rencana B tersedia). R3 Urutan keranjang berubah dari "terbaru di atas"; mitigasi event `cart-item-touched`. R4 `#[Renderless] setItemNote` bersama panggilan lain dalam satu request akan ikut tidak merender; panggil dari event terpisah. R5 Draft cache bersifat per user dan hilang bila `Cache::clear()`; tes memakai `Cache::clear()` sehingga harus menyiapkan draft dalam tiap test. R6 Menghapus `HasTable` membuat `CashierPerfTest` lama gagal bila tidak diubah. R7 Karakter kontrol/ESC pada catatan atau nama bisa menyuntik perintah printer: ditangani di model dan `toPrintable`. R8 Nomor "(2)" tetap dihitung sekali di server; dua kasir menyimpan bersamaan masih bisa bertabrakan (balapan pre-existing, bukan bagian pekerjaan ini). R9 Migrasi pending lain di DB dev bisa men-drop tabel: terapkan per file. R10 API tetap kompatibel (aditif). R11 Gambar produk besar pada mode list (tanpa thumbnail) dapat memberatkan koneksi lambat. R12 Temuan 1.3 (total ter-reset per request) dapat tampak sebagai bug baru di mata kasir.

---

## 11. Revision after challenge (BERLAKU; menggantikan bagian 3-9 bila bertentangan)

Dua verdict (backend: 2 critical + 14 important; UX: 8 critical + 13 important) ditinjau terhadap kode. Fakta yang saya verifikasi ulang sebelum menerima: (1) `modalOpened/scannerEnabled/barcodeData/barcodeTimeout` tidak dideklarasikan (`let/var`) di `cashier.blade.php`, jadi `keypress` lama membaca `scannerEnabled` tak terdefinisi dan hampir pasti melempar ReferenceError (scanner fisik lama kemungkinan sudah rusak; dicek di konsol oleh verify); (2) `scopeSellableInPos` memasukkan `type='service'` dan `is_non_stock=true`, `scopeWithStockTotal` memberi 0 untuk keduanya; (3) `refreshCart()` memang me-null-kan voucher dan `calculateTotalPrice()` jalan di `getListeners()`; (4) `SellingService::create` membungkus `Selling::create` di `DB::beginTransaction`; `CACHE_DRIVER=file` (mendukung `Cache::lock`); (5) `config/app.php` timezone `UTC`; (6) kunci terjemahan `Subtotal`, `Customer Name`, `Enter customer name`, `Customer Name (Optional)`, `Uang Pas` TIDAK ada di `lang/id.json`; (7) tidak ada `jsdom` di `node_modules` (Node 22 ada).

### 11.1 Yang DITERIMA dan berubah

| # | Sumber | Perubahan |
|---|---|---|
| A1 | BE-C1, UX-C7 | **Arsitektur dipecah.** Daftar produk menjadi komponen Livewire anak `App\Livewire\Cashier\ProductBrowser` (state cari/kategori/limit sendiri). Aksi keranjang (`addCart`, `commitCustomerName`, dst.) tidak lagi merender atau meng-query produk; ketikan di kotak cari tidak menyentuh keranjang. Qty pada kartu dibaca dari Alpine store `cart` yang diisi event `cart-qty-updated`. Bug "grup kategori hilang pada request non-refresh" ditutup oleh `loadMissing` di `getViewData()` + `group()` yang mengurutkan sendiri. Rencana B lama ("bila turun") dihapus karena menjadi rencana A. |
| A2 | BE-C2 | XSS catatan lama: konversi HTML ke teks dilakukan **di server** (`PlainText::fromHtml`, atribut `note_plain` pada hasil `loadForReceipt()`); JS `htmlToPlain` hanya cadangan dan WAJIB memakai `DOMParser().parseFromString(html,'text/html')` (dokumen inert) atau, bila `DOMParser` tidak ada, penghapus tag berbasis regex. DILARANG `innerHTML`/`outerHTML`/`insertAdjacentHTML`/`document.write` di partial (test statis). |
| A3 | BE-I1, UX-C6 | `#[Renderless] setItemNote` pada komponen utama DIHAPUS. Catatan item dipindah ke komponen anak per item `App\Livewire\Cashier\CartItemNote` (`save(?string)`): `skipRender` berlaku per komponen, jadi tidak pernah membuang render keranjang walau satu bundle request. Input catatan juga aman dari morph induk (anak tidak di-morph). `commitCustomerName` TIDAK renderless (render ringan, hanya keranjang), dedupe di klien. |
| A4 | BE-I2, UX-C7 | Anggaran performa terukur di `CashierPerfTest`: tiga skenario 5000 produk (tanpa cari / cari "kopi" / limit maksimum), waktu respons `addCart` dan ukuran HTML respons induk. `PRODUCT_MAX` turun 240 -> **96**. Tambah indeks `(show, name)`. Aturan jatuh: bila ORDER BY name melewati ambang, buang `orderBy name` (pakai `id`) dan lapor, jangan dilonggarkan diam-diam. |
| A5 | BE-I3 | LIKE memakai `whereRaw("... LIKE ? ESCAPE '!'")` dengan escape `!`,`%`,`_` (portabel SQLite+MySQL); `Product::escapeLike()`. Test dengan produk "100% Juice" dan "a_b". |
| A6 | BE-I4, UX-C4 | `searchEnter(?string $code = null)` dikirim `$el.value`. Scanner fisik: listener `keypress` lama DIHAPUS, diganti satu detektor global berbasis kecepatan (karakter < 50 ms, panjang >= 4, diakhiri Enter, tetap bekerja walau fokus di input; membersihkan input; mengirim `Livewire.dispatch('product-scanned',{code})`). Satu jalur: detektor menangani scan dengan `stopImmediatePropagation`, `searchEnter` hanya Enter manual. |
| A7 | BE-I5 | Barcode tetap infix (`%term%`, aktif saja) seperti perilaku lama; nama dan SKU juga infix. Test regresi 4-6 digit terakhir. |
| A8 | BE-I6, UX-I6 | Draft dipulihkan hanya bila keranjang tidak kosong (selain itu di-`forget`); `Table::find`/`Member::find` divalidasi, id basi dibuang; `fillMember()` dan `fillTableLabel()` dipanggil sesudah restore; draft di-`forget` saat keranjang menjadi kosong via `onCartRefreshed()`; penanda "Draft dipulihkan" + tombol "Buang draft" (`discardDraft()`). Hint dihitung ulang saat modal bayar dibuka (`syncCartForPayment()`) dan diberi label "Perkiraan". Draft tetap per akun (sama dengan keranjang) -> pertanyaan akun bersama. |
| A9 | BE-I7, BE-I8 | `OrderLineSorter`: kunci `[kategori null?1:0, strnatcasecmp(nama kategori), category_id, strnatcasecmp(nama produk), id]`, null-safe pada produk soft-deleted; `group()` mengurutkan sendiri dan mengelompokkan per `category_id` (bukan per nama); JS `groupDetails` memakai kunci yang sama (`category.id`). `Selling::loadForReceipt()` memuat produk `withTrashed`, idempoten (tidak query/sort ulang), `ViewSelling::getRecord()` dimemoize per request. `view-selling` null-safe (`$detail->product?->name`). |
| A10 | BE-I9, UX-I12 | `toPrintable`: peta transliterasi (kutip/apostrof pintar, en/em dash, bullet, x, ellipsis, NBSP), NFKD, buang non-ASCII, fallback nama (SKU, lalu `#id`) bila kosong (`itemName()`); `wrapText(text,width,indent)` memecah kata lebih panjang dari `lineWidth` dan membungkus judul kelompok `[ NAMA ]`; baris kosong antar kelompok. |
| A11 | BE-I10 | `SellingService::create` dibungkus `Cache::lock('selling-numbering', 15)->block(5, ...)` mencakup seluruh transaksi (nomor harian dan "Budi (2)" dihitung di dalamnya). `Selling::creating`/`CustomerNameService` tetap tidak diubah. Timezone UTC dicatat sebagai temuan + pertanyaan, TIDAK diubah di PR ini. |
| A12 | BE-I11, UX-I7 | Satu DOM per produk. Mode tampilan diatur atribut `data-product-view` pada `<html>` (skrip di `HEAD_END`, sebelum daftar, try/catch), CSS `pv-*` di partial head; tombol toggle hanya mengubah atribut + localStorage; tidak ada `:class` Alpine pada HTML yang dirender ulang. |
| A13 | BE-I12 | `proceedThePayment` memuat ulang `$this->cartItems = $this->loadCartItems()` di awal (sumber tunggal catatan terbaru); `submitPayment()` menunggu `LakasirPending.settle()` dan menolak bila ada simpan gagal. |
| A14 | BE-I13 | Partial cetak TIDAK lagi di-`@include` dalam view Livewire; dipasang lewat `FilamentView::registerRenderHook(PanelsRenderHook::BODY_END, ..., scopes: static::class)` di `mount()` Cashier dan ViewSelling (hanya dirender pada page load penuh, nol query per request Livewire). |
| A15 | BE-I14 | Migrasi tanpa `->after()`; catatan urutan rilis ("migrate sebelum/serentak deploy"; tab kasir lama harus reload). |
| A16 | BE-I15 | Test baru untuk setiap celah yang disebut (11.6). |
| A17 | BE-minor | Nama pelanggan dibatasi 240 (`maxlength` + validator + `commitCustomerName`); `getState()` bukan penyebab nama menempel (koreksi §1.2 poin 5, tetap dibersihkan); `{{ $item }}` JSON di `wire:keyup` diganti `$item->id`; `loadForReceipt` memakai kategori saat ini (batasan dicatat); tombol "Muat lebih banyak" selalu tampil bila `hasMore`; baris kosong antar kelompok di struk. |
| A18 | UX-C1 | Kontrak membawa `unlimited` = `is_non_stock || type==='service'` per produk (`Product::isStockUnlimited()`, data attribute `data-unlimited`). Badge stok disembunyikan, tombol tidak pernah "Habis", batas plus oleh stok tidak berlaku; validasi stok tetap di server (`validateStock`). Test dengan produk layanan dan non-stok. |
| A19 | UX-C2 | Regresi jumlah banyak ditutup: angka qty (kartu dan keranjang) adalah `<input inputmode="decimal">` yang memanggil `addCart(product, ['amount'=>n])` (koma -> titik; 0/kosong = hapus; negatif ditolak). Dialog `insert_amount` lama memang dihapus; fungsinya digantikan input qty. |
| A20 | UX-C3 | Member (@feature Member), diskon per item (@feature Discount) + `<livewire:price-setting>`, Meja (hanya `business_type=='fnb'`) dan voucher (tombol `availableVoucher` terfilter, bukan kolom teks) dikembalikan. Penempatan: baris keranjang punya menu "Opsi" (`cart-item-more-toggle-{id}`) berisi diskon item + satuan harga; panel pesanan punya "tile" ringkasan (Meja, Member, Diskon, Voucher, Catatan pesanan) yang membuka modal `modal-selected-table` / `edit-detail` yang SUDAH ada. Member wajib tampil di modal bayar untuk metode kredit. Mock v3 diperbarui. |
| A21 | UX-C5 | Tidak ada mutasi properti di server untuk input (tidak ada `updatedProductSearch` yang men-trim). Kotak cari = input Alpine (debounce 300 ms) -> `$wire.set('search', v)`; trim hanya di dalam query. Nama pelanggan = input Alpine di dalam `wire:ignore`, tanpa `wire:model`. |
| A22 | UX-C8 | Bottom sheet: dilarang `transform`/`translate`/`filter`/`will-change`/`contain`/`backdrop-filter` pada sheet dan leluhur modal price-setting pada keadaan terbuka (containing block untuk `position:fixed`); animasi memakai `bottom`/`opacity`; z-index sheet 30 (< modal Filament 40); kriteria verify: modal satuan harga dan modal Bayar dari dalam sheet pada 375 px memiliki rect fixed = viewport. |
| A23 | UX-I1, I9 | Token `lakasir.primary-strong: #c2410c` ditambah di `tailwind.config.js` (dimiliki `cashier-shell`) untuk tombol/chip berteks putih (kontras 5,18:1); `lakasir.primary` (#FF6600) hanya untuk dekorasi non-teks. Dimensi mengikuti DESAIN: panel `md:w-80 lg:w-[360px]`, baris list 60 px (thumbnail 48 px; 44 px < 360), grid `grid-cols-2 min-[560px]:grid-cols-3 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5`, list `grid-cols-1 lg:grid-cols-2`; kunci localStorage mengikuti rencana `lakasir.cashier.productView`. Tanpa `@container` (tidak ada plugin). |
| A24 | UX-I2 | Stepper keranjang: `grid-cols-[44px_minmax(2rem,auto)_44px] min-w-[116px] justify-self-end`; kriteria uji menambah qty `9999`, `12,5`, `10000` di keranjang dan kartu. |
| A25 | UX-I3 | Satu sumber nama pelanggan: input di panel pesanan. `TextInput customer_name` dihapus dari `storeCartForm`; modal bayar hanya menampilkan nama (teks) dan `submitPayment` membaca store tunggal itu. |
| A26 | UX-I4 | Indikator simpan mengikuti hasil promise: `Belum tersimpan / Menyimpan... / Tersimpan / Gagal, ketuk untuk ulang` (+ offline). `window.LakasirPending.settle()` menunggu semua simpan tertunda dan memaksa flush; gagal -> bayar ditolak dengan pesan jelas. Mock menggambar keadaan gagal dan offline. |
| A27 | UX-I5 | Keyboard HP: `visualViewport.resize` -> variabel `--vvh`; tinggi sheet `min(90dvh, var(--vvh)*.9)`; `focusin` pada input catatan/nama -> `scrollIntoView({block:'center'})`; footer Bayar disembunyikan saat keyboard terbuka; pakai `dvh` bukan `vh`. Verifikasi manual oleh Randy di perangkat nyata (emulator tidak cukup). |
| A28 | UX-I10 | Hapus item dapat diurungkan: server mengirim event browser `cart-item-removed {productId,qty,note,discountPrice,priceUnitId}` (diambil SEBELUM dihapus, dari `deleteCart`, `reduceCart` pada qty 1 dan `addCart` amount 0); snackbar "Urungkan" 6 detik memanggil `restoreCartItem(...)`. Minus pada qty 1 di keranjang tetap ikon sampah (44 px). |
| A29 | UX-I11 | Total/voucher yang ter-reset (`getListeners()` -> `calculateTotalPrice()`, `$discount_price` tidak persisten) tetap DI LUAR scope, dilaporkan sebagai batas keras ke Randy (pertanyaan). `syncCartForPayment()` tidak mereset voucher. |
| A30 | UX-I13 | Gambar: `width`/`height` eksplisit, `loading=lazy decoding=async`, `onerror` -> placeholder SVG lokal; view memakai `$product->hero_images_url[0] ?? null`, TIDAK `hero_image` (fallback iconfinder). Sentinel `x-intersect` hanya aktif setelah ada `scroll` oleh pengguna dan bila tidak `wire:loading`; tombol "Muat lebih banyak" cadangan. |
| A31 | UX-I8 | Tabel terjemahan LENGKAP (11.4) dengan satu pemilik (`cashier-shell`); kunci yang dipakai `receipt-print` ikut di dalamnya. |
| A32 | UX-I9 | Paket dipecah dan diurutkan dalam 3 gelombang (11.5); `tailwind.config.js` punya pemilik; kontrak 11.3 menjadi stub bersama. |
| A33 | UX-minor | Maks catatan item di UI 100 (server 255 untuk API); preset catatan cepat TIDAK diimplementasi (pertanyaan, default tidak); pengecualian target sentuh (chip 40 px, toggle 44x40) diterima; label input dikaitkan `for`; `inert` hanya peningkatan; listener `open-modal` lama tidak disalin; kunci idempotensi pembayaran dicatat sebagai tindak lanjut. |

### 11.2 DITOLAK atau diterima sebagian (dengan alasan)

| Poin | Keputusan | Alasan |
|---|---|---|
| UX-C3 "input diskon/voucher/catatan inline di panel" | Ditolak sebagian: tile ringkasan -> modal yang sudah ada | Diskon, voucher, member, meja divalidasi dan dipakai `storeCart`/`calculateTotalPrice` di server; menjadikannya input inline berarti menulis ulang alur total yang sudah rapuh (A29). Modal ada dan teruji. Keluhan Randy: overflow dan kecepatan, bukan form diskon. |
| UX-C7 "peta qty hanya lewat event" | Diterima + seed dari server | Menghindari balapan: kartu pertama dirender sebelum event pertama; seed lewat variabel yang dirender hook BODY_END. |
| BE-I1 opsi (c) antrean promise global | Ditolak | Menserialkan semua ketukan menambah latensi; komponen anak per item menyelesaikan akar masalahnya (flag per komponen) tanpa mengubah perilaku +/-. |
| BE-I2 "commitCustomerName #[Renderless]" | Ditolak | Renderless membawa risiko yang sama dengan setItemNote bila satu bundle dengan `addCart`. Setelah daftar produk dipisah, render induk murah; dedupe di klien (change+Enter+blur -> 1 request). |
| UX-I6 "draft per perangkat" | Ditolak, jadi pertanyaan | Keranjang (`CartItem::cashier()`) sudah per akun; draft per perangkat membuat nama/meja tidak cocok dengan keranjang. Perlu keputusan Randy. |
| UX "hitung jumlah chip mengikuti teks cari" (mock) | Ditolak | Butuh `GROUP BY category` dengan subquery `exists` pada 5000 produk di setiap ketikan. Chip memakai hitungan statis (sekali di `mount` dan `products-refresh`); baris status "N menu" dari hasil termuat ("24+" bila `hasMore`). Chip tanpa produk laku-jual tidak ditampilkan (menutup minor "chip kosong"). |
| UX "Undo khusus kartu produk" | Ditolak sebagian | Server mengirim `cart-item-removed` untuk semua jalur, jadi Undo ada di mana pun item terhapus (A28). |
| BE-minor `$members` properti publik | Dicatat, di luar scope | Pre-existing; menjadi pencarian server-side = pekerjaan terpisah. |
| UX-minor `inert` Android lama | Dicatat | Dampak hanya aksesibilitas keyboard. |
| Timezone UTC (BE-I10) | Tidak diubah di PR ini | Mengubah `config/app.php` menggeser tampilan waktu dan batas "harian" data lama; keputusan bisnis. Dilaporkan + pertanyaan, rekomendasi `APP_TIMEZONE=Asia/Jakarta` sebagai tugas terpisah. |
| Hapus dari kartu produk pada qty 1 kehilangan catatan | Dimitigasi, bukan dicegah | Lewat Undo (A28). |

### 11.3 KONTRAK FINAL

#### DB
- `cart_items.note` string(255) NULL; `selling_details.note` string(255) NULL. Tanpa `->after()`. Guard `hasColumn`.
- `products` index `products_category_listing_index (category_id, show)` dan `products_show_name_index (show, name)`. Guard `Schema::hasIndex`. `down()` membalik semua.
- Berkas: `2026_10_15_000001_add_note_to_cart_items_and_selling_details_tables.php`, `2026_10_15_000002_add_listing_indexes_to_products_table.php`.
- Urutan rilis: migrasi dijalankan sebelum/serentak kode (kode mengirim key `note` ke `AssignProduct::fill`).

#### Model / service (paket `data-layer`)
```php
// App\Models\Tenants\Concerns\HasOrderLine (dipakai CartItem, SellingDetail)
public function note(): Attribute      // set only: null->null; /[\x00-\x1F\x7F]+/ -> ' '; /\s+/ -> ' '; trim; mb_substr(0,255); '' -> null
public function categoryName(): ?string // $this->product?->category?->name (tidak di $appends)
// CartItem::$fillable += 'note'

// App\Models\Tenants\Selling
protected bool $receiptLoaded = false;
public function loadForReceipt(): static
//  idempoten; load(['sellingDetails.product' => fn($q)=>$q->withTrashed(), 'sellingDetails.product.category','paymentMethod','user','member','table'])
//  setRelation('sellingDetails', OrderLineSorter::sort($this->sellingDetails)); $this->append('note_plain'); return $this
public function notePlain(): Attribute  // get: PlainText::fromHtml($this->note); TIDAK di $appends global

// App\Services\Tenants\PlainText (final, static)
public static function fromHtml(?string $html, int $max = 1000): ?string
//  <br>,</p>,</div>,</li>,</h1-6> -> "\n"; strip_tags; html_entity_decode(ENT_QUOTES|ENT_HTML5); buang kontrol kecuali \n; rapikan baris kosong; trim; null bila kosong

// App\Models\Tenants\Product
public static function escapeLike(string $s): string                   // '!'->'!!', '%'->'!%', '_'->'!_'
public function scopeSearchInPos(Builder $b, string $term): Builder
//  term=trim; '' -> no-op. where(group): whereRaw("products.name LIKE ? ESCAPE '!'") OR whereRaw("products.sku LIKE ? ESCAPE '!'") OR exists(barcodes aktif AND whereRaw("barcodes.code LIKE ? ESCAPE '!'")). SEMUA infix "%esc%".
public function scopeInCategory(Builder $b, ?int $categoryId): Builder  // null no-op
public function isStockUnlimited(): bool                               // is_non_stock || type === 'service'

// App\Services\Tenants\OrderLineSorter (final, static; TANPA query: relationLoaded)
public static function sort(Collection $lines): Collection   // kelas koleksi sama, values()
public static function group(Collection $lines): Collection // mengurutkan sendiri; list<array{key:string,category:?string,category_id:?int,lines:Collection}>; key "c{category_id}"|"none"; null terakhir; per category_id
public static function compare($a, $b): int                  // [kategori null?1:0, strnatcasecmp(nama kategori), category_id, strnatcasecmp(nama produk), id]; produk/kategori null aman (nama '')

// App\Services\Tenants\CashierDraftService (app())
public function load(int|string $userId): array              // hanya customer_name,note,member_id,table_id
public function save(int|string $userId, array $fields): void // whitelist; merge; Cache::put("cashier-draft:{id}", ..., now()->addHours(12)); null/'' hapus kunci
public function forget(int|string $userId): void

// App\Services\Tenants\SellingService::create(array $data)    // SELURUH isi (transaksi) di dalam Cache::lock('selling-numbering', 15)->block(5, fn)
```
`Selling::creating`, `CustomerNameService`, `POS.php`, `Pages/CartItem.php`: TIDAK diubah.
API aditif: `TransactionSellingStoreRequest` + `'products.*.note' => ['nullable','string','max:255']`; `SellingDetailCollection` + `'note' => $this->note`; `SellingController` eager load `sellingDetails.product.category`. `AssignProduct` tidak berubah.

#### Komponen induk `App\Filament\Tenant\Pages\Cashier` (paket `cashier-livewire`)
Tidak `implements HasTable`; `Traits/TableProduct.php` dihapus. Properti publik baru: `?string $customerNameHint = null; bool $draftRestored = false;`. Dihapus: hook `updatedCartDetailCustomerName`, `generateDailyCustomerName` selain di `commitCustomerName`/`syncCartForPayment` (hanya untuk hint), `TextInput customer_name` di `storeCartForm`; `RichEditor note` -> `Textarea::make('note')->rows(2)->maxLength(500)`.
```php
public function mount(): void                                    // tetap publik; restoreDraft(); registerRenderHooks()
protected function registerRenderHooks(): void
//  FilamentView::registerRenderHook(PanelsRenderHook::HEAD_END, fn()=>view('livewire.cashier.partials.head'), scopes: static::class);
//  FilamentView::registerRenderHook(PanelsRenderHook::BODY_END, fn()=>view('filament.tenant.pages.cashier.bootstrap', ['cartQty'=>$this->cartQtyMap()]), scopes: static::class);
//  FilamentView::registerRenderHook(PanelsRenderHook::BODY_END, fn()=>view('filament.tenant.partials.receipt-print'), scopes: static::class);
protected function restoreDraft(): void                          // hanya bila cartItems tidak kosong; else forget; validasi Table/Member; fillMember(); fillTableLabel(); draftRestored=true
public function discardDraft(): void
public function commitCustomerName(?string $name = null): void  // trim, mb_substr 240, ''->null; cartDetail.customer_name; draft save; customerNameHint = generateDailyCustomerName($name) bila != $name else null. BUKAN renderless
public function syncCartForPayment(): void                       // cartItems = loadCartItems(); hitung ulang customerNameHint; TIDAK reset voucher/total
public function storeCart(): void                                // tanpa penomoran, tanpa catch kosong (notifikasi bila gagal), simpan draft
public function selectTable(?int $tableId = null): void         // + simpan draft
public function saveCustomTable(?string $customNumber = null): void   // + simpan draft
public function clearCart()                                      // + forget draft + dispatch('order-reset')
#[On('product-scanned')] public function onProductScanned(string $code): void   // addCartUsingScanner(trim($code))
public function restoreCartItem(int $productId, float $qty, ?string $note = null, ?float $discountPrice = null, ?int $priceUnitId = null): void
public function proceedThePayment(SellingService $sellingService): void   // signature TETAP
```
`proceedThePayment`: awal `$this->cartItems = $this->loadCartItems()`; kosong -> notifikasi + return; `products[]` dari cartItems terurut dengan kunci `product_id,qty,price,discount_price,price_unit_id,note`; validator `customer_name` => `['nullable','string','max:240']`; sukses: `CartItem::cashier()->delete()`, forget draft, `resetOrder()` privat (reload keranjang, form default, hint null, draftRestored false), dispatch `close-modal` (proceed-the-payment), `open-modal` (success-modal), `order-reset`, `selling-created` (selling: `$selling->loadForReceipt()`), Livewire event `products-refresh`.

`getViewData(): array` (protected):
```php
$this->cartItems->loadMissing(['product.category','priceUnit']);   // WAJIB, 2-3 query konstan
return ['cartGroups'=>OrderLineSorter::group($this->cartItems), 'cartCount'=>int, 'cartQtyTotal'=>float, 'cartQty'=>cartQtyMap()];
// 'products','hasMoreProducts','lowStockThreshold' PINDAH ke ProductBrowser
```
`CartInteraction` (aditif; POS dan Pages/CartItem yang meng-override `refreshCart` tidak berubah):
```php
protected function loadCartItems(): \Illuminate\Database\Eloquent\Collection  // CartItem::query()->with(['product.category','priceUnit'])->cashier()->get() lalu OrderLineSorter::sort
protected function cartQtyMap(): array                                          // product_id=>qty, satu query per request; di-null-kan di refreshCart
protected function onCartRefreshed(): void {}                                  // hook kosong di akhir refreshCart(); Cashier meng-override: dispatch('cart-qty-updated', qty: cartQtyMap()); keranjang kosong -> forget draft
public function addCart(Product $product, ?array $data = null)                 // signature tetap; amount: cast float, kosong/0 hapus ->cashier() null-safe, negatif ditolak; dispatch 'cart-item-touched'(productId:int)
public function reduceCart(Product $product)                                   // null-safe; qty 1 -> hapus; 'cart-item-touched' bila item ada
public function deleteCart(CartItem $cartItem)                                 // null-safe
// deleteCart, reduceCart qty 1, addCart amount 0: dispatch 'cart-item-removed' (productId, qty, note, discountPrice, priceUnitId) SEBELUM delete
```

#### Komponen anak `App\Livewire\Cashier\ProductBrowser` (paket `cashier-livewire`; tag `<livewire:cashier.product-browser />`; view `livewire.cashier.product-browser`)
```php
public const PAGE_SIZE = 24; public const MAX = 96;
public string $search = '';      // diset dari Alpine ($wire.set); TIDAK di-trim/dimutasi di server
public ?int $categoryId = null;
public int $limit = 24;
public array $chips = [];        // list<array{id:int,name:string,count:int}>, A-Z natural, hanya kategori punya produk laku-jual; count statis
public function mount(): void
public function selectCategory(?int $categoryId = null): void   // id tak ada di chips -> null; limit=24
public function loadMore(): void                                  // limit = min(limit+24, 96)
public function updatedSearch(): void                             // HANYA limit = 24
public function searchEnter(?string $code = null): void          // code ?? search, trim; Product::findByBarcodeOrSku -> dispatch('product-scanned', code: $code)->to(Cashier::class); search=''; dispatch browser 'search-cleared'; tidak ketemu -> tetap pencarian teks, tanpa notifikasi
#[On('products-refresh')] public function refreshProducts(): void // limit=24, isi ulang chips
public function render()   // view('livewire.cashier.product-browser', ['products','hasMore','lowStockThreshold','currency'])
```
Query: `Product::query()->select('products.*')->withStockTotal()->sellableInPos()->inCategory($categoryId)->when(trim($search)!=='', fn($q)=>$q->searchInPos(trim($search)))->orderBy('products.name')->orderBy('products.id')->limit($limit+1)->get()`; `hasMore` bila hasil > limit. Aksi kartu: `wire:click="$parent.addCart({{ $id }})"`, `$parent.reduceCart(id)`, input qty -> `$wire.$parent.addCart(id, {amount: n})`.

#### Komponen anak `App\Livewire\Cashier\CartItemNote` (paket `cashier-livewire`; tag `<livewire:cashier.cart-item-note :cart-item-id="$item->id" :note="$item->note" :key="'note-'.$item->id" />`; view `livewire.cashier.cart-item-note`)
```php
public int $cartItemId; public string $note = '';
public function mount(int $cartItemId, ?string $note = null): void
public function save(?string $note = null): void   // CartItem::cashier()->find($cartItemId); tidak ada -> return; set note; save; $this->note=(string)$item->note; dispatch browser 'cart-item-note-saved' (id, note)
```
Alpine: `await $wire.save(value)`.

#### Event
Browser (dari server): `cart-qty-updated {qty}`, `cart-item-touched {productId}`, `cart-item-removed {productId,qty,note,discountPrice,priceUnitId}`, `order-reset`, `search-cleared`, `cart-item-note-saved {id,note}`, `selling-created {selling}` (selling_details terurut; tiap elemen `note`, `product.id`, `product.sku`, `product.name`, `product.category.{id,name}|null`; `selling.note_plain`), Filament `open-modal`/`close-modal`. Livewire antar komponen: `product-scanned {code}` -> Cashier, `products-refresh` -> ProductBrowser.

#### Skrip bersama (paket `cashier-shell`: `resources/views/filament/tenant/pages/cashier/bootstrap.blade.php`, dirender BODY_END sekali per page load)
```js
Alpine.store('cart', { qty: @js($cartQty), of(id): number, set(map) })     // update dari window event 'cart-qty-updated' (Array kosong -> {})
window.LakasirPending = { track(p), register(fn): () => void, settle(): Promise<{ok:boolean, failed:string[]}> }
window.LakasirCashier = { getView(): 'grid'|'list', setView(v): void }      // atribut <html data-product-view>, localStorage 'lakasir.cashier.productView' (try/catch)
// detektor scanner: guard window._lakasirScannerBound; keydown capture; abaikan saat modal Filament terbuka; Livewire.dispatch('product-scanned', {code})
```
`submitPayment()`: `const r = await window.LakasirPending.settle(); if (!r.ok) { tampilkan pesan; return; } await $wire.set('cartDetail.customer_name', nameValue, false); $wire.proceedThePayment();`

#### Partial cetak `resources/views/filament/tenant/partials/receipt-print.blade.php` (paket `receipt-print`)
Dirender lewat render hook (bukan `@include`). Idempoten `window.LakasirReceipt?.version`.
```js
window.LakasirReceiptConfig = { about, showCurrency, logo, paperWidth:'58'|'80', tax, discount, settingsUrl:'/member/printer', labels:{cashier,table,paymentMethod,customer,general,tax,taxPrice,subtotal,discount,totalPrice,payedMoney,change,note,orderNote,uncategorized,copy,printerMissing,noTransaction,failed} }
window.LakasirReceipt = {
  version: 1,
  print(selling, opts?: {buttonId?:string, reprint?:boolean}): Promise<boolean>,
  build(printer, selling, opts?): Printer,                                   // murni
  groupDetails(details): Array<{key:string, category:string|null, lines:object[]}>,  // TIDAK mengurutkan; key 'c{category.id}'|'none'
  htmlToPlain(html): string,                                                 // DOMParser inert / regex strip; DILARANG innerHTML
  toPrintable(text): string,
  wrapText(text, width, indent = '    '): string[],
  itemName(detail): string                                                   // toPrintable(name) || sku || '#'+product.id
}
```
Catatan pesanan: `selling.note_plain ?? htmlToPlain(selling.note)`. Judul `[ NAMA ]` (dibungkus) hanya bila >= 2 kelompok; baris kosong antar kelompok; catatan item `'  > ' + note` dibungkus `printer.lineWidth`. Pemanggil: kasir `window.LakasirReceipt.print(window._lakasirLastSelling,{buttonId:'printReceiptButton'})`; view-selling `window.handlePrintReceipt=()=>window.LakasirReceipt.print(window._lakasirViewSelling,{buttonId:'printButton',reprint:true})`. ID `printButton`/`printInvoice`, `handlePrintInvoice` tetap. Tidak ada `function formatReceiptMoney` global.

Detail penjualan: `ViewSelling::mount/getRecord` -> `loadForReceipt()` dimemoize per request; `registerRenderHook(BODY_END, view(receipt-print), scopes: static::class)` di `mount()`. `view-selling.blade.php`: `OrderLineSorter::group($record->sellingDetails)`, judul kelompok bila >= 2, `{{ $detail->note }}`, `$detail->product?->name`, Order note = `$record->note_plain`; `SellingDetailsRelationManager`: kolom `product.category.name`, `note`, `modifyQueryUsing(with('product.category'))`.

#### View (paket `product-view` dan `cashier-shell`)
- Selector uji: `product-card-{id}` (single DOM, juga baris list; `product-row-{id}` DIHAPUS), `category-chip-all`, `category-chip-{id}`, `view-toggle-grid`, `view-toggle-list`, `product-search`, `product-load-more`, `product-qty-{id}`, `cart-group-{key}`, `cart-item-{id}`, `cart-item-qty-{id}`, `cart-item-more-toggle-{id}`, `cart-item-note-toggle-{id}`, `cart-item-note-{id}`, `cart-item-note-status-{id}`, `customer-name-input`, `customer-name-status`, `customer-name-hint`, `cart-dock`, `cart-sheet`, `undo-snackbar`, `draft-restored`.
- Kartu: `data-pv-card data-stock data-unlimited="0|1"`; kelas `pv-*` (CSS di `livewire/cashier/partials/head.blade.php`, bergantung `html[data-product-view]`); aksi `h-11`: qty 0 -> "Tambah"; qty>0 -> `grid grid-cols-[44px_minmax(0,1fr)_44px]` dengan input qty; plus disabled bila `!unlimited && qty >= stock`; "Habis" hanya bila `!unlimited && stock<=0`. Gambar `relative aspect-[4/3] overflow-hidden` + `<img absolute inset-0 w-full h-full object-cover width height loading=lazy decoding=async onerror>`.
- Keranjang: grup `cartGroups` (judul sticky uppercase + jumlah item), baris `wire:key="cart-item-{id}"` tanpa `rand()`, stepper `grid-cols-[44px_minmax(2rem,auto)_44px] min-w-[116px]`, trash di qty 1, menu Opsi (diskon item @feature(Discount), `<livewire:price-setting>`), tile ringkasan (Meja bila fnb, Member @feature, Diskon, Voucher, Catatan pesanan) membuka modal yang ada; modal bayar menampilkan Member + due date bila metode kredit; snackbar Urungkan -> `$wire.restoreCartItem(...)`.
- ID DOM tetap: `printReceiptButton`, `qr-reader`, modal `edit-detail`, `proceed-the-payment`, `success-modal`, `modal-selected-table`, `qr-scanner-modal`.
- Tombol "Proceed to payment": `await $wire.syncCartForPayment(); $dispatch('open-modal',{id:'proceed-the-payment'})`.
- Z-index: dock 20, scrim 30, sheet 30, modal Filament 40 (tidak diubah).

### 11.4 Tabel terjemahan LENGKAP (EN -> ID); satu pemilik `cashier-shell` (`lang/id.json`, `lang/en.json`; en.json: nilai = kunci; ar/es hanya fallback)
Baru: `All`=Semua; `Menu view`=Tampilan menu; `Grid view`=Tampilan kotak; `Compact list`=Daftar ringkas; `Load more`=Muat lebih banyak; `No products found`=Produk tidak ditemukan; `No products match`=Tidak ada menu yang cocok; `No products in this category`=Belum ada produk di kategori ini; `Reset filters`=Atur ulang; `:count menu`=:count menu; `Clear search`=Hapus pencarian; `Scan barcode`=Pindai barcode; `Add`=Tambah; `Out of stock`=Habis; `Stock :count`=Stok :count; `Remaining :count`=Sisa :count; `Add :name`=Tambah :name; `Reduce :name`=Kurangi :name; `Remove :name`=Hapus :name; `Quantity of :name`=Jumlah :name; `Item note`=Catatan item; `Add note`=Tambah catatan; `Edit note`=Ubah catatan; `Add note for :name`=Tambah catatan :name; `Edit note for :name`=Ubah catatan :name; `Item note placeholder`=Contoh: tanpa gula, pedas, es dipisah; `Clear note`=Hapus catatan; `Done`=Selesai; `Order note`=Catatan pesanan; `Uncategorized`=Tanpa kategori; `Orders`=Pesanan; `View order`=Lihat pesanan; `Close order`=Tutup pesanan; `:count items`=:count item; `Pay :amount`=Bayar :amount; `More options`=Opsi lainnya; `Empty cart`=Kosongkan; `Confirm empty cart`=Yakin kosongkan?; `Undo`=Urungkan; `Item removed`=Item dihapus; `Unsaved`=Belum tersimpan; `Saving...`=Menyimpan...; `Saved`=Tersimpan; `Failed to save, tap to retry`=Gagal menyimpan, ketuk untuk ulang; `You are offline`=Anda sedang offline; `Some changes are not saved yet`=Ada perubahan yang belum tersimpan; `Note saved`=Catatan tersimpan; `Customer name saved`=Nama pelanggan tersimpan; `Will be recorded as :name`=Perkiraan: akan dicatat sebagai :name; `Clear customer name`=Hapus nama pelanggan; `Draft restored`=Draft dipulihkan; `Discard draft`=Buang draft; `Receipt copy`=Salinan; `Printer not configured`=Printer belum diatur; `No transaction to print`=Tidak ada transaksi untuk dicetak; `Print failed`=Gagal mencetak.
Ada di kode tetapi hilang di id.json: `Subtotal`=Subtotal; `Customer Name`=Nama pelanggan; `Customer Name (Optional)`=Nama pelanggan (opsional); `Enter customer name`=Masukkan nama pelanggan; `Uang Pas`=Uang pas.
Dipakai ulang: `Note, Customer, Category, Cashier, Table, Payment method, General, Tax, Tax price, Discount, Total price, Payed money, Change, Member, Voucher, Search (SKU, name, barcode), Current Orders, Proceed to payment, Pay it, Close, Save, Print, No item, Stock, Stock is out`. Pakai `__('Key')` (bukan `@lang` di dalam `@js`). Test `LangKeysTest` memindai view baru dan memastikan setiap kunci `__('...')` ada di id.json.

### 11.5 PAKET KERJA FINAL (9 paket, 3 gelombang)
Gelombang 1 (paralel, hanya bergantung pada kontrak 11.3): `data-layer`, `cashier-livewire`, `receipt-print`, `design`, `tests-core`, `tests-cashier`.
Gelombang 2 (setelah gelombang 1 mendarat; membaca kode yang benar-benar ada + mock v3): `product-view`, `cashier-shell`.
Gelombang 3: `verify`, lalu `code-reviewer`, `test-runner`, `uiux-analyst`, `debugger` bila perlu.
Pemilik berkas disjoint (tabel lengkap di keluaran kontrak).

### 11.6 Tambahan test dan kriteria penerimaan
Test baru/diubah (tambahan terhadap §7): grup keranjang tetap setelah `commitCustomerName`/`syncCartForPayment`/`storeCart` (bukan hanya setelah `addCart`) dan jumlah query render konstan 5 vs 20 item; `searchInPos` LIKE dengan "100% Juice"/"a_b" dan infix barcode (SQLite); produk layanan + non-stok tampil dan bisa ditambah; draft dengan Table/Member terhapus, keranjang kosong, `fillMember`; `loadForReceipt` produk soft-deleted, dua kategori bernama sama, idempoten; `ViewSelling` produk soft-deleted tidak 500; sorter null-safe; API `selling_details[].note` di index/show/store + request lama tanpa `note` tetap 200; `SellingService` lock dilepas, dua `create` berurutan "Budi" -> "Budi (2)"; `CartItemNote::save` isolasi per kasir; `restoreCartItem` + event `cart-item-removed`; `addCart(amount:12.5/0/-1)`; `PlainText::fromHtml` dengan `<img onerror>`/`<script>`; JS (Node `vm`, Printer palsu) untuk 58 mm dan 80 mm, catatan 300 karakter tanpa spasi, nama kategori panjang, ESC/GS pada catatan, kutip pintar, nama non-Latin -> fallback SKU, catatan HTML lama, `groupDetails` memakai `category.id`; statis: tidak ada `innerHTML|outerHTML|insertAdjacentHTML|document.write` di partial, `.cut()` hanya di partial, tidak ada `@include('...receipt-print')`, render hook terdaftar, `keypress` lama hilang; `CashierPerfTest` (PERF=1): 5000 produk x {tanpa cari, "kopi", limit 96} dan `addCart` waktu + ukuran HTML induk (tidak berisi `data-pv-card`).
Kriteria penerimaan tambahan: (1) qty `9999`, `12,5`, `10000` tidak overflow di keranjang dan kartu pada 320/375/768/1280; (2) modal satuan harga dan modal Bayar dari dalam sheet pada 375 px menutup viewport penuh; (3) browser: blur catatan bersamaan klik + (satu tick) -> keranjang dan qty tetap ter-render; (4) simpan gagal (offline) menampilkan "Gagal" dan menolak bayar; (5) nama diketik lalu Bayar tanpa blur tersimpan; (6) scanner simulasi (karakter < 50 ms + Enter) menambah produk sekali walau fokus di input nama/cari; (7) Undo memulihkan item beserta catatan; (8) perf: ambang CashierPerfTest terpenuhi, `addCart` induk tidak merender produk; (9) konsol bersih (tanpa ReferenceError scanner lama).

### 11.7 Pertanyaan ke Randy (tidak memblokir; default direkomendasikan)
Lihat `open_questions_for_user` pada keluaran kontrak; tiap item memuat rekomendasi default.

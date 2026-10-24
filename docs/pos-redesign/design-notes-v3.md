# Design notes v3: Kasir Open Bill ("Pesan dulu, bayar belakangan")

Mock: `kasir-mock-v3.html` (satu berkas, JS murni, terang + gelap, 4 bingkai perangkat berbagi satu data).
Titik awal: `kasir-mock-v2-fixed.html` (= v2 + perbaikan plan.md bagian 11; salinannya disimpan karena nama v3 sebelumnya dipakai berkas itu).
Repo tidak disentuh. Semua data fiktif.

Cara melihat: buka berkas, ketuk saja. Tiap perangkat punya baris "Pintasan" (Menu, Bon, Pesanan, Tiket dapur, Bon sementara, Bayar tunai, QRIS, Kredit, Batalkan bon) yang hanya mempercepat; semua layar tetap bisa dicapai dengan mengetuk. Alat uji di atas: tema, offline, keyboard HP tiruan, FnB/Retail, reload halaman, lewat tengah malam WIB, scanner, qty ekstrem, "Cek overflow dan target sentuh".

---

## 1. Ringkasan keputusan (yang berubah dari v2)

| # | Keputusan | Alasan |
|---|-----------|--------|
| 1 | **Satu bon = satu pesanan terbuka.** Draft per akun kasir dihapus: tidak ada banner "Draft dipulihkan" dan tombol "Buang draft". Nama, meja, member, diskon, voucher, catatan, item, dan status dapur semuanya milik bon. | Permintaan Randy no. 4. Reload tidak menghilangkan apa pun; bon ada di tab Bon. |
| 2 | **Bon dibuat otomatis begitu item pertama ditambahkan** dan nomornya langsung terlihat (`#0016`). Mengosongkan bon yang belum pernah dikirim ke dapur membuangnya (nomor tidak dipakai ulang). | Tidak ada langkah "buat bon" yang bisa terlupa. Perubahan tidak hilang bila koneksi putus. |
| 3 | **Tiga aksi di panel pesanan:** `Simpan` (tutup bon dari layar, buka bon baru), `Kirim ke dapur` (cetak hanya yang baru), `Bayar`. Ditaruh di footer panel, bukan di header, supaya terjangkau ibu jari. Header hanya identitas bon + menu `⋯`. | Alur kasir: ambil pesanan, Simpan, layani pelanggan berikutnya. |
| 4 | **Kirim ke dapur hanya mengirim yang BARU** sejak kiriman terakhir: qty tambahan, item baru, pembatalan (BATAL), perubahan catatan, pindah meja. Selalu lewat pratinjau tiket dulu. | Dapur tidak boleh menerima pesanan dobel. |
| 5 | **Bayar** tetap bisa dari nol (tanpa Simpan/Kirim). Bila ada item yang belum dikirim, opsi "Cetak tiket dapur sekarang" menyala otomatis, sehingga alur bayar-dulu lama tetap sama. | Tempat usaha yang tidak memakai open bill tidak kehilangan apa pun. |
| 6 | **Tab Bon**: daftar bon terbuka (tertua di atas), cari, filter, dan per kartu: buka, Tambah, Cetak bon sementara, Bayar, `⋯` (Batalkan dst). Di tablet tetap berdampingan dengan panel bon aktif. | Permintaan no. 1. |
| 7 | **Rumus harga per item** `Rp 18.000 × 2 = Rp 36.000` di keranjang, rincian bayar, bon sementara, dan struk (`2 x 18.000   36.000`). Tiket dapur sengaja tanpa harga. | "Jangan lupa harga per itemnya". |
| 8 | **Pembayaran dirombak:** layar penuh di HP, dialog dua kolom di tablet. Total besar, ubin metode, panel per metode, tombol bayar dengan alasan nonaktif yang terlihat + status memproses. | Permintaan no. 1 (pemilihan pembayaran). |
| 9 | **Nomor harian** (bon dan nomor nama kembar) kembali ke 1 setiap pukul 00.00 WIB. Bon yang masih terbuka melewati tengah malam tetap memakai nomor lamanya dan diberi chip `Kemarin`. | Permintaan no. 5. |
| 10 | Dihapus: tombol `Kosongkan` (diganti `Batalkan bon` dengan alasan), tile Meja (jadi tombol di samping input nama), banner draft. | Konsistensi dengan bon. |

---

## 2. Peta layar

```
                       ┌──────────── Tab Menu ────────────┐      ┌──────────── Tab Bon ─────────────┐
HP  (<768)             │ cari + kotak/daftar + chip       │      │ cari + filter + kartu bon        │
                       │ dock: "Meja 6 · Dina · 6 item"   │      │   tap kartu  ─► sheet pesanan    │
                       └──────┬───────────────────────────┘      │   Tambah ─► Menu (bon itu aktif) │
                              │ tap dock                          │   Cetak  ─► modal Bon sementara  │
                              ▼                                   │   Bayar  ─► layar Pembayaran     │
                  Sheet pesanan (90% tinggi)  ◄───────────────────┤   ⋯      ─► modal Aksi bon       │
                  ├ ⋯ ─► modal Aksi bon ─► Bon sementara / Ulang tiket / Simpan / Batalkan (konfirmasi)
                  ├ Meja ─► modal Pilih meja (titik kuning = terisi, tawarkan "Buka bon #...")
                  ├ Simpan ─► kembali ke bon baru, toast
                  ├ Kirim ke dapur ─► modal Tiket dapur (hanya baru) ─► Cetak dan kirim
                  └ Bayar ─► layar Pembayaran ─► Sukses (struk) ─► Selesai ─► bon baru
Tablet (>=768): Menu/Bon di kiri, panel bon aktif tetap di kanan (320 px, 360 px di >=1024). Modal tetap menutup seluruh layar perangkat.
```

Tab dan tombol `Bon baru` ada di baris pertama header. `Bon baru` = simpan bon aktif (otomatis sudah tersimpan) dan mulai bon kosong; di HP hanya ikon `+` di bawah 360 px.

---

## 3. Siklus bon dan aturan dapur

Status bon (UI): `terbuka` → `dibayar` | `dibatalkan` (dibatalkan tetap tercatat dengan alasan, tidak pernah dihapus permanen).

Per baris item yang diperlukan UI: `qty`, `catatan`, `sent_qty` (qty yang sudah dikirim ke dapur), `sent_note` (catatan saat dikirim).

| Keadaan item (bon yang pernah dikirim) | Chip di baris keranjang | Masuk tiket dapur sebagai |
|---|---|---|
| `sent_qty = 0` | `Baru` (oranye) | `Nx NAMA` di bawah judul kategori |
| `qty > sent_qty` | `Terkirim 2 · baru 1` | selisihnya (`1x`) |
| `qty = sent_qty`, catatan sama | `Terkirim` (hijau) | tidak dicetak |
| `qty = sent_qty`, catatan berubah | `Catatan berubah` (kuning) | bagian `** UBAH CATATAN **` |
| `qty < sent_qty` atau item dihapus | `Batal 1` (merah) | bagian `** BATAL **` |
| meja berubah sejak kiriman terakhir | (tidak ada chip) | baris `PINDAH dari meja 4` |

Bon yang belum pernah dikirim tidak menampilkan chip per item (semuanya "baru", tidak perlu ramai).

Judul tiket: `PESANAN BARU` (kiriman 1), `TAMBAHAN - KIRIMAN KE-n`, `PEMBATALAN` (hanya BATAL). `MEJA 4` atau `BAWA PULANG` dicetak besar. Qty dicetak besar (2x tinggi/lebar; nama normal-tebal di baris yang sama). Judul kategori hanya bila ada >= 2 kategori. Catatan item: `> catatan` tebal. Tombol Kirim ke dapur: aktif bila ada >= 1 perubahan, lencana menunjukkan jumlah perubahan.

Bon sementara (bon sebelum bayar): kop sama dengan struk, tulisan `** BON SEMENTARA **`, `BELUM DIBAYAR`, `BON #0012`, daftar item dengan harga per item, total, `Belum dibayar / Bukan bukti pembayaran`. Tidak menutup bon, hanya memberi tanda "Bon dicetak" di kartu.

Batalkan bon: modal konfirmasi di halaman (bukan `alert()`): ringkasan bon, peringatan bila dapur sudah menerima porsi, sakelar "Cetak tiket pembatalan ke dapur" (menyala bila ada yang terkirim), alasan wajib (Pelanggan batal, Salah input, Menu habis atau tidak jadi, Lainnya + keterangan). Fokus awal di `Kembali`. Tombol bahaya nonaktif sampai alasan dipilih.

Pindah bon: sebelum bon aktif diganti, antrean simpan (nama dan catatan item) harus selesai. Bila gagal, tetap di bon itu dengan toast.

---

## 4. Komponen, ukuran, teks

Semua target sentuh >= 44 x 44 px kecuali yang tercatat di bagian 8. Ukuran dalam px kecuali disebut lain. "scr" = lebar layar perangkat (di aplikasi nyata = viewport / breakpoint Tailwind).

### 4.1 Header (di atas Menu dan Bon)
- Baris tab: `tablist` 2 kolom, tinggi 44, garis bawah aktif 3 (`primary-strong`). Teks `Menu` | `Bon` + lencana jumlah (24 x 24, bulat). Tombol `Bon baru`: tinggi 44, min 44, ikon `+`, label tampil >= 360 (teks `Bon baru`), border `primary-strong`.
- Menu: cari 44 (`Cari (SKU, nama, barcode)`), segmented kotak/daftar 44 x 44 x 2, pindai 44. Chip kategori tinggi 40 (pengecualian disetujui) + hitungan statis.
- Bon: cari 44 (`Cari meja, nama, atau no. bon`), chip filter `Semua n`, `Bon saya n`, `Belum dikirim n` (disembunyikan untuk Retail), `Kemarin n`.
- Dock HP (Tab Menu saja, hanya bila ada item): tinggi min 56, kiri `Meja 6 · Dina · 6 item` + total, kanan `Lihat pesanan`.

### 4.2 Kartu bon (`bill-card-{id}`)
- Baris 1: judul (`Meja 4 · Budi`, `Budi (2)` bila nama kembar hari ini) + total (tebal, kanan).
- Baris 2: `#0012 · 3 item` + chip status: `Belum dikirim` (kuning), `+2 baru` (oranye), `Terkirim` (hijau), `Kemarin` (hitam).
- Baris 3: umur `12 mnt` / `1 j 5 mnt` / `Kemarin 23.40` + kasir (`Dewi`) + `Bon dicetak` + chip `Sedang dibuka`.
- Badan kartu = satu tombol (buka bon), min tinggi 44. Baris aksi: `Tambah`, `Cetak`, `Bayar` (utama), `⋯` (44 x 44); ikon disembunyikan < 360.
- Kartu aktif: border 1 + ring 1 `primary-strong`. Daftar: 1 kolom HP, >= 560 grid `auto-fill minmax(280px,1fr)`. Urutan: tertua dulu.
- Ringkasan di atas daftar: `6 bon terbuka · Rp 655.000 belum dibayar`. Catatan kaki: aturan reset 00.00 WIB.
- Kosong: `Belum ada bon terbuka` + tombol `Ke menu`. Filter tanpa hasil: `Tidak ada bon yang cocok` + `Atur ulang`.

### 4.3 Panel pesanan (sheet HP / kolom kanan tablet)
- Header: judul bon (`Meja 4 · Budi` | `Bon baru`), baris kecil `Bon #0012 · dibuka 13.22 · Dewi` + pil status simpan agregat (`Tersimpan` / `Menyimpan...` / `Belum tersimpan` / `Gagal menyimpan, ketuk untuk ulang`), tombol `⋯` (44, nonaktif bila belum ada bon), tombol tutup (HP).
- Nama pelanggan + tombol meja satu baris: input 44 (maks 240) dan `Meja 4`/`Pilih meja` (min 92 x 44, disembunyikan untuk Retail). Pil status nama hanya tampil bila belum `Tersimpan`. Petunjuk nama kembar: `Sudah ada Budi hari ini. Akan dicatat sebagai Budi (2).`
- Daftar per kategori: judul kelompok lengket (huruf kapital, hitungan `2 item`). Baris item: nama + chip dapur; **rumus** `Rp 18.000 × 2` (kiri, muted) dan `= Rp 36.000` (kanan, tebal); `Satuan: ...`; catatan item (blok oranye, ikon pena); tombol `Catatan`/`Ubah catatan`, `⋯` opsi (diskon item, satuan harga), stepper qty (input desimal, tombol 44). Dengan diskon item: `Rp 18.000 × 2 − Rp 2.000` lalu `= Rp 34.000`.
- Tile: Member, Diskon, Voucher, Catatan pesanan (membuka modal edit-detail yang ada). Ringkasan: `Subtotal (n porsi)`, `Diskon`, `Voucher ...`.
- Footer: `Total` (1.1rem tebal); baris `[Simpan] [Kirim ke dapur n]` (grid 5fr / 7fr, tinggi 44; Retail: satu tombol `Simpan bon`); `Bayar Rp ...` (tinggi 52). Footer disembunyikan saat keyboard HP terbuka.
- Modal `Aksi bon` (`⋯`): Buka bon (bila bukan aktif), Tambah pesanan, Cetak bon sementara, Cetak ulang tiket dapur (bila pernah dikirim), Simpan dan buka bon baru, Batalkan bon (bahaya). Baris 56 tinggi.

### 4.4 Pembayaran
Wadah: HP = layar penuh (tinggi 100%, tanpa sudut), >= 560 = dialog (maks 800, tinggi maks viewport - 24), >= 720 = dua kolom (5fr kiri / 6fr kanan: kiri total, ubin, rincian, opsi dapur; kanan panel metode). Tidak ada penutupan lewat klik latar. Header 56: `←` kembali, `Pembayaran` + `Meja 4 · Budi · Bon #0012`, ikon cetak bon sementara.

| Bagian | Spesifikasi |
|---|---|
| Total tagihan | Label `Total tagihan`; angka 1.9rem tebal tabular (1.6rem < 360, 2.1rem >= 720); subteks `5 item · Subtotal Rp 85.000 · Diskon − Rp 2.000`. |
| Metode | `radiogroup`, ubin tinggi 64, ikon 22 + nama; 4 kolom >= 360, 2 kolom < 360 dan di kolom kiri tablet. Sumber data = tabel metode bayar (flag `is_cash`, `is_wallet`, `is_debit`, `is_credit`; nama dan ikon dari data). Contoh: Tunai, QRIS, Kartu Debit, Kredit. |
| Tunai | Kotak `Uang diterima` (angka 1.7rem, label di atas, tombol hapus 44), 4 nominal cepat (`Uang pas`, 3 pecahan terdekat di atas total: kelipatan 5.000, 10.000, 20.000, 50.000, 100.000, lalu +100.000), papan angka 3 x 4 (`7 8 9 / 4 5 6 / 1 2 3 / 000 0 ⌫`, tinggi 48, 52 di tablet), keyboard fisik: angka, Backspace, Enter. |
| QRIS (wallet) | Kartu QR 168 x 168 (gambar QR toko dari pengaturan), `NMID`, nominal pas; status `Menunggu pembayaran masuk` (titik berkedip) lalu `Pembayaran diterima`; sakelar konfirmasi manual `Pembayaran QRIS sudah masuk` (56). Tidak ada kembalian. Bila kelak memakai gateway, tiga keadaan (menunggu, dibayar, kedaluwarsa) menempati slot yang sama. |
| Kartu (debit) | Ikon kartu + `Gesek, tempel, atau masukkan kartu di mesin EDC...`, sakelar `Transaksi kartu disetujui`. |
| Kredit | Member (wajib, select 44), `Jatuh tempo` (tanggal, wajib) + chip `+7 hari`, `+14 hari`, `+30 hari`, kotak info `Tagihan Rp ... dicatat sebagai piutang atas nama ..., jatuh tempo ...`. |
| Opsi dapur | Muncul bila FnB dan ada perubahan belum dikirim: sakelar `Cetak tiket dapur sekarang` (`n perubahan belum dikirim ke dapur`), default aktif. |
| Rincian pesanan | Tombol buka/tutup 44 (terbuka otomatis >= 720). Daftar per kategori dengan rumus harga per item, catatan, lalu Subtotal/Diskon/Voucher. |
| Footer (lengket) | Baris status (live region): `Kembalian Rp 15.000` hijau, `Kurang Rp 8.000` merah, atau alasan nonaktif (`Masukkan uang yang diterima`, `Konfirmasi pembayaran sudah masuk`, `Pilih member dulu`, `Pilih tanggal jatuh tempo`). Tombol bayar tinggi 56: `Bayar Rp 85.000` (Kredit: `Catat piutang Rp 85.000`). |

Status tombol bayar: **nonaktif** (abu, dengan alasan di atasnya), **aktif**, **memproses** (`Memproses pembayaran...` + spinner, `aria-busy`, semua kontrol lain nonaktif, tidak bisa ditutup), **gagal** (kotak merah `Tidak ada koneksi. Pembayaran belum diproses dan bon tidak berubah.` + `Coba lagi`; juga bila nama atau catatan item gagal tersimpan). Pembayaran ditolak selama ada simpanan nama atau catatan yang gagal (perilaku `LakasirPending.settle()` v2 dipertahankan).

### 4.5 Sukses
Judul `Pembayaran berhasil`; ikon centang 60; `Lunas · Tunai`; identitas bon; kotak besar `Kembalian Rp 15.000` (Kredit: `Piutang <member>` + jatuh tempo; QRIS/Kartu: `Dibayar lewat ...`); info `Tiket dapur ikut dicetak: n perubahan` bila opsi menyala; pratinjau struk (58/80 mm); `Cetak struk` (`id=printReceiptButton`) dan `Selesai` (menutup, bon baru). Menutup dengan Esc sama dengan Selesai.

### 4.6 Pratinjau cetak (modal Tiket dapur, Bon sementara, Sukses)
Kertas termal putih, monospace, hitam putih (anotasi warna hanya di panel uji di bawah halaman). Pilihan 58 mm (32 kolom) / 80 mm (48 kolom). Bila 80 mm tidak muat di HP, kertas bisa digeser mendatar di dalam kotaknya.

---

## 5. Format cetak (satu kode, tiga mode)

`LakasirReceipt.build(printer, selling, {mode: 'receipt' | 'provisional' | 'kitchen', reprint})`

```
Struk / Bon sementara (32 kolom)            Tiket dapur
    WARUNG RND                                *** DAPUR ***
 Jl. Melati No. 8, Bandung                 TAMBAHAN - KIRIMAN KE-2
   15/10/2026 12:53                        MEJA 4           <- ukuran 2x
  ** BON SEMENTARA **   (hanya prov)       Pelanggan : Budi
    BELUM DIBAYAR                           Bon #0012  Kasir: Dewi
ORDER #0012  (prov: BON #0012)              15/10/2026 12:53
--------------------------------           --------------------------------
Kasir     : Dewi                           [ MINUMAN ]          <- bila >= 2 kategori
Meja      : 4                              2x Kopi Susu Gula Aren   <- "2x" ukuran 2x
Bayar     : Tunai (hanya struk)               > less sugar
Pelanggan : Budi (2)                       --------------------------------
--------------------------------           ** BATAL **
[ MAKANAN UTAMA ]                          1x Es Teh Manis Jumbo
Mie Goreng Spesial                         --------------------------------
  1 x 22.000              22.000           Total baru: 2 porsi, 2 item
  > pedas level 3                                 Kiriman ke-2
--------------------------------
Subtotal                  59.000
TOTAL                     59.000
Tunai                    100.000
Kembali                   41.000
```

- Baris item: `  {qty} x {harga satuan}` kiri, `{qty x harga}` kanan. Bila tidak muat dalam satu baris, total pindah ke baris berikutnya rata kanan (tidak pernah dipotong).
- Diskon item: baris `  Diskon   -2.000`. Satuan harga non-default: `  (Botol 1 liter)`. Catatan: `  > catatan` dibungkus dengan indent 4.
- Kredit: baris `Kredit (piutang)`, `Member`, `Jatuh tempo`. QRIS/Debit: satu baris `<metode>   <total>`.
- Karakter non-ASCII (kutip pintar, dash, kanji) diubah/diganti SKU seperti v2. Tiket dapur: qty ditulis dengan ukuran 2x (kolom terpakai 2 per karakter, nama membungkus di sisa kolom), notes tebal.
- Nomor yang dicetak adalah **nomor bon yang sama** di bon sementara, tiket dapur, dan struk (dapur dan kasir menyebut nomor yang sama).

---

## 6. Nomor harian dan hari bisnis (kontrak UI)

- Nomor bon `#0001...` dan nomor nama kembar `Budi`, `Budi (2)` dihitung per **hari bisnis WIB** (Asia/Jakarta, dapat diatur), reset ke 1 setelah 00.00 WIB.
- Nomor nama kembar dihitung dari bon lain hari ini (terbuka atau sudah dibayar) bernama sama; bon kemarin tidak ikut dihitung. Hint di input nama hanya perkiraan; angka final dicatat server.
- Bon yang dibuka kemarin dan masih terbuka: tetap bernomor lama, chip `Kemarin`, umur `Kemarin 23.40`; tidak ikut memengaruhi urutan hari ini. Mock menunjukkannya dengan tombol "Simulasi lewat tengah malam (WIB)" (nomor bon berikutnya = `#0001`, nomor nama direset).
- Rekomendasi untuk perencana: satu fungsi `BusinessDay` (zona bisnis default Asia/Jakarta, batas hari dikonversi ke UTC untuk query) dipakai oleh nomor harian, `CustomerNameService`, dan filter "hari ini" di jalur POS. `app.timezone` tetap UTC.

---

## 7. Perilaku data yang dibutuhkan UI (bukan desain skema)

Untuk dinilai perencana bersama batasan MySQL (migrasi aditif, kolom nullable, tanpa menulis ulang data):

- **Bon**: `id`, nomor harian, hari bisnis, status (terbuka, dibayar, dibatalkan), meja, nama pelanggan (+ nomor kembar), member, catatan, diskon, voucher, kasir pembuka, waktu buka, jumlah kiriman dapur, waktu kirim terakhir, meja saat kirim terakhir, "bon sementara dicetak", alasan batal (+ siapa dan kapan).
- **Baris**: `qty`, `catatan`, `sent_qty`, `sent_note` (dua kolom nullable baru).
- **Salinan tiket dapur terakhir** untuk "Cetak ulang": menyimpan muatan tiket per kiriman (atau menyusun ulang dari `sent_qty` bila cukup).
- Sumber daftar Bon harus satu query tanpa N+1 (judul, jumlah item, total, chip dapur, umur dihitung dari kolom bon + agregat baris).
- Daftar Bon dibaca semua kasir; filter `Bon saya` memakai kasir pembuka.

---

## 8. Responsif, aksesibilitas, target sentuh

- Lebar yang diuji: HP 320, 360, 375, 414; tablet 768, 1024, 1280. Titik pindah (container "scr"): 360 (ubin metode 4 kolom, label `Bon baru`, quick amount 4 kolom), 400 (ikon di footer disembunyikan di bawahnya), 560 (kartu bon grid, pembayaran jadi dialog), 720 (pembayaran dua kolom), 768/`md` (panel bon di kanan), 1024/`lg` (panel 360).
- Tab: `role=tablist`, ArrowLeft/Right, `aria-selected`; panel `role=tabpanel`. Modal `role=dialog aria-modal`, `inert` pada latar, Tab terkurung, Esc menutup (kecuali saat memproses), fokus kembali ke pemicu. Modal pembatalan fokus awal di `Kembali`; pembayaran fokus awal di ubin metode terpilih.
- Status bon dan status bayar `role=status`; kesalahan pembayaran `role=alert`. Tidak ada `alert()`.
- Kontras: token v2 (`primary-strong #c2410c` untuk tombol teks putih; gelap `#fb923c` dengan teks `#1f1209`); tambahan token `--on-danger` (putih terang / `#2a0a0a` gelap) untuk tombol bahaya.
- **Pengecualian target sentuh** (tercatat, diaudit otomatis): chip kategori/filter tinggi 40; lebar input qty (tinggi tetap 44); pil status simpan non-interaktif; pegangan sheet `grab` (28 tinggi, ada tombol tutup 44 sebagai pengganti); kontrol alat uji di luar bingkai.
- Animasi sheet tetap lewat `bottom` + `opacity` (tanpa transform pada leluhur modal), `prefers-reduced-motion` menonaktifkan transisi.

---

## 9. Kamus teks (usulan kunci `lang/id.json`)

`Bon`, `Bon baru`, `Bon terbuka`, `Simpan`, `Simpan bon`, `Kirim ke dapur`, `Bayar`, `Tambah`, `Cetak`, `Cetak bon sementara`, `Bon sementara`, `Belum dibayar`, `Bukan bukti pembayaran`, `Belum dikirim`, `Terkirim`, `Baru`, `Batal`, `Catatan berubah`, `Kemarin`, `Sedang dibuka`, `Bon dicetak`, `Tiket dapur`, `Cetak dan kirim`, `Cetak ulang tiket dapur`, `Hanya {n} perubahan yang dicetak`, `Tidak ada item baru sejak kiriman terakhir`, `Batalkan bon`, `Alasan pembatalan`, `Pelanggan batal`, `Salah input`, `Menu habis atau tidak jadi`, `Lainnya`, `Cetak tiket pembatalan ke dapur`, `Pembayaran`, `Total tagihan`, `Rincian pesanan`, `Uang diterima`, `Uang pas`, `Kembalian`, `Kurang`, `Menunggu pembayaran masuk`, `Pembayaran diterima`, `Pembayaran QRIS sudah masuk`, `Transaksi kartu disetujui`, `Pilih member dulu`, `Pilih tanggal jatuh tempo`, `Catat piutang`, `Memproses pembayaran...`, `Coba lagi`, `Pembayaran berhasil`, `Lunas`, `Selesai`, `Nomor bon dan nomor nama kembar kembali ke 1 setiap pukul 00.00 WIB`.

---

## 10. data-testid yang disarankan

Tambahan pada daftar plan.md 11 (yang lama tetap: `product-card-{id}`, `category-chip-*`, `view-toggle-*`, `product-search`, `product-load-more`, `product-qty-{id}`, `cart-group-{key}`, `cart-item-*`, `customer-name-*`, `cart-dock`, `cart-sheet`, `undo-snackbar`; `draft-restored` dihapus).

| Area | Selector |
|---|---|
| Tab/header | `tab-menu`, `tab-bills`, `bills-count`, `new-bill` |
| Daftar Bon | `bill-search`, `bill-filter-{all\|mine\|unsent\|prev}`, `bills-summary`, `bills-list`, `bills-footnote`, `bill-card-{id}`, `bill-open-{id}`, `bill-add-{id}`, `bill-print-{id}`, `bill-pay-{id}`, `bill-more-{id}` |
| Panel pesanan | `cart-title`, `cart-subtitle`, `bill-save-status`, `cart-menu`, `table-button`, `cart-total`, `cart-save`, `cart-send-kitchen`, `cart-pay` |
| Aksi bon | `bill-menu-{open\|add\|prov\|reprint\|new\|cancel}` |
| Batalkan | `cancel-reason-{cust\|wrong\|unavail\|other}`, `cancel-print-kitchen`, `cancel-back`, `cancel-confirm` |
| Meja | `table-occupied`, `table-open-existing` |
| Bon sementara | `prov-paper`, `prov-print`, `paper-58`, `paper-80` |
| Tiket dapur | `kitchen-summary`, `kitchen-empty`, `kitchen-paper`, `kitchen-print-confirm` |
| Pembayaran | `pay-back`, `pay-print-prov`, `pay-total`, `pay-details-toggle`, `pay-details`, `pay-method-{id}`, `pay-cash-display`, `pay-quick-{exact\|nominal}`, `pay-key-{0-9\|000\|bk}`, `pay-qris-state`, `pay-qris-confirm`, `pay-credit-member`, `pay-credit-due`, `pay-due-chip-{7\|14\|30}`, `pay-kitchen-toggle`, `pay-status`, `pay-submit`, `pay-retry` |
| Sukses | `success-change`, `success-kitchen`, `success-paper`, `success-print` (`id=printReceiptButton` tetap), `success-done` |

ID DOM yang dipertahankan: `printReceiptButton`, modal `edit-detail`, `proceed-the-payment`, `success-modal`, `modal-selected-table`; baru: `bill-menu`, `cancel-bill`, `provisional-bill`, `kitchen-ticket`.

---

## 11. Pertanyaan terbuka dan risiko untuk perencana

1. **Kapan nomor bon diberikan.** Mock: saat item pertama ditambahkan (nomor langsung terlihat, tidak dipakai ulang bila bon dibuang, sehingga bisa ada celah). Alternatif: saat Simpan/Kirim pertama. Perlu keputusan karena `daily_order_number` sekarang dibuat saat pembayaran.
2. **Dua perangkat mengedit bon yang sama.** Perlu pemeriksaan versi dan pesan "Bon diubah di perangkat lain". Mock tidak mensimulasikan.
3. **Stok.** Bon terbuka tidak mengurangi stok; dua bon dapat menjual stok yang sama. Cek stok tetap di saat bayar (`CheckProductStock`), dan sebaiknya ada peringatan lunak saat menambah.
4. **Harga dan promo bon lama.** Harga item dikunci saat ditambahkan; voucher dan diskon dievaluasi ulang saat bayar.
5. **Tiket dapur: reprint dan audit.** Perlu catatan tiket per kiriman (muatan atau penyusunan ulang). Printer dapur terpisah belum dimodelkan (satu printer).
6. **Harga di tiket dapur** sengaja tidak ada; bila perlu, jadikan pengaturan.
7. **Pindah/gabung/pisah meja dan pisah tagihan** tidak termasuk.
8. **Metode QRIS** dimodelkan konfirmasi manual (sesuai kode sekarang: nominal pas, tanpa gateway).
9. **Hak akses.** Siapa boleh membatalkan bon orang lain atau yang sudah dikirim ke dapur belum diputuskan; mock mengizinkan semua kasir.
10. **Bon lintas tengah malam** dibayar hari berikutnya: tanggal penjualan = tanggal bayar, nomor = nomor bon lama; struk perlu menyebut tanggal buka bila berbeda.

---

## 12. Hasil verifikasi (otomatis, Chrome headless)

- "Cek overflow dan target sentuh": 4 perangkat x lebar (HP 320/360/375/414, tablet 768/1024/1280) x tampilan kotak dan daftar (12 modal diuji pada tampilan kotak) x tab Menu/Bon x sheet/panel x 12 modal (edit, meja, satuan, aksi bon, batalkan, bon sementara, tiket dapur, bayar tunai/QRIS/kartu/kredit, sukses): **semua OK** (tidak ada elemen keluar layar, tidak ada scroll mendatar, semua target sentuh >= 44 kecuali pengecualian pada bagian 8). Halaman sendiri tanpa scroll mendatar di viewport 320, 375, 768, 1024.
- Alur: 44 asersi (e2) + 19 asersi (e3) lulus: kartu bon (`Meja 4 · Budi`, `3 item`, `Rp 58.000`, `12 mnt`, kasir), Tambah memuat bon, kirim ke dapur hanya item baru, BATAL, bon sementara dengan harga per item, konfirmasi batal butuh alasan, bayar tunai (papan angka, kembalian, memproses, sukses, struk), kredit butuh member dan jatuh tempo, QRIS butuh konfirmasi, meja terisi, tengah malam (nomor ke 1, nama kembar reset), pembayaran ditolak saat simpan nama gagal lalu berhasil setelah online, reload tidak menghilangkan bon, Retail tanpa dapur/meja, scanner, filter dan cari Bon, bayar dari daftar, kembali ke pembayaran setelah cetak bon sementara.
- Terang dan gelap diperiksa lewat tangkapan layar. Yang disimulasikan: printer, jaringan, keyboard HP, scanner, QR (gambar acuan), waktu (umur bon memakai jam browser).

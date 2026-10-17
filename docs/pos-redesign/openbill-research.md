# Brief Open Bill untuk Lakasir

Dasar riset (terbatas): help center majoo (Simpan Order / Pisah Bayar / Daftar Order), Loyverse (open ticket + kitchen printer), Square (void vs comp), Paloma/Mekari (split, merge, pindah meja). Dokumen resmi Moka/Pawoon/iReap tidak terjangkau lewat pencarian; poin soal mereka adalah konvensi umum, bukan kutipan.

## 10 pola diadopsi sekarang
1. Bill = entitas persisten dengan status: Terbuka -> Dibayar | Dibatalkan. Semua state (nama pelanggan, meja, catatan, item) milik bill, bukan akun kasir. Bill bisa dibuka ulang dan ditambah item berkali-kali.
2. Layar "Daftar Order" (istilah majoo): kartu/baris per bill: nomor harian, meja/nama, jumlah item, total, umur bill (mis. "35 mnt"), badge "Belum dikirim ke dapur". Filter: Terbuka / Dibayar / Batal; cari nama/meja. Ini juga tempat kasir membayar bill.
3. Dua aksi terpisah: "Simpan" (tanpa cetak) dan "Kirim ke Dapur" (simpan + cetak). Loyverse mencetak saat open ticket disimpan; Lakasir sebaiknya eksplisit supaya tidak cetak tak sengaja.
4. Tiket dapur hanya berisi item BARU sejak kirim terakhir. Simpan di tiap baris detail penanda terkirim (qty_sent atau sent_at) agar delta = qty - qty_sent. Tambahan qty ikut menjadi delta.
5. Isi tiket dapur: header "TAMBAHAN" bila bukan kiriman pertama, meja/nama besar, nomor harian, jam, kasir, qty besar (double-size) di depan nama, catatan item di bawah nama (inden, huruf tebal), dikelompokkan per kategori. TANPA harga.
6. Item dibatalkan setelah terkirim: tampil di tiket dapur berikutnya sebagai "BATAL" (analog KDS Loyverse yang mencoret merah). Pola Square: item terkirim tidak dihapus senyap; wajib alasan + tercatat.
7. Bon Sementara (cetak/pratinjau sebelum bayar): judul "BON SEMENTARA - BELUM LUNAS", harga satuan x qty = total baris (permintaan Randy), subtotal/pajak/total, tanpa kembalian, tanpa metode bayar. Struk final memakai partial yang sama (window.LakasirReceipt) dengan judul berbeda.
8. Void/Batal bill: item belum terkirim bebas dihapus; bill dengan item terkirim butuh konfirmasi + alasan wajib, tidak boleh hapus fisik (soft status "Dibatalkan", stok tidak berubah karena stok baru dipotong saat bayar). Pembatasan lewat permission yang sudah ada.
9. Layar pembayaran dirombak: ringkasan bill di atas (total besar), grid metode sebagai tombol besar (Tunai, QRIS, Transfer, Kartu, dll dari data metode yang ada), bukan dropdown. Tunai: tombol cepat "Uang Pas" + pecahan terdekat (mis. 50.000, 100.000 dihitung dari total, naik ke pecahan berikutnya), input nominal, "Kembalian" besar berwarna. Non-tunai: nominal otomatis = total, tanpa kembalian. Satu tombol primer "Proses Bayar" (istilah majoo), nonaktif sampai valid. Setelah sukses: "Transaksi Berhasil" + Cetak Struk / Bill Baru.
10. Mobile/layar kecil: Daftar Order dan Pembayaran satu kolom, tombol aksi menempel bawah (sticky), minimal 44px, keranjang jadi bottom-sheet dengan bilah total ("3 item - Rp 45.000 - Lihat Order"), nomor/meja tidak terpotong, tab bawah: Menu | Order | Daftar.

## 5 ditunda
1. Pisah Bayar / split bill (majoo: wajib Simpan Order dulu; per jumlah dan per produk, dampak pajak/promo rumit). Tahap berikut; skema bill_payments bisa disiapkan sekarang tapi jangan dibuat.
2. Gabung Bill dan Pindah Meja/Pindah Item (Mekari, Paloma, Loyverse). Pindah meja sederhana (ganti field meja) boleh masuk karena murah; gabung/pindah item ditunda.
3. Tiket dapur per stasiun/printer berbeda dan KDS layar dapur.
4. Pembayaran sebagian/DP dan pelunasan bertahap.
5. Reservasi/denah meja visual, area makan, biaya layanan per meja; juga auto-void bill menggantung (hanya beri peringatan umur bill).

## Wording label
- Tombol utama keranjang: "Kirim ke Dapur" (sekunder: "Simpan"); "Bayar" untuk langsung bayar.
- Tab/nav: "Menu" | "Order" | "Daftar Order"; filter status: "Terbuka", "Lunas", "Batal".
- Aksi bill: "Tambah Item", "Cetak Bon Sementara", "Cetak Ulang Dapur", "Batalkan Order", "Ganti Meja".
- Badge: "Belum dikirim", "Sudah dikirim", "Tambahan baru (2)".
- Bayar: "Pilih Metode Bayar", "Uang Pas", "Uang Diterima", "Kembalian", "Proses Bayar", "Transaksi Berhasil", "Cetak Struk", "Bill Baru".
- Field: "Nama Pelanggan", "Meja", "Catatan Order", "Catatan Item".
- Dialog batal: "Batalkan order ini? Item yang sudah dikirim ke dapur akan ditandai BATAL." + alasan wajib.
- Kosong: "Belum ada order terbuka".

## Catatan desain untuk planner
- Bill terbuka tidak memotong stok dan tidak masuk laporan penjualan; jurnal, stok, dan laporan hanya saat Lunas. Beri status eksplisit, jangan pakai flag yang bisa bocor ke laporan lama (tanggung jawab migrasi additive MySQL: kolom status default 'paid' agar data produksi lama otomatis lunas).
- Nomor harian dialokasikan saat bill dibuat (urutan pesanan), bukan saat bayar, memakai BusinessDay Asia/Jakarta; kasir menyebut "nomor 12" ke dapur.
- Kesepakatan Randy butir 4: draft per akun kasir diganti draft = bill. "Bill Baru" otomatis menyimpan bill berjalan ke Daftar Order sehingga tidak ada kehilangan data.

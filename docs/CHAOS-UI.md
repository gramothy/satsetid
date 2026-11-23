# Chaos test UI SatSet

Tujuan: memastikan UI tidak rusak ketika data yang tidak ideal masuk, misalnya deskripsi tanpa spasi yang membuat halaman melebar. Tes dijalankan dengan database terpisah (salinan SQLite) yang diisi data terburuk, jadi database demo tidak tersentuh.

## Data terburuk yang diuji

| Kasus | Data |
| --- | --- |
| Deskripsi tanpa spasi | 600 karakter `A` |
| Nama outlet tanpa spasi | `Ayam Bakar` + 120 karakter `X` |
| Kategori tanpa spasi | `Kategori` diulang 20 kali |
| URL panjang di deskripsi | 25 segmen `segmen-panjang-` |
| Nama campur emoji dan teks RTL | 30 emoji + teks Arab |
| Nama satu huruf dan deskripsi kosong | `X`, `null` |
| Batas tamu besar | `max_party_size` 50 (input angka, bukan pilihan radio) |
| Antrean besar | 400 tiket aktif di satu outlet |
| Nama dan email akun panjang | nama 175 karakter, email 96 karakter |
| Nama outlet panjang di daftar akun | 120 karakter tanpa spasi |

Viewport: 320×568, 390×844, dan 1440×900.

## Yang ditemukan sebelum perbaikan

| Halaman | Gejala |
| --- | --- |
| Outlet A (mobile) | Halaman melebar 4797 px. Konten terdorong keluar layar. |
| Outlet A (desktop) | Melebar 5377 px. Ini bug dari screenshot. |
| Outlet B | Melebar 323 px (mobile) dan 903 px (desktop) karena URL dan kategori. |
| Tiket A (desktop) | Tombol "kembali" dengan nama outlet panjang mendorong kartu tiket keluar 245 px. |
| Admin, daftar akun | Nama outlet panjang tumpah dari sel tabel dan menimpa kolom lain (lebar 1158 px di sel 320 px). |

## Perbaikan

Semua perbaikan berupa CSS. Tidak ada perubahan logika.

- **Kolom grid boleh menyusut.** `.page` memakai `grid-template-columns: minmax(0, 1fr)`, dan anak-anaknya `min-width: 0`. Penyebab utama pelebaran adalah kolom `auto` yang mengikuti lebar kata terpanjang.
- **Kata tanpa spasi boleh dipatahkan.** `overflow-wrap: anywhere` pada teks pengguna (nama, deskripsi, kategori, tombol kembali, status).
- **Arah teks campuran.** `unicode-bidi: plaintext` pada teks pengguna, supaya RTL dan emoji tidak mengacaukan urutan baris.
- **Tabel admin.** Sel boleh dipatahkan dan menyusut, sehingga tidak menimpa kolom di sebelahnya.
- **Badge dan tombol admin** boleh turun baris, tidak lagi memaksa lebar.
- Versi aset dinaikkan: customer `ui-11`, admin `ui-11`.

## Hasil setelah perbaikan

- Overflow horizontal: 0 di semua halaman, di tiga viewport.
- Pelanggaran teks meluap dari kotaknya: 0, selain elemen yang memang disembunyikan untuk pembaca layar (`sr-only`) dan tautan lewati navigasi.
- `php artisan test`: 158/158 lulus.

## Yang belum diperbaiki

- **Antrean 400 tiket di satu halaman admin.** Halaman memuat semua tiket aktif sekaligus, sekitar 12.600 node DOM di 390 px, dan layout sekitar 30 ms per render. Ini tidak bisa diperbaiki dengan CSS. `content-visibility` pada baris tabel sudah dicoba dan tidak mengubah angka, jadi dihapus lagi. Perbaikannya ada di controller dan view: paginasi atau batas tampilan. Itu mengubah perilaku, jadi perlu keputusan pemilik.
- Tes ini belum dijalankan di ponsel fisik.

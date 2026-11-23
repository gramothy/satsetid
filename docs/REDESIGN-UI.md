# Redesign UI dan UX SatSet

> Repo ini diterapkan ke `gramothy/satsetid` (branch `main`, commit `2a5fd2a`). Halaman customer identik dengan basis sebelumnya, jadi angka Lighthouse customer berlaku juga di sini. Halaman admin ditambah halaman **Hubungkan WhatsApp** dan **Log WhatsApp** yang juga sudah diukur dan dirapikan.
>
> Tambahan untuk repo ini:
> - Halaman **Hubungkan WhatsApp** (`/admin/whatsapp/connect`): kartu catatan keamanan diberi `aria-label` "Catatan keamanan WhatsApp", sehingga tidak ada landmark ganda (axe `landmark-unique`).
> - Semua wadah tabel yang bisa digulir horizontal (`.admin-table-wrap`) sekarang `tabindex="0"`, supaya bisa difokus dengan keyboard (axe `scrollable-region-focusable`, sebelumnya serius).
> - Tautan admin "Hubungkan WhatsApp" tetap ada di sidebar dan ikut mendapat area sentuh 44 px.
>
> Hasil uji di repo ini: `php artisan test` **158/158 lulus**.

Lingkup: tampilan, interaksi, performa, dan aksesibilitas. Logika aplikasi (controller, service, rute, validasi, JavaScript yang memanggil endpoint) tidak diubah. Tes `php artisan test` tetap 71/71 lulus sebelum dan sesudah perubahan.

## Hasil terukur

Diukur pada 2026-11-23 dengan Lighthouse (mobile, throttling default) dan pengukuran DOM Playwright di Edge headless. Server lokal memakai `php artisan serve` (tanpa gzip dan tanpa header cache), jadi peringatan kompresi dan cache di Lighthouse adalah artefak server dev, bukan kondisi produksi.

| Halaman | Metrik | Sebelum | Sesudah |
| --- | --- | --- | --- |
| Beranda (mobile) | Performa | 89 | **99** |
| | LCP | 3.6 s | **2.3 s** |
| | FCP | 2.0 s | **0.8 s** |
| | Bobot halaman | 465 KB | **223 KB** |
| | Request | 11 | **9** |
| | SEO | 92 | **100** |
| Outlet, form web (mobile) | Performa | 97 | **99** |
| | Tombol "Ambil nomor antrean" terlihat tanpa scroll | Tidak (bawah di y=963, layar 844) | **Ya (bawah di y=622)** |
| | Bobot halaman | 206 KB | **142 KB** |
| Tiket (mobile) | Performa | 97 | **100** |
| | LCP | 2.1 s | **1.7 s** |
| Halaman masuk admin | Font input | 15 px (zoom di iOS) | **16 px** |
| | Pelanggaran aksesibilitas axe | 2 | **0** |
| Halaman tiket | Pelanggaran axe (tidak ada h1) | 1 | **0** |
| Admin, antrean (mobile) | Target sentuh di bawah 44 px | 20 dari 22 | **0 dari 22** |
| Semua halaman | Permintaan ke pihak ketiga (Google/Bunny Fonts) | ya | **tidak ada** |

Data hasil ukur mentah (JSON) ada di folder scratchpad sesi ini (`ui-before`, `ui-final`, `lh\before`, `lh\final`). Screenshot sebelum dan sesudah ada di `screenshots\ui-before` dan `screenshots\ui-final` (folder `screenshots` tidak di-commit).

## Perubahan dan alasannya

Setiap perubahan mengacu ke aturan dari skill `emil-design-eng`, `mobile-native`, dan `animation-performance`.

### Performa

- **Font Inter dilayani sendiri** (`public/fonts/inter-latin-wght.woff2`, 48 KB, variabel). Sebelumnya dimuat dari fonts.bunny.net dengan CSS yang memblokir render. Sekarang di-preload dan `font-display: swap`. Dua permintaan pihak ketiga hilang.
- **Hero beranda jadi WebP** (`satset-food-hero.webp`, 105 KB, turun dari 325 KB JPEG) dan di-preload dengan `fetchpriority="high"`. Background CSS tidak bisa ditemukan browser sebelum CSS diproses, jadi preload adalah yang membuat LCP turun dari 3.6 s ke 2.3 s.
- **Gambar restoran dalam WebP**, dengan JPEG sebagai fallback. Komponen `restaurant-image` memakai `<picture>` dan hanya menyajikan WebP jika file pendampingnya ada. Ukuran turun sekitar 68% (56 KB ke 18 KB). Data restoran di database tidak berubah.
- **Backdrop blur di header dihapus.** Blur di elemen yang sticky dan selalu terlihat membebani compositing di ponsel. Header sekarang memakai latar putih solid.

### Mobile (QoL)

- **Tombol utama naik di halaman outlet.** Urutan di ponsel sekarang: foto, nama dan status, tombol "Ambil nomor antrean", lalu kondisi antrean. Sebelumnya tombol di bawah lipatan. Foto diperkecil menjadi rasio 2:1 di ponsel dan `object-position` dibuat agar judul di foto tetap terlihat. Urutan DOM juga berubah, supaya urutan fokus keyboard sama dengan urutan visual.
- **Header menghormati notch.** `padding-top: env(safe-area-inset-top)` pada header sticky. Footer dan kontainer halaman sudah memakai inset bawah.
- **`interactive-widget=resizes-content`** di viewport. Keyboard virtual Android mengecilkan layout, bukan menutupi input.
- **Theme color** diselaraskan dengan warna paling atas di layar: putih untuk pengunjung, `#12302f` untuk admin (sidebar gelap di ponsel).
- **Input admin 16 px** supaya iOS tidak zoom saat fokus.
- **Target sentuh minimal 44 px** pada layar sentuh (`pointer: coarse`) di admin: tombol kecil, tautan kembali, logo, item navigasi, dan paginasi.
- **Pilihan jumlah tamu** memakai kolom `minmax(44px, 1fr)`, jadi enam pilihan muat dalam satu baris di 390 px.
- **`user-select: none` dan `-webkit-touch-callout: none`** hanya pada kontrol (tombol, label pilihan jumlah tamu, logo). Konten tetap bisa dipilih.

### Motion (sesuai Emil Kowalski)

- **Press feedback** `scale(0.97)` pada `:active` dengan 160 ms `ease-out`. Sebelumnya 0.98.
- **Hanya properti yang berubah yang dianimasikan.** Tidak ada `transition: all`. Transisi tiket saat status berubah (dipanggil, selesai, dibatalkan) memakai `background-color`, `color`, dan `border-color` dengan 400 ms `ease-out`. Perubahan status tidak lagi mendadak.
- **Flash message masuk dengan `@starting-style`** (opacity dan sedikit translate, 240 ms). Tidak memakai `setTimeout` atau kelas tambahan.
- **Tiga langkah di beranda masuk bertahap** (stagger 40 ms, opacity dan translate 8 px). Hanya untuk pengunjung baru, dan tidak aktif jika `prefers-reduced-motion: reduce`. Stagger tidak memblokir interaksi.
- **Data yang terus diperbarui tidak bergerak.** Angka antrean dan estimasi berganti langsung, tanpa animasi.
- **Reduced motion** tetap mempertahankan transisi warna dan opacity, dan menghapus gerak. Sebelumnya semua transisi dimatikan.
- **Hover hanya di perangkat dengan pointer presisi** (`(hover: hover) and (pointer: fine)`), tetap seperti sebelumnya.

### Aksesibilitas

- **Halaman tiket punya h1.** Nomor antrean sekarang `<h1 class="ticket-number">`. Teksnya tidak berubah. Ini menutup satu pelanggaran axe.
- **Halaman masuk admin punya `<main>`.** Ini menutup dua pelanggaran axe (landmark dan region).
- **Tautan "Lewati navigasi"** tetap ada dan tersembunyi sampai difokus.

### Tema dan konsistensi

- Token warna tidak berubah. Brand `#2e6d6d` dipertahankan sesuai CLAUDE.md. Nilai baru ditambahkan: `--ease-in-out`.
- Versi aset dinaikkan ke `ui-10` (customer) dan `ui-9` (admin), sesuai konvensi di CLAUDE.md.
- Tautan `manifest.json` ditambahkan. Berkas itu sudah ada di `public/` tetapi belum tertaut, jadi aplikasi tidak bisa dipasang ke layar utama.

## Yang tidak diubah

- Tidak ada perubahan di `app/` (controller, service, model, middleware, rute, validasi, atau konfigurasi).
- Tidak ada perubahan pada `public/js/`.
- Tidak ada perubahan teks UI, kecuali penambahan `meta description`.
- Nama field form, `id`, dan atribut `data-*` yang dibaca JavaScript tetap sama.

## Yang belum dilakukan dan kenapa

- **Tes di perangkat fisik belum dilakukan.** Semua pengukuran berasal dari emulasi dan Lighthouse. Aturan `mobile-native` meminta tes di ponsel, terutama untuk keyboard, notch, dan perilaku hover. Saya tidak punya akses ke ponsel dari sesi ini. Silakan cek di ponsel lewat remote debugging (Chrome `chrome://inspect` atau Safari Web Inspector).
- **CSS dan JS belum di-minify.** CLAUDE.md menyebut tidak ada build step. Ukuran CSS saat ini sekitar 14 KB, jadi dampaknya kecil.
- **Gambar JPEG asli restoran dan hero masih ada** sebagai fallback. Hero JPEG (`satset-food-hero.jpg`) sudah tidak dipakai di CSS dan bisa dihapus.
- **Ikon logo** `logo-128.png` ditampilkan 32 px. Ukuran yang lebih kecil bisa menghemat sedikit. Lighthouse menandainya di modern-image-formats.
- **Gzip dan cache header** perlu diatur di server produksi. Peringatan itu muncul karena server lokal tidak mengirimnya.
- **Checkbox di admin** tetap 20 px. Label-nya sudah memperluas area sentuh, jadi ini tidak dihitung sebagai masalah.

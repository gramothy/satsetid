# SatSet — Team Progress

File ini adalah pusat koordinasi pengembangan SatSet.

Semua anggota tim cukup melihat dan meng-update file ini untuk mengetahui:

- konsep SatSet saat ini
- fitur yang sudah selesai
- fitur yang sedang dikerjakan
- pembagian tugas
- blocker
- next priority
- cara menjalankan project
- aturan Git

---

# 1. Project Overview

**SatSet** adalah platform virtual queue untuk bisnis F&B seperti:

- restoran
- cafe
- food stall
- dessert shop
- beverage shop
- bisnis F&B lainnya

SatSet membantu customer mengantre tanpa harus berdiri dan menunggu secara fisik.

---

# 2. Final Customer Flow

Flow utama SatSet:

```text
Customer datang ke restoran
        ↓
Scan QR SatSet
        ↓
Masuk ke halaman restoran SatSet
        ↓
Lihat jumlah antrean + estimasi waktu
        ↓
Klik "Lanjut via WhatsApp"
        ↓
Chatbot SatSet terbuka
        ↓
Chatbot mengetahui restoran
        ↓
Customer memilih jumlah orang / party size
        ↓
Customer konfirmasi
        ↓
Queue Ticket dibuat
        ↓
Customer mendapatkan nomor antrean
        ↓
Update antrean dikirim lewat WhatsApp
        ↓
Customer kembali ketika giliran tiba
```

---

# 3. Status (17 November 2026)

## Sudah selesai

- Halaman outlet dari QR (`/r/{slug}`): jumlah antrean, estimasi, tanpa login.
- Chatbot WhatsApp: `ANTRE <slug>` → jumlah tamu → `YA`. Tiket hanya dibuat setelah `YA`.
- Daftar lewat web (opsional, `SATSET_WEB_SIGNUP=true`).
- Halaman tiket yang ter-update sendiri.
- Admin: peran pemilik dan admin outlet (kasir), kelola antrean, QR Code, riwayat, akun admin, log WhatsApp.
- Notifikasi WhatsApp: tinggal 3, tinggal 1, giliran tiba, dibatalkan. Notifikasi tidak lagi terlewat saat posisi melompat (misalnya dua orang batal sekaligus), dan posisi dihitung dengan satu query.
- Pesan masuk tidak hilang saat Laravel atau gateway mati: event disimpan di `wa-gateway/data/webhook-queue/` sampai Laravel menerimanya.
- Pemantauan WhatsApp (`php artisan schedule:work`): email ke pemilik saat sesi diputus, gateway mati, pesan menumpuk atau banyak gagal; peringatan juga tampil di Dashboard.
- CI GitHub Actions untuk test Laravel (PHP 8.3 dan 8.4) dan wa-gateway (Node 22 dan 24). `composer.lock` kini bisa dipasang di PHP 8.3.
- **WhatsApp lewat Baileys** (`wa-gateway/`): nomor outlet disambungkan dengan scan QR di **Admin → Hubungkan WhatsApp**. Hanya membalas nomor yang chat lebih dulu, jeda acak antar pesan, dan batas pesan harian. Meta Cloud API dan Twilio dinonaktifkan (kodenya belum dihapus).

## Perlu diuji

- Uji manual dengan nomor WhatsApp sungguhan: scan QR, chat `ANTRE ayam-bakar-nusantara-a` dari HP lain, panggil antrean, dan pastikan "tinggal 3" serta "giliran tiba" terkirim sekali. Lalu matikan gateway saat ada pesan tertunda dan pastikan pesan terkirim setelah gateway hidup lagi.

## Blocker dan risiko

- **Baileys tidak resmi dan melanggar ketentuan WhatsApp.** Nomor bisa diblokir. Pakai nomor khusus outlet/demo, jangan nomor pribadi.
- Belum ada hosting permanen: web dan wa-gateway berjalan dari laptop.

## Prioritas berikutnya

Lihat daftar backlog di `CLAUDE.md`, antara lain threshold notifikasi per restoran, reset nomor antrean harian, perintah `STATUS`/`BATAL` di chatbot, dan penghapusan kode Meta/Twilio.

---

# 4. Cara Menjalankan

- Instalasi dan WhatsApp: `README.md` (bagian **Menghubungkan WhatsApp**).
- Demo: `docs/PANDUAN-PRESENTASI.md`.
- Uji coba semua fitur: `docs/PANDUAN-UJI-COBA.md`.
- Test otomatis: `php artisan test`, dan `npm test` di folder `wa-gateway`. Keduanya juga dijalankan GitHub Actions di setiap push ke `main` dan pull request.

---

# 5. Aturan Git

- Jangan pernah commit `.env`, `wa-gateway/.env`, `wa-gateway/auth_info/` (sesi WhatsApp), `wa-gateway/data/` (antrean webhook), atau nilai asli kredensial.

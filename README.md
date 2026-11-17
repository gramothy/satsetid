# SatSet

SatSet adalah antrean virtual untuk bisnis F&B (restoran, cafe, food stall). Customer **scan QR di outlet**, mendaftar lewat **chatbot WhatsApp**, lalu menerima kabar giliran lewat WhatsApp tanpa harus berdiri menunggu.

```text
Scan QR di outlet → halaman outlet (/r/{slug}) → "Lanjut via WhatsApp"
→ chatbot: jumlah tamu → konfirmasi YA → nomor antrean (mis. A-001)
→ notifikasi: tinggal 3 antrean, tinggal 1 antrean, giliran tiba
```

> - Menjalankan SatSet untuk presentasi atau demo: [docs/PANDUAN-PRESENTASI.md](docs/PANDUAN-PRESENTASI.md)
> - Mencoba seluruh fitur langkah demi langkah: [docs/PANDUAN-UJI-COBA.md](docs/PANDUAN-UJI-COBA.md)

## Daftar isi

1. [Teknologi](#teknologi)
2. [Kebutuhan](#kebutuhan)
3. [Instalasi pertama kali](#instalasi-pertama-kali)
4. [Menjalankan aplikasi](#menjalankan-aplikasi)
5. [Akses dari HP dengan scan QR](#akses-dari-hp-dengan-scan-qr)
6. [Akun admin dan peran](#akun-admin-dan-peran)
7. [Mencoba chatbot tanpa WhatsApp](#mencoba-chatbot-tanpa-whatsapp)
8. [Menghubungkan WhatsApp (Baileys; Twilio, Meta, GREEN-API opsional)](#menghubungkan-whatsapp)
9. [Test otomatis](#test-otomatis)
10. [Masalah umum](#masalah-umum)

## Teknologi

- **Backend:** Laravel 13 (PHP 8.3+)
- **Frontend:** Blade, CSS, dan JavaScript biasa (tanpa build step, file ada di `public/css` dan `public/js`)
- **Database:** MySQL untuk development, SQLite in-memory untuk test
- **WhatsApp:** Baileys (koneksi WhatsApp Web, **tidak resmi**) lewat `wa-gateway/` (Node.js) sebagai bawaan. Twilio, Meta WhatsApp Cloud API, dan GREEN-API tetap bisa dipilih lewat `WHATSAPP_PROVIDER`
- **QR code:** `endroid/qr-code`

## Kebutuhan

- PHP **8.3 atau lebih baru** dengan ekstensi `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`. `composer.lock` dikunci untuk PHP 8.3 (`config.platform.php` di `composer.json`), jadi `composer install` berjalan di PHP 8.3 maupun 8.4
- Composer
- MySQL atau MariaDB (MySQL Server atau XAMPP; **pakai salah satu saja** agar port 3306 tidak bentrok)
- Node.js **20.12 atau lebih baru** (disarankan 22 LTS), hanya untuk `wa-gateway` saat WhatsApp dipakai. Tanpa WhatsApp, Node.js tidak diperlukan

## Instalasi pertama kali

Jalankan perintah berikut dari folder proyek (folder yang berisi file `artisan`).

**1. Instal dependency PHP**

```bash
composer install
```

**2. Buat file `.env` dan app key**

```bash
cp .env.example .env
```

```bash
php artisan key:generate
```

**3. Buat database MySQL** bernama `satset`, lewat terminal MySQL atau phpMyAdmin:

```sql
CREATE DATABASE satset CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

**4. Isi koneksi database di `.env`**

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=satset
DB_USERNAME=root
DB_PASSWORD=password_mysql_anda
```

Untuk XAMPP, `DB_PASSWORD` biasanya dikosongkan.

**5. Buat tabel dan data contoh** (3 restoran contoh beserta beberapa antrean)

```bash
php artisan migrate --seed
```

**6. Buat akun pemilik SatSet** (Anda akan diminta mengetik password minimal 12 karakter)

```bash
php artisan satset:admin email@anda.com --name="Nama Anda"
```

## Menjalankan aplikasi

```bash
php artisan serve
```

Biarkan terminal tetap terbuka (tekan **Ctrl + C** untuk berhenti), lalu buka:

| Halaman | Alamat |
|---|---|
| Beranda customer | http://localhost:8000 |
| Halaman outlet (hasil scan QR) | http://localhost:8000/r/{slug-outlet} |
| Admin | http://localhost:8000/admin |

Slug outlet bisa dilihat di **Admin → QR Code**.

Jika WhatsApp sudah diaktifkan, jalankan juga worker di terminal kedua supaya pesan keluar terkirim (dan `wa-gateway`, lihat [Menghubungkan WhatsApp](#menghubungkan-whatsapp)):

```bash
php artisan queue:work
```

## Akses dari HP dengan scan QR

Cara ini dipakai untuk mencoba alur customer dari HP sungguhan: scan QR, lalu buka halaman outlet dan halaman tiket.

**Syarat:** HP dan laptop terhubung ke **Wi-Fi yang sama**.

**1. Jalankan server untuk jaringan lokal** (menggantikan `php artisan serve`)

```bash
php artisan satset:lan
```

Perintah ini menampilkan alamat laptop di jaringan, misalnya:

```text
Admin QR : http://192.168.1.189:8000/admin/qr
```

**2. Buka alamat tersebut di browser laptop**, bukan `localhost`. Login sebagai admin, lalu klik **Lihat QR** pada outlet.

Karena halaman admin dibuka lewat alamat jaringan, QR otomatis berisi alamat yang bisa dibuka HP (misalnya `http://192.168.1.189:8000/r/...`). File `.env` tidak perlu diubah.

**3. Scan QR dengan kamera HP.** Halaman outlet akan terbuka di HP.

**4. Kalau Windows menampilkan pop-up Firewall** saat server dijalankan, pilih **Allow** untuk jaringan **Private**. Kalau HP tetap tidak bisa membuka halaman, lihat [Masalah umum](#masalah-umum).

> Catatan:
> - Alamat jaringan laptop bisa berubah kalau Wi-Fi tersambung ulang. Jalankan `php artisan satset:lan` lagi untuk melihat alamat terbaru.
> - QR yang memakai alamat jaringan hanya berlaku di Wi-Fi tersebut. Untuk QR yang dicetak dan ditempel di outlet, isi `APP_URL` dengan domain HTTPS (misalnya `https://satsetid.my.id`); QR akan selalu memakai `APP_URL` jika isinya bukan `localhost`.
> - Untuk mengakses dari jaringan mana pun (misalnya data seluler), gunakan tunnel. Lihat [Menghubungkan WhatsApp](#menghubungkan-whatsapp) langkah 8.

## Akun admin dan peran

| Peran | Akses |
|---|---|
| **Pemilik SatSet** | Semua outlet, tambah atau nonaktifkan restoran, kelola akun admin, hubungkan nomor WhatsApp, log WhatsApp |
| **Admin outlet** (kasir) | Hanya outlet yang dipilihkan: kelola antrean, QR, dan pengaturan outlet (kecuali nama, prefix, dan status aktif) |

- Akun pemilik dibuat dengan `php artisan satset:admin`.
- Akun kasir dibuat oleh pemilik di **Admin → Akun admin**. Setiap kasir sebaiknya punya akun sendiri. Satu akun boleh memegang beberapa outlet.
- Kasir yang memegang satu outlet langsung diarahkan ke halaman antrean outletnya setelah login.
- Akun yang dinonaktifkan tidak bisa login dan langsung keluar dari sesi yang sedang berjalan.

## Mencoba chatbot tanpa WhatsApp

Alur chatbot bisa disimulasikan dari terminal tanpa akun WhatsApp. Data akan masuk ke database lokal.

```bash
php artisan tinker
```

Tempel baris berikut (sekali saja). `$wa` mengirim pesan sebagai customer, `$inbox` menampilkan pesan yang diterima customer:

```php
$hp = '6281111111111'; $wa = function ($t) use ($hp) { app(App\Services\WhatsAppChatbotService::class)->receive(['id' => 'sim-'.uniqid(), 'from' => $hp, 'text' => ['body' => $t]]); echo App\Models\WhatsappLog::where('direction', 'outbound')->where('phone_number', $hp)->latest('id')->value('message'), "\n"; }; $inbox = function () use ($hp) { App\Models\WhatsappLog::where('direction', 'outbound')->where('phone_number', $hp)->oldest('id')->get()->each(fn ($m) => print("---\n{$m->message}\n")); };
```

Lalu chat seperti di WhatsApp (ganti slug sesuai outlet Anda):

```php
$wa('ANTRE ayam-bakar-nusantara-a');
$wa('2');
$wa('YA');
```

Setelah itu klik **Panggil berikutnya** di halaman antrean admin, lalu jalankan `$inbox();` untuk melihat notifikasi "tinggal 3 antrean", "tinggal 1 antrean", dan "giliran tiba". Untuk mensimulasikan customer lain, jalankan ulang baris `$hp = ...` dengan nomor berbeda.

## Menghubungkan WhatsApp

> [!WARNING]
> **Baca dulu: risiko memakai Baileys**
> - SatSet tersambung ke WhatsApp lewat **Baileys**, library tidak resmi yang meniru WhatsApp Web. **Cara ini melanggar Ketentuan Layanan WhatsApp.** Nomor yang dipakai bisa dibatasi atau **diblokir permanen** sewaktu-waktu, tanpa peringatan.
> - Pakai **nomor khusus outlet/demo**. Jangan pakai nomor pribadi atau nomor bisnis utama.
> - SatSet sengaja membatasi diri: hanya membalas nomor yang **lebih dulu chat ke bot**, memberi **jeda acak 3–8 detik** antar pesan, membatasi **200 pesan per hari** (reset tengah malam WIB), dan **tidak punya** fitur pesan massal atau promosi. Jangan mengubahnya untuk broadcast.
> - `WHATSAPP_ENABLED` bawaannya `false`. Nyalakan hanya saat diperlukan, misalnya untuk demo.
> - Untuk layanan sungguhan, gunakan WhatsApp Business Platform resmi.

Cara kerjanya:

```text
HP customer ⇄ WhatsApp ⇄ wa-gateway (Node + Baileys, 127.0.0.1:3100) ⇄ Laravel (127.0.0.1:8000)
```

`wa-gateway` hanya memegang sesi WhatsApp. Semua logika (chatbot, antrean, outbox pesan) tetap di Laravel. Keduanya hanya berbicara di komputer yang sama: Laravel memanggil gateway dengan token, dan gateway mengirim pesan masuk, status pesan, dan status sesi ke `POST /webhooks/baileys` dengan signature HMAC.

### 1. Instal wa-gateway (sekali)

Butuh Node.js 20.12 atau lebih baru (cek dengan `node --version`).

```bash
cd wa-gateway
```

```bash
npm install
```

### 2. Buat token dan secret

Jalankan perintah ini **dua kali** untuk mendapat dua nilai acak yang berbeda:

```bash
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
```

Nilai pertama menjadi **token** (Laravel → gateway), nilai kedua menjadi **secret webhook** (gateway → Laravel). Jangan pernah commit atau membagikan keduanya.

### 3. Isi `wa-gateway/.env`

Salin `wa-gateway/.env.example` menjadi `wa-gateway/.env` (di Windows: `copy .env.example .env`), lalu isi:

```env
WA_GATEWAY_TOKEN=nilai-pertama
WA_WEBHOOK_SECRET=nilai-kedua
```

Nilai lain boleh dibiarkan: port `3100`, webhook `http://127.0.0.1:8000/webhooks/baileys`, jeda `3000`–`8000` ms, dan batas `200` pesan per hari. Gateway **tidak** membaca `.env` Laravel. Kalau Laravel berjalan di port lain, sesuaikan `WA_WEBHOOK_URL`.

### 4. Isi `.env` Laravel

```env
WHATSAPP_ENABLED=true
WHATSAPP_PROVIDER=baileys
WHATSAPP_GATEWAY_URL=http://127.0.0.1:3100
WHATSAPP_GATEWAY_TOKEN=nilai-pertama
WHATSAPP_WEBHOOK_SECRET=nilai-kedua
```

Token dan secret harus **sama persis** dengan `wa-gateway/.env`. Lalu jalankan:

```bash
php artisan config:clear
```

### 5. Jalankan empat terminal

| Terminal | Perintah | Fungsi |
|---|---|---|
| 1 | `php artisan serve` (atau `php artisan satset:lan`) | Web SatSet |
| 2 | `php artisan queue:work` | Mengirim pesan keluar dari outbox |
| 3 | `npm start` (di folder `wa-gateway`) | Sesi WhatsApp |
| 4 | `php artisan schedule:work` | Pemantauan dan email peringatan, serta mencoba ulang pesan tertunda setiap menit |

### 6. Scan QR dari halaman admin

1. Login sebagai **pemilik**, buka **Admin → Hubungkan WhatsApp**. QR muncul dan diperbarui otomatis setiap 5 detik.
2. Di HP yang memakai nomor outlet: buka WhatsApp → **Perangkat tertaut** → **Tautkan perangkat**, lalu scan QR.
3. Status berubah menjadi **Tersambung** dan nomornya tampil. Tombol **Lanjut via WhatsApp** di halaman outlet langsung memakai nomor itu (`WHATSAPP_PHONE_NUMBER` tidak dipakai).

Sesi tersimpan di `wa-gateway/auth_info/` (sudah di-*ignore* Git; jangan dibagikan). Saat gateway dijalankan ulang, ia langsung tersambung tanpa scan.

### 7. Coba dari HP lain

Dari HP dengan nomor **lain**, kirim `ANTRE <slug-outlet>` ke nomor outlet (atau scan QR outlet lalu klik **Lanjut via WhatsApp**). Bot membalas, lalu balas jumlah tamu dan `YA`. Semua pesan masuk dan keluar terlihat di **Admin → Log WhatsApp**.

Bot memahami jawaban yang biasa diketik orang: `antre slug` / `ANTRI slug` (huruf besar-kecil dan tanda baca di akhir diabaikan), jumlah tamu seperti `2`, `2 orang`, atau `3 org`, konfirmasi `ya`, `iya`, `ok`, `oke`, `siap`, dan pembatalan `batal`, `tidak`, `ubah`. Setelah tiket dibuat, pesan apa pun dibalas status antrean terbaru (posisi, perkiraan tunggu, link tiket), kapan pun selama tiket masih aktif.

Pesan ke customer memakai format WhatsApp (`*tebal*` untuk nomor antrean dan hal penting), maksimal satu emoji per pesan, dan dibuat singkat supaya terbaca sekilas. Customer menerima: balasan pendaftaran, **tinggal 3 antrean**, **tinggal 1 antrean / Anda berikutnya**, **giliran tiba**, **dilewati** (dipanggil tetapi tidak hadir), dan **dibatalkan**.

### 8. (Opsional) Link tiket untuk customer di jaringan lain

Balasan bot berisi link tiket yang dibangun dari `APP_URL`. Supaya link itu bisa dibuka dari data seluler, buat alamat publik dengan Cloudflare Tunnel:

```bash
cloudflared tunnel --url http://localhost:8000
```

Isi alamat `https://….trycloudflare.com` ke `APP_URL`, lalu jalankan `php artisan config:clear`. wa-gateway sendiri **tidak** perlu tunnel karena webhook-nya lokal.

### Memutus nomor

**Admin → Hubungkan WhatsApp → Putuskan nomor**. Gateway mengeluarkan perangkat dari WhatsApp, menghapus `auth_info/`, dan **tidak** menyambung ulang otomatis. Hal yang sama terjadi kalau perangkat dikeluarkan dari HP atau nomor diblokir (status **Sesi diputus**). Untuk menyambungkan lagi: hentikan gateway (**Ctrl + C**), jalankan `npm start`, lalu scan QR baru.

### Kalau gateway mati

- Pesan keluar tetap **Menunggu kirim** tanpa menghabiskan jatah percobaan, lalu terkirim otomatis begitu gateway tersambung lagi.
- Kalau gateway menolak sementara (batas harian tercapai atau sedang sibuk), Laravel mencoba lagi sampai 5 kali (setiap menit lewat `schedule:work`), lalu menandai **Gagal** (`gateway_unavailable`).
- Kalau koneksi putus **setelah** gateway sempat menerima pesan, pesan ditandai **Gagal** (`ambiguous_timeout`) dan **tidak** dikirim ulang otomatis supaya customer tidak menerima pesan ganda. Cek di WhatsApp HP outlet apakah pesan itu sudah terkirim; kalau belum, klik **Kirim ulang** di **Admin → Log WhatsApp** (khusus pemilik).
- Kalau `queue:work` mati di tengah pengiriman, pesan tertahan di **Mengirim** dan diberi tanda **Macet** setelah 2 menit. Tombol **Kirim ulang** juga tersedia untuk pesan ini.
- Notifikasi yang sudah tidak berlaku saat akhirnya akan dikirim (misalnya "tinggal 3 antrean" untuk tiket yang sudah dilayani karena gateway mati lama) **tidak** dikirim, dan tercatat **Tidak dikirim** (`stale_notification`).
- Log Laravel dan log wa-gateway tidak mencatat token, secret, maupun isi pesan.

### Kalau Laravel mati

Pesan customer yang masuk saat Laravel mati, sedang restart, atau dalam mode maintenance **tidak hilang**. Gateway menyimpan setiap event ke `wa-gateway/data/webhook-queue/pending/` sebelum mengirimnya, lalu mencoba lagi (1, 2, 4 … sampai 30 detik) dengan urutan tetap sampai Laravel menerima. Event juga tetap ada kalau gateway ikut dimatikan, dan dikirim begitu gateway dijalankan lagi.

- Event yang ditolak Laravel (misalnya data tidak valid), terus menyebabkan error, atau tidak diterima dalam 24 jam dipindahkan ke `wa-gateway/data/webhook-queue/failed/`. Setelah penyebabnya diperbaiki, kembalikan ke antrean dengan `npm run webhooks:replay` (di folder `wa-gateway`), lalu jalankan ulang gateway. Hapus file yang sudah ditangani.
- Kalau secret webhook berbeda, event tidak dibuang. Gateway mencatat "Laravel menolak signature webhook" dan terus mencoba sampai secret disamakan.
- Folder `wa-gateway/data/` berisi isi pesan dan nomor HP sampai event diterima Laravel. Folder ini sudah di-*ignore* Git; jangan dibagikan.

### Pemantauan dan peringatan

`php artisan whatsapp:monitor` berjalan setiap menit lewat `php artisan schedule:work` dan memeriksa:

| Masalah | Kapan diperingatkan |
|---|---|
| Sesi diputus atau token gateway salah | Langsung |
| Gateway tidak berjalan, menyambung ulang, atau menunggu scan QR | Setelah 3 menit berturut-turut |
| Pesan keluar menunggu kirim lebih dari 10 menit | Langsung (tanda `queue:work` atau gateway mati) |
| 5 pesan gagal atau lebih dalam 60 menit (di luar `not_opted_in`) | Langsung |
| Pesan tertahan di status Mengirim lebih dari 2 menit (worker berhenti saat mengirim) | Langsung |
| Gateway menahan 10 event atau lebih yang belum diterima Laravel | Langsung |
| Ada event di `wa-gateway/data/webhook-queue/failed/` | Langsung, pengingat sekali sehari |

Peringatan dikirim lewat **email ke semua akun pemilik yang aktif**, diulang paling sering sekali per jam selama masalah berlangsung, ditambah satu email saat masalah selesai. Isi email hanya menyebut jenis masalah dan jumlahnya, tanpa isi pesan, nomor HP, atau kredensial. Atur pengiriman email di `.env` (`MAIL_MAILER`, `MAIL_HOST`, dan seterusnya); bawaan `MAIL_MAILER=log` hanya menulis email ke `storage/logs/laravel.log`.

Masalah yang sama tampil di **Dashboard** pemilik, termasuk peringatan "Pemantauan WhatsApp tidak berjalan" kalau `schedule:work` mati lebih dari 5 menit. Pemantauan hanya aktif kalau `WHATSAPP_ENABLED=true`.

### Provider lain (Twilio, Meta, GREEN-API)

Selain Baileys, SatSet tetap mendukung Twilio, Meta WhatsApp Cloud API, dan GREEN-API lewat `WHATSAPP_PROVIDER=twilio`, `meta`, atau `greenapi`. Variabelnya ada di bagian bawah `.env.example`; langkah Twilio dan Meta ada di README sebelum migrasi Baileys (riwayat Git). Pemantauan pesan tertunda dan pesan gagal juga berlaku untuk provider ini; pemeriksaan gateway hanya untuk Baileys. Server yang sudah memakai provider lain harus menulis `WHATSAPP_PROVIDER` secara eksplisit di `.env`, karena bawaannya sekarang `baileys`.

#### GREEN-API (tidak resmi, untuk demo)

GREEN-API menautkan nomor WhatsApp biasa seperti WhatsApp Web. Tidak perlu verifikasi Meta atau template, tetapi ini **bukan API resmi** dan nomornya bisa diblokir WhatsApp. **Pakai nomor cadangan.**

1. Daftar di console.green-api.com, buat instance (paket Developer), lalu scan QR dari WhatsApp nomor cadangan (**Perangkat tertaut**) sampai status **Authorized**.
2. Buat token webhook acak, misalnya dengan `php -r "echo bin2hex(random_bytes(24));"`.
3. Isi `.env`:

```env
WHATSAPP_ENABLED=true
WHATSAPP_PROVIDER=greenapi
WHATSAPP_PHONE_NUMBER=62...          # nomor cadangan yang ditautkan
GREENAPI_API_URL=https://7107.api.greenapi.com
GREENAPI_ID_INSTANCE=...
GREENAPI_API_TOKEN=...               # apiTokenInstance
GREENAPI_WEBHOOK_TOKEN=...           # token acak dari langkah 2
```

4. Di console GREEN-API → instance → **Change** (pengaturan): isi **webhookUrl** dengan `https://<domain>/webhooks/greenapi`, **webhookUrlToken** dengan token yang sama, lalu aktifkan notifikasi pesan masuk (incoming messages) dan status pesan keluar (outgoing message statuses).

Semua pesan dikirim sebagai teks biasa, termasuk notifikasi antrean, jadi variabel `WHATSAPP_TEMPLATE_*` tidak dipakai.

## Test otomatis

```bash
php artisan test
```

Test memakai database SQLite in-memory dan API WhatsApp tiruan (termasuk wa-gateway tiruan), jadi database lokal tidak tersentuh dan tidak ada pesan yang benar-benar terkirim. Cakupannya meliputi alur chatbot, penomoran antrean dan estimasi waktu, notifikasi, Baileys, Twilio dan Meta (termasuk verifikasi signature), serta pembagian akses pemilik dan admin outlet.

Test wa-gateway (fungsi murni, jeda antar pesan dengan timer palsu, webhook, HTTP, dan sesi dengan socket tiruan; tidak tersambung ke WhatsApp):

```bash
cd wa-gateway
```

```bash
npm test
```

Keduanya juga dijalankan otomatis oleh GitHub Actions (`.github/workflows/tests.yml`) untuk setiap push ke `main` dan setiap pull request: Laravel di PHP 8.3 dan 8.4, wa-gateway di Node 22 dan 24.

## Masalah umum

| Masalah | Solusi |
|---|---|
| `Access denied for user 'root'` | `DB_PASSWORD` di `.env` salah |
| `Unknown database 'satset'` | Database belum dibuat (lihat langkah 3 instalasi) |
| `No connection could be made` | MySQL belum berjalan. Start service MySQL atau XAMPP |
| Perubahan `.env` tidak berpengaruh | Jalankan `php artisan config:clear` |
| Tampilan terlihat lama setelah update | Tekan **Ctrl + F5** di browser |
| HP tidak bisa membuka halaman saat scan QR | Pastikan Wi-Fi sama dan server dijalankan dengan `php artisan satset:lan`. Izinkan PHP di Windows Firewall (Private). Beberapa Wi-Fi publik atau kampus memblokir koneksi antar perangkat; coba hotspot HP |
| QR masih berisi `localhost` | Halaman admin dibuka lewat `localhost`. Buka lewat alamat jaringan dari `php artisan satset:lan` |
| Tombol "Lanjut via WhatsApp" tidak muncul | WhatsApp belum aktif, konfigurasinya belum lengkap, atau gateway belum **Tersambung** (cek **Admin → Hubungkan WhatsApp**) |
| Pesan WhatsApp berstatus "Menunggu kirim" | `php artisan queue:work` belum dijalankan, atau wa-gateway belum tersambung |
| Admin → Hubungkan WhatsApp: "Gateway tidak berjalan" | Jalankan `npm start` di folder `wa-gateway`; cek `WHATSAPP_GATEWAY_URL` sesuai port gateway |
| Admin → Hubungkan WhatsApp: "Token gateway ditolak" | Samakan `WHATSAPP_GATEWAY_TOKEN` dengan `WA_GATEWAY_TOKEN`, lalu `php artisan config:clear` |
| Gateway mencatat "Laravel menolak signature webhook" | Samakan `WHATSAPP_WEBHOOK_SECRET` dengan `WA_WEBHOOK_SECRET`, lalu `php artisan config:clear` |
| `npm start` berhenti dengan "Konfigurasi wa-gateway tidak valid" | Salin `wa-gateway/.env.example` ke `wa-gateway/.env` dan isi token serta secret (minimal 16 karakter, berbeda) |
| Dashboard: "Pemantauan WhatsApp tidak berjalan" | Jalankan `php artisan schedule:work` di terminal terpisah |
| Email peringatan tidak sampai | `MAIL_MAILER` masih `log` (email ada di `storage/logs/laravel.log`), atau pengaturan SMTP salah |
| Peringatan "event dari wa-gateway disisihkan" | Periksa file di `wa-gateway/data/webhook-queue/failed/`, perbaiki penyebabnya, lalu `npm run webhooks:replay` dan jalankan ulang gateway |
| Log WhatsApp: gagal `not_opted_in` | Nomor itu belum pernah chat ke bot. Sesuai desain, Baileys hanya mengirim ke nomor yang chat lebih dulu |
| Log WhatsApp: **Tidak dikirim** (`stale_notification`) | Normal. Notifikasi itu sudah tidak berlaku (tiket sudah dipanggil, selesai, atau batal) saat gateway tersambung lagi |
| Log WhatsApp: tanda **Macet** | `queue:work` berhenti saat mengirim. Cek di HP outlet; kalau customer belum menerima, klik **Kirim ulang** |
| Jam di admin atau log meleset 7 jam | Aplikasi memakai WIB (`APP_TIMEZONE=Asia/Jakarta`). Data lama yang dibuat saat zona waktu masih UTC tampil 7 jam lebih awal |
| Webhook Twilio mendapat 403 (provider lama) | `APP_URL` tidak sama dengan alamat tunnel, atau `config:clear` belum dijalankan |

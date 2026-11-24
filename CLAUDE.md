# SatSet — Antrean Virtual F&B

Customer scan QR di outlet, mengambil nomor antrean, lalu memantau giliran dari HP (dan WhatsApp, bila aktif). Panduan menjalankan ada di `README.md`; panduan demo di `docs/PANDUAN-PRESENTASI.md`; checklist uji di `docs/PANDUAN-UJI-COBA.md`.

## Alur customer

Ada dua mode pendaftaran. Keduanya berawal dari halaman outlet `/r/{slug}` (isi QR).

1. **WhatsApp chatbot** (default, butuh `WHATSAPP_ENABLED=true` dan wa-gateway berstatus `open`): tombol "Lanjut via WhatsApp" membuka `wa.me/<nomor>?text=ANTRE <slug>` → bot menanyakan jumlah tamu → `YA` untuk konfirmasi → tiket dibuat. State percakapan di `whatsapp_conversations` (`restaurant_selected` → `waiting_party_size` → `waiting_confirmation` → `queue_created`, kedaluwarsa 30 menit).
2. **Daftar lewat web** (`SATSET_WEB_SIGNUP=true`): form jumlah tamu di halaman outlet → `POST /r/{slug}/queue` → langsung ke halaman tiket. Satu tiket aktif per browser per outlet (disimpan di session), throttle 6/menit.

Halaman tiket `/queue/{public_token}` di-poll setiap 10 detik; halaman outlet setiap 15 detik.

## Stack dan file penting

- Laravel 13, PHP 8.3+, MySQL (dev), SQLite in-memory (test). Blade + CSS + JS biasa di `public/` tanpa build step. `composer.json` mengunci `config.platform.php` = `8.3.0` supaya `composer.lock` tetap bisa dipasang di PHP 8.3; jangan dihapus.
- `app/Services/QueueService.php`: nomor antrean, posisi, estimasi, call/complete/skip/cancel. Semua perubahan di dalam transaksi dengan `lockForUpdate` pada restoran.
- `app/Services/WhatsAppChatbotService.php`: state machine chatbot (tidak tahu provider apa yang dipakai).
- `app/Services/WhatsAppService.php`: outbox `whatsapp_logs`, status gateway (cache), dan pengiriman Baileys (default), Meta, Twilio, atau GREEN-API.
- `app/Services/QueueWhatsAppNotificationService.php`: notifikasi tinggal 3, tinggal 1, dipanggil, dibatalkan.
- `app/Services/WhatsAppMonitor.php` + `app/Notifications/WhatsAppAlert.php`: pemantauan kanal WhatsApp (lihat bagian Pemantauan).
- `app/Http/Controllers/BaileysWebhookController.php` (Baileys, `POST /webhooks/baileys`), `WhatsAppWebhookController.php` (Meta), `TwilioWebhookController.php` (Twilio), dan `GreenApiWebhookController.php` (GREEN-API, tidak resmi: nomor WA biasa yang ditautkan lewat QR; satu endpoint untuk pesan masuk dan status).
- `app/Http/Controllers/Admin/AdminWhatsAppConnectController.php`: halaman pemilik **Hubungkan WhatsApp** (`/admin/whatsapp/connect`): status gateway, QR (dirender `endroid/qr-code`, polling 5 detik lewat `public/js/admin.js`), nomor tersambung, tombol putuskan.
- `wa-gateway/`: proses Node terpisah (Node 20.12+, ESM, `node:http`, `@whiskeysockets/baileys` 7.0.0-rc14, `package.json` sendiri). Memegang sesi WhatsApp di `wa-gateway/auth_info/`, hanya mendengarkan di `127.0.0.1`. Fungsi murni di `src/util.js`; test `cd wa-gateway && npm test`.
- `routes/web.php`: route admin dibagi tiga grup: semua admin, per outlet (`can:manage-restaurant`), dan khusus pemilik (`can:owner`).
- `routes/console.php`: `satset:admin` (buat akun pemilik), `satset:lan` (server untuk diakses HP di Wi-Fi yang sama), `whatsapp:send-pending` dan `whatsapp:monitor` (keduanya terjadwal setiap menit; butuh `php artisan schedule:work`).
- `.github/workflows/tests.yml`: CI untuk `php artisan test` (PHP 8.3 dan 8.4) dan `npm test` di `wa-gateway` (Node 22 dan 24).

## wa-gateway (Baileys)

Laravel tetap pusat logika; gateway hanya transport. Kontrak HTTP (semua dengan `Authorization: Bearer WA_GATEWAY_TOKEN`, dibandingkan `timingSafeEqual`):

- `GET /health` → `{state: connecting|qr|open|closed|logged_out, phone, sent_today, webhook_backlog, webhook_failed}`; `GET /qr` → `{qr}` (string mentah, hanya saat `qr`).
- `POST /send {to, text, client_ref: "log:<id>"}` → `200 {message_id}` · `429 daily_cap` · `503 not_connected|busy|lookup_failed` (belum terkirim, aman diulang) · `400 invalid` · `422 not_on_whatsapp` · `409 send_uncertain` (Baileys error setelah mungkin menulis pesan; jangan diulang otomatis). `client_ref` yang sudah terkirim mengembalikan `message_id` yang sama, tidak mengirim ulang. Gateway selalu menjawab dalam ±25 detik.
- `POST /logout` → keluarkan perangkat, hapus `auth_info/`, state `logged_out`, tanpa reconnect otomatis.
- Webhook ke Laravel (`type`: `message` | `status` | `session`), dikirim berurutan satu per satu. Setiap event **disimpan ke disk dulu** (`wa-gateway/data/webhook-queue/pending/`, satu file per event) dan baru dihapus setelah Laravel menjawab 2xx, jadi Laravel atau gateway yang mati tidak menghilangkan pesan customer (pengiriman at-least-once; Laravel men-dedupe ID pesan). Backoff 1 detik sampai 30 detik; setelah 3 kali percobaan ulang gagal, dicatat di log lalu terus dicoba selama Laravel tidak tersedia (0/403/408/429/503), maksimal 24 jam. 4xx lain langsung, 5xx lain setelah 10 kali, dan pesan yang lewat 24 jam dipindah ke `failed/` (`npm run webhooks:replay` mengembalikannya). Event `session` yang menunggu digantikan yang terbaru; `session`/`status` yang lewat 24 jam dibuang.

Laravel menyimpan state gateway di cache `whatsapp:baileys:session` selama 30 detik (diperbarui webhook `session`; kalau kosong, `GET /health` dengan timeout 1 detik). Halaman outlet tidak pernah memanggil gateway di setiap request, dan tidak memanggilnya sama sekali bila `WHATSAPP_ENABLED=false`.

## Pemantauan

`whatsapp:monitor` (setiap menit) memakai `WhatsAppMonitor::problems()` dan mengirim email `WhatsAppAlert` ke semua pemilik aktif saat masalah muncul, pengingat paling sering sekali per jam (event `failed/`: sekali sehari), dan satu email saat pulih. Masalah: sesi `logged_out`/token salah (langsung), gateway tidak `open` lebih dari 3 menit, pesan `pending` lebih dari 10 menit, pesan `sending` lebih dari 2 menit (`stuck`, worker mati saat mengirim), 5+ pesan `failed` dalam 60 menit (kecuali `not_opted_in`), `webhook_backlog` ≥ 10, `webhook_failed` > 0. Dashboard pemilik menampilkan masalah yang sama dan peringatan bila monitor tidak berjalan lebih dari 5 menit (heartbeat di cache). Tidak aktif bila `WHATSAPP_ENABLED=false`. Isi peringatan tidak boleh memuat isi pesan, nomor HP, atau kredensial.

## Peran admin

- `users.role`: `owner` (Pemilik SatSet) atau `outlet_admin` (kasir). Pivot `restaurant_user`: satu akun bisa memegang beberapa outlet, dan satu outlet bisa punya beberapa akun.
- Gate `owner` dan `manage-restaurant` ada di `AppServiceProvider`. Daftar admin selalu difilter dengan `User::accessibleRestaurants()`.
- Admin outlet boleh mengubah pengaturan outlet, **kecuali** nama, prefix, dan status aktif (dicek juga di server).
- `users.active=false` → tidak bisa login dan langsung di-logout (middleware `admin.active`). Pemilik tidak bisa menurunkan peran atau menonaktifkan akunnya sendiri.

## Keputusan desain (jangan dilanggar)

- Membuka halaman atau mengikuti link WhatsApp **tidak pernah** membuat tiket. Tiket hanya dibuat oleh konfirmasi chatbot atau submit form web.
- `party_size` adalah jumlah tamu dalam satu kelompok. Satu kelompok mendapat satu nomor antrean.
- Slug outlet tidak berubah walau nama berganti, supaya QR yang sudah dicetak tetap berlaku. Prefix tidak bisa diubah setelah ada riwayat antrean.
- Satu nomor WhatsApp hanya punya satu tiket aktif per outlet.
- Webhook memverifikasi signature dari raw request sebelum membaca isi (Baileys: `X-Satset-Signature` = hex HMAC-SHA256 dari `timestamp.body` dengan `WHATSAPP_WEBHOOK_SECRET`, `X-Satset-Timestamp` maksimal selisih 300 detik; Meta: `X-Hub-Signature-256`; Twilio: `X-Twilio-Signature` dengan URL dari `APP_URL`; GREEN-API: header `Authorization` berisi `GREENAPI_WEBHOOK_TOKEN`, plus `idInstance` harus cocok). Webhook provider yang tidak aktif menjawab 503.
- ID pesan masuk disimpan unik (`whatsapp_incoming_messages`), sehingga pesan yang dikirim ulang provider diproses sekali.
- Pesan keluar ditulis ke outbox dulu (dengan `event_key` unik), baru dikirim lewat job `DeliverWhatsAppMessage`. Notifikasi yang sama tidak pernah terkirim dua kali. Error yang dicatat sudah disanitasi; kredensial tidak pernah masuk log.
- Meta/Twilio: di luar jendela 24 jam sejak pesan terakhir customer, kirim template (`WHATSAPP_TEMPLATE_*`: nama template Meta atau Content SID Twilio); kalau tidak ada template, catat `template_required`. GREEN-API dan Baileys tidak punya jendela 24 jam (`WhatsAppService::hasSessionWindow()`), jadi selalu teks biasa. Token GREEN-API ada di URL request, jadi URL itu tidak boleh di-log.
- **Baileys tidak resmi dan melanggar ketentuan WhatsApp**, jadi:
  - `WHATSAPP_ENABLED` default `false`.
  - **Opt-in**: hanya kirim ke nomor yang pernah mengirim pesan ke bot (`whatsapp_conversations.last_message_at` tidak null); selain itu `failed` dengan `not_opted_in`.
  - **Pacing**: gateway mengirim satu per satu (antrean FIFO, satu pengirim) dengan jeda acak `WA_MIN_DELAY_MS`–`WA_MAX_DELAY_MS` (bawaan 3–8 detik, maksimal 15 detik).
  - **Batas harian**: `WA_DAILY_CAP` (bawaan 200) dihitung per hari WIB, disimpan di `auth_info/` supaya tidak ter-reset saat gateway dijalankan ulang.
  - Tidak ada endpoint atau fitur pesan massal/promosi. Jangan menambahkannya.
- Sebelum mengirim, `WhatsAppService::deliver()` membuang notifikasi yang sudah tidak berlaku (tinggal 3/1 untuk tiket yang tidak lagi `waiting`, dipanggil untuk tiket yang tidak lagi `called`): status `skipped` dengan `stale_notification`, penanda `*_notified_at` tidak diisi.
- `whatsapp:send-pending` memakai `foreach`, bukan `Collection::each()`, karena `deliver()` mengembalikan `false` untuk pesan yang tidak terkirim dan `each()` berhenti di situ.
- Tombol **Kirim ulang** (`POST /admin/whatsapp-logs/{log}/retry`, khusus pemilik) hanya untuk pesan keluar `failed` (kecuali `not_opted_in`) atau `sending` lebih dari 2 menit (`WhatsappLog::canRetry()`); mengembalikan ke `pending` dengan `attempts` 0. Tidak pernah otomatis.
- Gateway Baileys tidak `open` (mati, menunggu QR, reconnect): pesan tetap `pending` tanpa menambah `attempts`, dan dikirim ulang saat webhook `session: open` masuk (`dispatchPending()`). 429/503/5xx/koneksi ditolak/401 (token salah): tetap `pending` dengan `gateway_unavailable`, maksimal 5 percobaan lalu `failed`; 503 `not_connected` dan 401 juga menandai gateway tidak siap di cache supaya pesan berikutnya tidak menghabiskan percobaan. Timeout setelah request sampai ke gateway: `failed` dengan `ambiguous_timeout`, tidak pernah dikirim ulang otomatis (review manual).
- Pesan dari JID `@lid` memakai `key.remoteJidAlt`; kalau tidak ada, pesan dibuang dan hanya ID-nya yang dicatat. Gateway hanya memproses `messages.upsert` bertipe `notify` (bukan riwayat), mengabaikan pesan sendiri, grup, status, dan non-teks.
- Notifikasi posisi punya penanda sendiri untuk tiap angka: tinggal 3 (tepat 3 di depan), tinggal 2 (tepat 2), dan tinggal 1 / "Anda berikutnya" (≤ 1). Penanda `*_notified_at` dan `event_key` membuat tiap notifikasi terkirim sekali. Bila antrean melompat melewati beberapa angka sekaligus, hanya yang terdekat yang dikirim (4 → 2 kirim "tinggal 2"; 4 → 1 kirim "tinggal 1"). Tiket yang mendaftar dengan antrean di depan ≤ 2 langsung diberi penanda `two_ahead`, dan ≤ 3 untuk `three_ahead`, karena konfirmasi pendaftaran sudah menyebut jumlahnya (`QueueService::createTicket`). Posisi dihitung di memori dari satu query.
- Link yang dikirim lewat WhatsApp dibangun dari `APP_URL`, bukan dari host request.
- QR memakai `APP_URL`, kecuali `APP_URL` masih localhost dan admin membuka lewat alamat jaringan (supaya bisa di-scan HP di Wi-Fi yang sama).
- Hanya proxy di loopback (`127.0.0.1`, `::1`) yang dipercaya, untuk tunnel `cloudflared`.
- Endpoint polling memanggil `session()->reflash()` supaya tidak menghabiskan flash message.

## Konfigurasi `.env`

```
APP_URL=                     # alamat publik (tunnel/domain); dipakai untuk QR dan link WhatsApp
APP_TIMEZONE=Asia/Jakarta    # WIB; jam di UI, log admin, dan batas harian
SATSET_WEB_SIGNUP=false      # true = daftar lewat form web
WHATSAPP_ENABLED=false
WHATSAPP_PROVIDER=baileys    # baileys (default) | meta | twilio | greenapi
WHATSAPP_GATEWAY_URL=http://127.0.0.1:3100
WHATSAPP_GATEWAY_TOKEN=      # = WA_GATEWAY_TOKEN di wa-gateway/.env
WHATSAPP_WEBHOOK_SECRET=     # = WA_WEBHOOK_SECRET di wa-gateway/.env
WHATSAPP_GATEWAY_TIMEOUT=30  # detik, untuk POST /send
WHATSAPP_PHONE_NUMBER=       # Meta/Twilio/GREEN-API: nomor publik (Twilio/GREEN-API: juga pengirim); Baileys memakai nomor yang tersambung di gateway
TWILIO_ACCOUNT_SID=
TWILIO_AUTH_TOKEN=
GREENAPI_API_URL=            # https://NNNN.api.greenapi.com
GREENAPI_ID_INSTANCE=
GREENAPI_API_TOKEN=          # apiTokenInstance
GREENAPI_WEBHOOK_TOKEN=      # rahasia buatan sendiri = webhookUrlToken di console
WHATSAPP_ACCESS_TOKEN=       # Meta
WHATSAPP_PHONE_NUMBER_ID=    # Meta
WHATSAPP_BUSINESS_ACCOUNT_ID=
WHATSAPP_APP_SECRET=
WHATSAPP_VERIFY_TOKEN=
WHATSAPP_API_VERSION=        # format vXX.X
WHATSAPP_TEMPLATE_THREE_AHEAD= / _ONE_AHEAD= / _CALLED= / _CANCELLED=
```

`wa-gateway/.env` (tidak membaca `.env` Laravel): `WA_GATEWAY_PORT` (3100), `WA_GATEWAY_TOKEN`, `WA_WEBHOOK_URL` (`http://127.0.0.1:8000/webhooks/baileys`), `WA_WEBHOOK_SECRET`, `WA_MIN_DELAY_MS` (3000), `WA_MAX_DELAY_MS` (8000), `WA_DAILY_CAP` (200). Token dan secret wajib sama dengan `WHATSAPP_GATEWAY_TOKEN` / `WHATSAPP_WEBHOOK_SECRET`, minimal 16 karakter, dan berbeda satu sama lain.

Jangan pernah commit `.env`, `wa-gateway/.env`, `wa-gateway/auth_info/` (sesi WhatsApp), `wa-gateway/data/` (antrean webhook berisi isi pesan), atau nilai asli kredensial. Saat aplikasi bisa diakses publik, `APP_DEBUG` harus `false`.

## Konvensi

- Zona waktu aplikasi `APP_TIMEZONE` (bawaan `Asia/Jakarta`); semua jam di UI dan batas harian memakai WIB.
- Pesan bot ke customer: Bahasa Indonesia formal (Anda), format WhatsApp (`*tebal*` untuk nomor antrean, nama outlet, dan instruksi; `_miring_` untuk catatan), maksimal satu emoji di judul, kalimat pendek, satu instruksi jelas per pesan. Chatbot menerima variasi input (`ANTRI`, huruf besar, tanda baca, `2 orang`, `iya`/`ok`/`siap`, `batal`/`tidak`/`ubah`); daftar ada di `WhatsAppChatbotService::YES`/`NO`. Customer yang dilewati (`skipCurrent`) mendapat notifikasi `skipped`.
- Semua teks UI dalam Bahasa Indonesia. Label status tiket diambil dari `QueueTicket::STATUS_LABELS`.
- Warna dan ukuran didefinisikan sebagai token di `:root` pada `public/css/satset.css` (customer) dan `public/css/admin.css` (admin). Warna brand `#2e6d6d`.
- Setelah mengubah CSS/JS, naikkan query versi (`?v=ui-N`) di layout supaya cache browser diperbarui.
- Test: `php artisan test` dan `cd wa-gateway && npm test`. HTTP ke gateway/Meta/Twilio/GREEN-API selalu di-fake (`Http::preventStrayRequests()`). Pengaturan WhatsApp untuk test dikunci di `phpunit.xml` (provider `meta` untuk test lama; test Baileys dan GREEN-API menyetel `whatsapp.provider` sendiri), jadi tidak terpengaruh `.env` lokal.
- Nilai contoh yang formatnya mirip kredensial asli (misalnya Account SID Twilio) dipecah, contohnya `'AC'.'000…'`, supaya tidak memicu secret scanning GitHub.

## Belum dikerjakan (backlog)

Diurutkan kira-kira dari yang paling penting.

1. ~~**Notifikasi bisa terlewat saat posisi melompat.**~~ **Selesai**: ambang `<=` dengan penanda `*_notified_at` (lihat Keputusan desain).
2. **Threshold notifikasi di-hardcode** (3 dan 1). Jadikan kolom per restoran, misalnya `notify_threshold`.
3. **Nomor antrean tidak pernah di-reset.** Sequence dihitung per restoran sepanjang masa; seharusnya per `(restaurant_id, service_date)`.
4. **Tombol reset antrean harian** di halaman admin.
5. **Pembatalan lewat chatbot**: `BATAL` dengan konfirmasi setelah tiket dibuat. Saat ini pesan apa pun setelah tiket dibuat dibalas status antrean terbaru (juga lewat dari 30 menit), dan pembatalan lewat link tiket.
6. **Tiket web → WhatsApp**: menghubungkan tiket dari form web ke WhatsApp lewat kode acak sekali pakai (alfabet tanpa 0/O/1/I), supaya customer web juga bisa menerima notifikasi.
7. **Privasi (UU PDP)**: anonimkan nomor HP dan isi pesan N hari setelah tiket selesai (scheduled command), dan tampilkan pemberitahuan singkat di halaman pendaftaran.
8. ~~**Efisiensi notifikasi**~~ **Selesai**: `notifyWaitingPositions` mengambil tiket aktif dengan satu query dan menghitung posisi di memori.
9. **Laporan per outlet** untuk pemilik: tamu per hari, rata-rata tunggu, tingkat dilewati, jam tersibuk.
10. **Hosting permanen** (rencana: versi Firebase). Saat ini demo berjalan dari laptop lewat Cloudflare Tunnel. wa-gateway juga berjalan di laptop (sesi di `auth_info/`, `useMultiFileAuthState` yang menurut dokumentasi Baileys bukan untuk produksi).
11. **Pilih satu provider untuk produksi** (Baileys, GREEN-API, Twilio, atau Meta) dan hapus yang tidak dipakai beserta test, route, dan variabel `.env`-nya. Catatan commit GREEN-API: produksi saat itu memakai Twilio.

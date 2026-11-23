<x-layouts.admin title="Hubungkan WhatsApp | SatSet Admin">
    <header class="admin-page-header">
        <div>
            <h1>Hubungkan WhatsApp</h1>
            <p>Nomor WhatsApp yang dipakai chatbot SatSet, lewat wa-gateway (Baileys).</p>
        </div>
    </header>

    @if($provider !== 'baileys')
        <div class="admin-notice admin-notice-warning">Provider WhatsApp yang aktif adalah <strong>{{ $provider }}</strong>. Halaman ini hanya dipakai jika <code>WHATSAPP_PROVIDER=baileys</code>.</div>
    @elseif(! $enabled)
        <div class="admin-notice admin-notice-warning"><code>WHATSAPP_ENABLED</code> masih <code>false</code>, jadi chatbot belum menerima atau mengirim pesan. Nomor tetap bisa disambungkan lebih dulu.</div>
    @endif

    <div class="admin-wa-layout">
        <section class="admin-panel admin-wa-status" aria-live="polite"
                 data-wa-connect data-state="{{ $state }}" data-status-url="{{ route('admin.whatsapp.connect.status') }}">
            @include('admin.whatsapp.partials.connect-status')
        </section>

        <aside class="admin-panel admin-wa-risk" aria-label="Catatan keamanan WhatsApp">
            <h2>Sebelum menyambungkan</h2>
            <ul>
                <li><strong>Baileys bukan API resmi WhatsApp</strong> dan melanggar ketentuan layanan WhatsApp. Nomor yang dipakai bisa dibatasi atau diblokir sewaktu-waktu.</li>
                <li>Pakai nomor khusus outlet, bukan nomor pribadi atau nomor bisnis utama.</li>
                <li>SatSet hanya membalas nomor yang lebih dulu chat ke bot, memberi jeda acak antar pesan (bawaan 3 sampai 8 detik), dan membatasi jumlah pesan per hari (bawaan 200).</li>
                <li>Jangan pakai nomor ini untuk pesan massal atau promosi.</li>
            </ul>
        </aside>
    </div>
</x-layouts.admin>

@php
    $labels = [
        'open' => ['Tersambung', 'green'],
        'qr' => ['Menunggu scan QR', 'amber'],
        'connecting' => ['Menyambungkan', 'amber'],
        'closed' => ['Terputus, mencoba lagi', 'amber'],
        'logged_out' => ['Sesi diputus', 'red'],
        'unreachable' => ['Gateway tidak berjalan', 'red'],
        'unauthorized' => ['Token gateway ditolak', 'red'],
        'not_configured' => ['Belum dikonfigurasi', 'grey'],
    ];
    [$label, $color] = $labels[$state] ?? [$state, 'grey'];
@endphp
<div class="admin-panel-heading">
    <div>
        <h2>Status gateway</h2>
        <p>{{ $state === 'open' ? 'Chatbot siap menerima pesan.' : 'Halaman ini diperbarui otomatis setiap 5 detik.' }}</p>
    </div>
    <span class="admin-badge admin-badge-{{ $color }}" data-wa-state-label>{{ $label }}</span>
</div>

@switch($state)
    @case('qr')
        @if($qrImage)
            <img class="admin-wa-qr" src="{{ $qrImage }}" width="280" height="280" alt="QR untuk menautkan WhatsApp ke SatSet">
        @else
            <p class="admin-hint">QR sedang dibuat. Tunggu beberapa detik.</p>
        @endif
        <ol class="admin-wa-steps">
            <li>Buka WhatsApp di HP yang memakai nomor outlet.</li>
            <li>Ketuk <strong>Menu</strong> (Android) atau <strong>Setelan</strong> (iPhone), lalu <strong>Perangkat tertaut</strong>.</li>
            <li>Ketuk <strong>Tautkan perangkat</strong>, lalu arahkan kamera ke QR ini. QR berganti sendiri setiap sekitar 20 detik.</li>
        </ol>
        @break

    @case('connecting')
    @case('closed')
        <p>wa-gateway sedang menyambung ke WhatsApp. Tunggu beberapa detik.</p>
        @break

    @case('logged_out')
        <p>Nomor sudah diputus dan sesi di wa-gateway sudah dihapus. Untuk menyambungkan lagi, hentikan wa-gateway (<kbd>Ctrl</kbd> + <kbd>C</kbd>), jalankan <code>npm start</code> di folder <code>wa-gateway</code>, lalu scan QR baru di halaman ini.</p>
        @break

    @case('unreachable')
        <p>wa-gateway belum berjalan atau alamatnya salah. Jalankan <code>npm start</code> di folder <code>wa-gateway</code>, dan pastikan <code>WHATSAPP_GATEWAY_URL</code> sesuai port gateway.</p>
        @break

    @case('unauthorized')
        <p>wa-gateway menolak token Laravel. Samakan <code>WHATSAPP_GATEWAY_TOKEN</code> di <code>.env</code> Laravel dengan <code>WA_GATEWAY_TOKEN</code> di <code>wa-gateway/.env</code>, lalu jalankan <code>php artisan config:clear</code>.</p>
        @break

    @case('not_configured')
        <p>Isi <code>WHATSAPP_GATEWAY_TOKEN</code> dan <code>WHATSAPP_WEBHOOK_SECRET</code> di <code>.env</code>, lalu jalankan <code>php artisan config:clear</code>. Langkah lengkapnya ada di README bagian "Menghubungkan WhatsApp".</p>
        @break
@endswitch

@if($phone && in_array($state, ['open', 'connecting', 'closed'], true))
    <dl class="admin-wa-facts">
        <div>
            <dt>Nomor tersambung</dt>
            <dd class="admin-mono">+{{ $phone }}</dd>
        </div>
        <div>
            <dt>Pesan terkirim hari ini</dt>
            <dd class="admin-mono">{{ $sentToday ?? '–' }}</dd>
        </div>
    </dl>
    <form method="post" action="{{ route('admin.whatsapp.logout') }}" onsubmit="return confirm('Putuskan nomor +{{ $phone }} dari SatSet? Chatbot berhenti sampai nomor disambungkan lagi.')">
        @csrf
        <button class="admin-button admin-button-ghost" type="submit">Putuskan nomor</button>
    </form>
@endif

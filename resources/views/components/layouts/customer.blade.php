@props(['title' => 'SatSet'])
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="description" content="Ambil nomor antrean di outlet tanpa berdiri. Tanpa login dan tanpa install aplikasi.">
    <meta name="theme-color" content="#ffffff">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }}</title>
    <link rel="icon" href="{{ asset('images/logo-128.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('icons/icon-192.png') }}">
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <link rel="preload" href="{{ asset('fonts/inter-latin-wght.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/satset.css') }}?v=ui-10">
    {{ $head ?? '' }}
    <script src="{{ asset('js/customer.js') }}?v=ui-10" defer></script>
</head>
<body>
    <a class="skip-link" href="#main">Lewati navigasi</a>
    <header class="topbar">
        <div class="topbar-inner">
            <a class="brand" href="{{ route('home') }}" aria-label="SatSet beranda"><img class="brand-mark" src="{{ asset('images/logo-128.png') }}" alt="" width="32" height="32">SatSet</a>
            <span class="topbar-caption">Antre tanpa berdiri</span>
        </div>
    </header>
    <main id="main">
        @if(session('status'))<div class="flash" role="status">{{ session('status') }}</div>@endif
        {{ $slot }}
    </main>
    <footer class="footer">
        <span><strong>SatSet</strong> &middot; Virtual queue untuk F&amp;B</span>
        <span>Waktu Anda lebih berharga.</span>
    </footer>
</body>
</html>

<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#12302f">
    <link rel="icon" href="{{ asset('images/logo-128.png') }}">
    <title>{{ $title ?? 'Masuk | SatSet Admin' }}</title>
    <link rel="preload" href="{{ asset('fonts/inter-latin-wght.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v=ui-9">
</head>
<body class="admin-login-body">
    <main class="admin-login-main">
        {{ $slot }}
    </main>
</body>
</html>

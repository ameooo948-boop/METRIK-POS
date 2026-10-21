<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#020617">
    <meta name="description" content="Metrik POS — إدارة السوبر ماركت والمخزون ونقطة البيع">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('icons/icon-192.png') }}">
    <title>Metrik POS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-inertia::head />
</head>
<body class="font-sans antialiased">
    <x-inertia::app />
</body>
</html>

<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'AI Notary')</title>

    @vite(['resources/css/theme.css', 'resources/js/app.js'])
</head>

<body>
    <div class="auth-shell">
        @yield('content')
    </div>
</body>

</html>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'AI Notary')</title>
    {{-- Set theme before paint to prevent flash --}}
    <script>
        (function() {
            try {
                var saved = localStorage.getItem('app-theme');
                var theme = saved || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                document.documentElement.setAttribute('data-theme', theme);
            } catch (e) {}
        })();
    </script>
    @vite(['resources/css/theme.css', 'resources/js/app.js'])
</head>

<body>
    @php
    $user = auth()->user();
    $balance = $balance ?? app(\App\Services\TokenService::class)->balance($user);
    $active = $navActive ?? '';
    $initials = collect(explode(' ', $user->name ?? $user->email))
    ->map(fn($p) => mb_substr($p, 0, 1))->take(2)->implode('');
    @endphp

    <div class="app-shell">
        <div class="sidebar-backdrop" id="sidebar-backdrop"></div>

        <aside class="app-sidebar" id="app-sidebar">
            <div class="app-sidebar__brand">
                <span class="app-sidebar__brand-mark"><i class="fa-solid fa-stamp"></i></span>
                <span>AI Notary</span>
            </div>

            <nav class="app-sidebar__nav">
                <div class="app-sidebar__section">Workspace</div>
                <a href="{{ route('dashboard') }}" class="app-nav-link {{ $active === 'dashboard' ? 'is-active' : '' }}">
                    <i class="fa-solid fa-gauge"></i> Dashboard
                </a>
                <a href="{{ route('upload') }}" class="app-nav-link {{ $active === 'upload' ? 'is-active' : '' }}">
                    <i class="fa-solid fa-arrow-up-from-bracket"></i> Procesar documento
                </a>
                <a href="#" class="app-nav-link {{ $active === 'documents' ? 'is-active' : '' }}">
                    <i class="fa-solid fa-folder-open"></i> Documentos
                </a>

                <div class="app-sidebar__section">Account</div>
                <a href="{{ route('billing.index') }}" class="app-nav-link {{ $active === 'billing' ? 'is-active' : '' }}">
                    <i class="fa-solid fa-coins"></i> Tokens y Pagos
                </a>
            </nav>

            <div class="app-sidebar__footer">
                <div class="d-flex align-items-center gap-2 px-2 py-1">
                    <div class="app-avatar">
                        @if($user->avatar_url)<img src="{{ $user->avatar_url }}" alt="">@else{{ strtoupper($initials) }}@endif
                    </div>
                    <div class="flex-grow-1" style="min-width:0">
                        <div class="small fw-medium text-truncate">{{ $user->name }}</div>
                        <div class="text-truncate" style="font-size:0.7rem;color:var(--text-subtle)">{{ $user->email }}</div>
                    </div>
                </div>
            </div>
        </aside>

        <div class="app-main">
            <header class="app-topbar">
                <div class="d-flex align-items-center gap-2">
                    <button class="icon-btn sidebar-toggle" id="sidebar-toggle" aria-label="Menu">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <span class="app-topbar__title">@yield('page_title', 'Dashboard')</span>
                </div>

                <div class="app-topbar__actions">
                    <a href="{{ route('billing.index') }}" class="token-pill" title="Token balance">
                        <i class="fa-solid fa-coins"></i> {{ $balance }}
                    </a>
                    <button class="icon-btn" data-theme-toggle aria-label="Toggle theme" aria-pressed="false">
                        <i class="fa-solid fa-circle-half-stroke"></i>
                    </button>
                    <button class="icon-btn" onclick="App.logout()" aria-label="Sign out">
                        <i class="fa-solid fa-arrow-right-from-bracket"></i>
                    </button>
                </div>
            </header>

            <main class="app-content">
                <div class="app-content__inner">
                    @yield('content')
                </div>
            </main>
        </div>
    </div>
    @stack('scripts')
</body>

</html>
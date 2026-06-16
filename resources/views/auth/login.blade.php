@extends('layouts.auth')
@section('title', 'Sign in')

@section('content')
<div class="auth-card">
    <div class="auth-brand">
        <span class="app-sidebar__brand-mark"><i class="fa-solid fa-stamp"></i></span>
        <span>AI Notary</span>
    </div>

    <h1 class="h5 fw-semibold mb-1 text-center">Bienvenido de vuelta</h1>
    <p class="text-center small mb-4" style="color: var(--text-muted)">Inicia sesión en tu espacio de trabajo</p>

    <form id="login-form">
        <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" required autocomplete="email" autofocus>
        </div>
        <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-primary w-100 btn-lg mb-3">Iniciar sesión</button>
    </form>

    <div class="d-flex align-items-center gap-2 mb-3">
        <hr class="flex-grow-1" style="border-color: var(--border)">
        <span class="small" style="color: var(--text-subtle)">or</span>
        <hr class="flex-grow-1" style="border-color: var(--border)">
    </div>

    <button id="google-login" class="btn btn-outline-secondary w-100 btn-lg">
        <i class="fa-brands fa-google me-2"></i> Continuar con Google
    </button>
</div>
@endsection
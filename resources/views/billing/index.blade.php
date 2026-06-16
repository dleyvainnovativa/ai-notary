@extends('layouts.app')
@section('title', 'Tokens & Pagos')
@section('page_title', 'Tokens & Pagos')
@php($navActive = 'billing')

@section('content')
<div class="page-header">
    <h1 class="page-header__title">Tokens y Pagos</h1>
    <p class="page-header__subtitle">Cada token procesa un documento. Compre un paquete a continuación.</p>
</div>

{{-- Current balance --}}
<div class="stat-card mb-4" style="max-width: 320px;">
    <div class="stat-card__icon"><i class="fa-solid fa-coins"></i></div>
    <div class="stat-card__value" style="color: var(--primary)">{{ $balance }}</div>
    <div class="stat-card__label">Tokens available</div>
</div>

{{-- Packages --}}
<div class="row g-3">
    @foreach ($packages as $key => $pkg)
    <div class="col-md-4">
        <div class="billing-card {{ $key === 'pro' ? 'billing-card--featured' : '' }}">
            @if ($key === 'pro')
            <span class="billing-card__badge">Más popular</span>
            @endif

            <div class="billing-card__tokens text-mono">{{ $pkg['tokens'] }}</div>
            <div class="billing-card__label">{{ $pkg['label'] }}</div>

            <div class="billing-card__price">
                <span class="billing-card__amount">${{ number_format($pkg['price'] / 100, 2) }}</span>
                <span class="billing-card__per">
                    ${{ number_format(($pkg['price'] / 100) / $pkg['tokens'], 2) }} / documento
                </span>
            </div>

            <button class="btn {{ $key === 'pro' ? 'btn-primary' : 'btn-outline-secondary' }} w-100 mt-auto"
                data-buy="{{ $key }}">
                <i class="fa-solid fa-arrow-right me-1"></i> Comprar {{ $pkg['tokens'] }} tokens
            </button>
        </div>
    </div>
    @endforeach
</div>

<p class="small mt-4" style="color: var(--text-subtle)">
    <i class="fa-solid fa-lock me-1"></i>
    Los pagos se procesan de forma segura a través de Stripe. Los tokens se añaden automáticamente a tu cuenta una vez completado el pago.
</p>
@endsection
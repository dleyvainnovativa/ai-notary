@extends('layouts.app')
@section('title', $ok ? 'Payment complete' : 'Payment cancelled')
@section('page_title', 'Tokens & billing')
@php($navActive = 'billing')

@section('content')
<div class="d-flex justify-content-center align-items-center" style="min-height: 60vh;">
    <div class="result-card">
        @if ($ok)
        <div class="result-badge result-badge--ok">
            <i class="fa-solid fa-check"></i>
        </div>
        <h1 class="result-title">Pago completado</h1>
        <p class="result-text">
            Tus tokens se están añadiendo a tu cuenta y aparecerán en unos segundos.
        </p>
        <div class="result-actions">
            <a href="{{ route('upload') }}" class="btn btn-primary">
                <i class="fa-solid fa-arrow-up-from-bracket me-1"></i> Process a document
            </a>
            <a href="{{ route('billing.index') }}" class="btn btn-outline-secondary">
                Regresar a tokens
            </a>
        </div>
        @else
        <div class="result-badge result-badge--neutral">
            <i class="fa-solid fa-xmark"></i>
        </div>
        <h1 class="result-title">Pago cancelado</h1>
        <p class="result-text">
            No se ha realizado ningún cargo a tu cuenta. Puedes intentarlo de nuevo cuando estés listo.
        </p>
        <div class="result-actions">
            <a href="{{ route('billing.index') }}" class="btn btn-primary">
                <i class="fa-solid fa-arrow-left me-1"></i> Regresar a tokens
            </a>
        </div>
        @endif
    </div>
</div>
@endsection
@extends('layouts.app')
@section('title', 'Dashboard')
@section('page_title', 'Dashboard')
@php($navActive = 'dashboard')

@section('content')
<div class="page-header">
    <h1 class="page-header__title">Bienvenido, {{ auth()->user()->name }}</h1>
    <p class="page-header__subtitle">Aquí tienes una descripción general de tu espacio de trabajo.</p>
</div>

<div class="row g-3">
    <div class="col-md-4">
        <div class="stat-card">
            <div class="stat-card__icon"><i class="fa-solid fa-coins"></i></div>
            <div class="stat-card__value" style="color: var(--primary)">{{ $balance ?? 0 }}</div>
            <div class="stat-card__label">Tokens disponibles</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="stat-card__icon"><i class="fa-solid fa-file-circle-check"></i></div>
            <div class="stat-card__value">{{ $processedCount ?? 0 }}</div>
            <div class="stat-card__label">Documentos procesados</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="stat-card__icon"><i class="fa-solid fa-cubes"></i></div>
            <div class="stat-card__value">{{ $moduleCount ?? 0 }}</div>
            <div class="stat-card__label">Módulos activos</div>
        </div>
    </div>
</div>

<div class="mt-4">
    <a href="{{ route('upload') }}" class="btn btn-primary">
        <i class="fa-solid fa-arrow-up-from-bracket me-1"></i> Procesar nuevo documento
    </a>
</div>
@endsection
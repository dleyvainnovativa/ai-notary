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

@php
    $statusLabels = [
        'uploaded' => ['En fila', 'neutral'],
        'extracting' => ['Extrayendo texto', 'neutral'],
        'processing' => ['Analizando con IA', 'neutral'],
        'requires_review' => ['Por revisar', 'warn'],
        'completed' => ['Exportado', 'ok'],
        'failed' => ['Fallido', 'fail'],
    ];
@endphp

<div class="doc-list mt-4">
    <div class="doc-list__head">
        <h2 class="doc-list__title">Documentos recientes</h2>
        <span class="doc-list__hint">Los datos se eliminan 24 h después de exportar.</span>
    </div>

    @if ($recent->isEmpty())
        <p class="doc-list__empty">Aún no has procesado documentos.</p>
    @else
        <div class="table-responsive">
            <table class="table doc-list__table mb-0">
                <thead>
                    <tr>
                        <th>Documento</th>
                        <th>Módulo</th>
                        <th>Estado</th>
                        <th>Actualizado</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recent as $doc)
                        @php([$label, $tone] = $statusLabels[$doc['status']] ?? [$doc['status'], 'neutral'])
                        <tr>
                            <td class="doc-list__file" title="{{ $doc['filename'] }}">{{ $doc['filename'] }}</td>
                            <td>{{ $doc['module'] }}</td>
                            <td>
                                <span class="doc-status doc-status--{{ $tone }}" @if ($doc['status'] === 'failed' && $doc['error']) title="{{ $doc['error'] }}" @endif>{{ $label }}</span>
                                @if ($doc['has_draft'] && $doc['status'] === 'requires_review')
                                    <span class="doc-list__draft">borrador guardado</span>
                                @endif
                            </td>
                            <td class="doc-list__date">{{ $doc['updated_at']?->format('d/m/Y H:i') }}</td>
                            <td class="text-end">
                                @if ($doc['can_open'])
                                    <a href="{{ route('upload', ['document' => $doc['id']]) }}" class="btn btn-sm {{ $doc['status'] === 'requires_review' ? 'btn-primary' : 'btn-outline-secondary' }}">
                                        {{ $doc['status'] === 'requires_review' ? 'Continuar revisión' : 'Abrir' }}
                                    </a>
                                @elseif ($doc['in_progress'])
                                    <a href="{{ route('upload', ['document' => $doc['id']]) }}" class="btn btn-sm btn-outline-secondary">Ver progreso</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
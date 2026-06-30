@extends('layouts.app')
@section('title', 'Procesar documento')
@section('page_title', 'Procesar documento')
@php($navActive = 'upload')

@section('content')
<div class="page-header">
    <h1 class="page-header__title">Procesar un documento</h1>
    <p class="page-header__subtitle">Sube tus archivos y deja que la IA extraiga los datos.</p>
</div>

{{-- Wizard steps indicator --}}
<div class="wizard-steps mb-4">
    <div class="wizard-step is-active" data-step-indicator="1">
        <span class="wizard-step__num">1</span>
        <span class="wizard-step__label">Subir</span>
    </div>
    <div class="wizard-step__line"></div>
    <div class="wizard-step" data-step-indicator="2">
        <span class="wizard-step__num">2</span>
        <span class="wizard-step__label">Procesando</span>
    </div>
    <div class="wizard-step__line"></div>
    <div class="wizard-step" data-step-indicator="3">
        <span class="wizard-step__num">3</span>
        <span class="wizard-step__label">Revisión</span>
    </div>
</div>

<div class="wizard-card">
    {{-- ============ STEP 1: UPLOAD ============ --}}
    <div class="wizard-pane is-active" data-pane="1">
        <form id="upload-form">
            <div class="mb-4" style="max-width: 360px;">
                <label class="form-label">Tipo de documento</label>
                <select name="module" id="module-select" class="form-select" required>
                    @foreach ($modules as $slug => $module)
                    <option value="{{ $slug }}">{{ $module['name'] }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Per-module dropzones --}}
            @foreach ($inputsByModule as $slug => $inputs)
            <div class="module-inputs" data-module="{{ $slug }}" style="display:none;">
                @foreach ($inputs as $input)
                <div class="dropzone-field mb-3">
                    <div class="dropzone-field__head">
                        <span class="dropzone-field__label">
                            {{ $input->label }}
                            @if ($input->required)
                            <span class="dropzone-field__req">Required</span>
                            @else
                            <span class="dropzone-field__opt">Optional</span>
                            @endif
                        </span>
                    </div>
                    @if ($input->description)
                    <p class="dropzone-field__desc">{{ $input->description }}</p>
                    @endif

                    <label class="dropzone"
                        data-dropzone
                        data-input-key="{{ $input->key }}"
                        data-module="{{ $slug }}"
                        data-required="{{ $input->required ? '1' : '0' }}">
                        <input type="file" class="dropzone__input module-file"
                            data-input-key="{{ $input->key }}"
                            data-module="{{ $slug }}"
                            accept=".pdf,.docx" hidden>
                        <div class="dropzone__empty">
                            <i class="fa-solid fa-cloud-arrow-up dropzone__icon"></i>
                            <span class="dropzone__text">
                                <strong>Click para subir</strong> o arrastrar y soltar
                            </span>
                            <span class="dropzone__hint">PDF o Word · max 20 MB</span>
                        </div>
                        <div class="dropzone__file" hidden>
                            <i class="fa-solid fa-file-lines dropzone__file-icon"></i>
                            <span class="dropzone__file-name"></span>
                            <button type="button" class="dropzone__remove" aria-label="Remove file">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>
                    </label>
                </div>
                @endforeach
            </div>
            @endforeach

            <div class="d-flex align-items-center justify-content-between mt-4">
                <span class="small" style="color: var(--text-subtle)">
                    <i class="fa-solid fa-shield-halved me-1"></i>
                    Los archivos son procesados y luego eliminados permanentemente.
                </span>
                <button type="submit" class="btn btn-primary" id="upload-submit"
                    {{ $balance < 1 ? 'disabled' : '' }}>
                    {{ $balance < 1 ? 'No quedan tokens' : 'Procesar documento' }}
                    <i class="fa-solid fa-arrow-right ms-1"></i>
                </button>
            </div>
        </form>
    </div>

    {{-- ============ STEP 2: PROCESSING ============ --}}
    <div class="wizard-pane" data-pane="2">
        <div class="processing-state" data-state="working">
            <div class="processing-spinner"></div>
            <h2 class="h5 fw-semibold mb-1" id="processing-title">Procesando tu documento…</h2>
            <p class="mb-0" style="color: var(--text-muted)" id="processing-text">Esto generalmente toma menos de un minuto.</p>
        </div>

        <div class="processing-state" data-state="done" hidden>
            <div class="processing-icon processing-icon--ok"><i class="fa-solid fa-check"></i></div>
            <h2 class="h5 fw-semibold mb-1">Documento procesado</h2>
            <p class="mb-3" style="color: var(--text-muted)">Los datos fueron extraídos y están listos para su revisión.</p>
            <button class="btn btn-primary" id="goto-review">
                Revisar datos extraídos <i class="fa-solid fa-arrow-right ms-1"></i>
            </button>
        </div>

        <div class="processing-state" data-state="failed" hidden>
            <div class="processing-icon processing-icon--fail"><i class="fa-solid fa-xmark"></i></div>
            <h2 class="h5 fw-semibold mb-1">Procesamiento fallido</h2>
            <p class="mb-3" style="color: var(--text-muted)" id="failed-reason">Algo salió mal.</p>
            <button class="btn btn-outline-secondary" id="try-again">
                <i class="fa-solid fa-rotate-left me-1"></i> Intentar de nuevo
            </button>
        </div>
    </div>

    {{-- ============ STEP 3: REVIEW (Phase 5) ============ --}}
    <div class="wizard-pane" data-pane="3">
        <div id="review-form"></div>
        <div class="d-flex justify-content-end mt-3">
            <button class="btn btn-primary" id="review-save">
                <i class="fa-solid fa-check me-1"></i> Exportar TXT
            </button>
        </div>
    </div>
</div>
@endsection
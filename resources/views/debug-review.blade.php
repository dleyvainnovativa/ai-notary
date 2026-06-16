@extends('layouts.app')
@section('title', 'Debug review')
@section('page_title', 'Debug review form')

@section('content')
<div id="review-form"></div>
<div class="d-flex justify-content-end mt-3">
    <button class="btn btn-primary" id="review-save">
        <i class="fa-solid fa-check me-1"></i> Validar (debug)
    </button>
</div>
@endsection
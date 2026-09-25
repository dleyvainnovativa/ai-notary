<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\DocumentController;

Route::get('/login', fn() => view('auth.login'))->name('login')->middleware('guest');
Route::post('/auth/session', [SessionController::class, 'store'])->middleware('guest');
Route::post('/auth/logout', [SessionController::class, 'destroy'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', [\App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard');


    Route::get('/documents/{document}/review-data', [DocumentController::class, 'reviewData'])->name('document.review-data');
    Route::post('/documents/{document}/review-validate', [DocumentController::class, 'reviewValidate'])->name('document.review-validate');
    Route::post('/documents/{document}/draft', [DocumentController::class, 'saveDraft'])->name('document.draft');
    Route::match(['get', 'post'], '/documents/{document}/pdf', [DocumentController::class, 'pdf'])->name('document.pdf');
    Route::post('/documents/{document}/export', [DocumentController::class, 'export'])
        ->middleware('auth')->name('document.export');

    Route::get('/billing', fn() => view('billing.index', [
        'packages' => config('tokens.packages'),
        'balance' => app(\App\Services\TokenService::class)->balance(auth()->user()),
    ]))->name('billing.index');

    Route::post('/billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
    Route::get('/billing/success', [BillingController::class, 'success'])->name('billing.success');
    Route::get('/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');

    Route::get('/upload', function () {
        $registry = app(\App\Modules\ModuleRegistry::class);
        $modules = $registry->active();
        $inputsByModule = [];
        foreach (array_keys($modules) as $slug) {
            $inputsByModule[$slug] = $registry->controller($slug)->inputs();
        }
        return view('upload', [
            'balance' => app(\App\Services\TokenService::class)->balance(auth()->user()),
            'modules' => $modules,
            'inputsByModule' => $inputsByModule,
        ]);
    })->name('upload');

    Route::post('/upload', [UploadController::class, 'store'])->name('upload.store');
    Route::get('/documents/{document}/status', [UploadController::class, 'status'])->name('document.status');
    Route::get('/debug/review-data', [DocumentController::class, 'reviewDebug'])
        ->middleware('auth')->name('debug.review-data');
    Route::get('/debug-review', fn() => view('debug-review'))
        ->middleware('auth')->name('debug.review');

    // routes/web.php (inside auth group)
    Route::get('/api/postal-codes/{cp}', [\App\Http\Controllers\PostalCodeController::class, 'search'])
        ->name('postal-codes.search');
});
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle'])->name('stripe.webhook');

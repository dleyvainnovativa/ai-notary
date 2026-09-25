<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Swap this single line for FailoverExtractor later — nothing else changes.
        $this->app->bind(
            \App\Services\Ai\AiExtractor::class,
            \App\Services\Ai\OpenAiExtractor::class,
        );

        // Review PDF: dompdf temp/font cache inside storage/ (writable on shared hosting)
        $this->app->bind(\App\Services\Pdf\ReviewPdfRenderer::class, fn() => new \App\Services\Pdf\ReviewPdfRenderer(
            null,
            storage_path('app/dompdf'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

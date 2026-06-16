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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

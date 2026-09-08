<?php

namespace App\Providers;

use App\Contracts\SentimentClassifier;
use App\Services\FakeFlakyClassifier;
use App\Services\SentimentLabelResolver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SentimentClassifier::class, FakeFlakyClassifier::class);

        $this->app->singleton(SentimentLabelResolver::class, function ($app) {
            return new SentimentLabelResolver(
                classifier: $app->make(SentimentClassifier::class),
                maxAttempts: 3,
                retryDelayMs: $app->environment('testing') ? 0 : 200,
            );
        });
    }

    public function boot(): void
    {
        //
    }
}

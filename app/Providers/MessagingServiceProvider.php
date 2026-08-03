<?php

namespace App\Providers;

use App\Services\Messaging\Providers\MessageProvider;
use App\Services\Messaging\Providers\MessageProviderFactory;
use Illuminate\Support\ServiceProvider;

class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The default binding (no per-message override) resolves through the
        // same factory the worker uses, so the prod-only + pause gates and the
        // runtime/env provider precedence live in exactly one place.
        $this->app->bind(
            MessageProvider::class,
            fn ($app) => $app->make(MessageProviderFactory::class)->make(),
        );
    }
}

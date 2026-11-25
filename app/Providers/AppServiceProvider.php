<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\WebhookSignature;
use App\Services\Pricing;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PixelforgeClient::class, fn () => new PixelforgeClient(
            config('services.pixelforge.base_url'),
            config('services.pixelforge.api_key'),
        ));

        $this->app->singleton(WebhookSignature::class, fn () => new WebhookSignature(
            (string) config('services.pixelforge.webhook_secret'),
            (int) config('services.pixelforge.webhook_tolerance_seconds'),
        ));

        $this->app->singleton(Pricing::class, fn () => new Pricing(
            (int) config('services.pixelforge.markup_percent'),
        ));
    }

    public function boot(): void
    {
        Gate::define('admin', fn (User $user) => in_array(strtolower($user->email), config('app.admin_emails'), true));
    }
}

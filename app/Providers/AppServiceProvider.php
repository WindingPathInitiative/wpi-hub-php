<?php

namespace App\Providers;

use App\Support\PresetOfficeContext;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Per-request holder for the ?useOffice preset office.
        $this->app->scoped(PresetOfficeContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Node returned bare JSON arrays/objects; don't wrap Resources in "data".
        JsonResource::withoutWrapping();
    }
}

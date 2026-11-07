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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Blade views are precompiled to functions for the native build (aot/compile-views.php).
        $this->app["view.engine.resolver"]->register("blade", fn () => new \App\Aot\AotBladeEngine($this->app["blade.compiler"], $this->app["files"]));
    }
}

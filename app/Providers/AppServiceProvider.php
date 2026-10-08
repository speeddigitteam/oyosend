<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Lazy loading is an N+1 waiting to happen: fail on it everywhere but production.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Never persist a class name in a polymorphic column. Each module
        // registers the aliases of its models through its manifest.
        Relation::requireMorphMap();

        DB::prohibitDestructiveCommands($this->app->isProduction());

        Password::defaults(fn (): Password => $this->app->isProduction()
            ? Password::min(12)->mixedCase()->numbers()->uncompromised()
            : Password::min(8));
    }
}

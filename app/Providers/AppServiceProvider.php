<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Paginator::useBootstrapFive();
        foreach (array_keys(Permissions::DELEGABLE + Permissions::OWNER) as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}

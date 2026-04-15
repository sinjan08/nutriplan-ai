<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Auth;
use App\Auth\FirestoreUserProvider;
use App\Repositories\Firestore\UserRepository;

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
        Auth::provider('firestore', function ($app, array $config) {
            return new FirestoreUserProvider(
                $app->make(UserRepository::class)
            );
        });
    }
}

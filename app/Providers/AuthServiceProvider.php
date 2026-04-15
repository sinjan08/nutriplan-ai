<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Auth;
use App\Auth\FirestoreUserProvider;
use App\Repositories\Firestore\UserRepository;

class AuthServiceProvider extends ServiceProvider
{
  public function boot(): void
  {
    Auth::provider('firestore', function ($app, array $config) {
      return new FirestoreUserProvider(
        $app->make(UserRepository::class)
      );
    });
  }
}

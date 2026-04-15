<?php
// app/Providers/NotificationServiceProvider.php

namespace App\Providers;

use App\Repositories\Firestore\NotificationRepository;
use App\Services\Notification\NotificationService;
use App\Services\Notification\NotificationDecisionLayer;
use App\Services\Notification\SuppressionService;
use Illuminate\Support\ServiceProvider;

class NotificationServiceProvider extends ServiceProvider
{
  public function register(): void
  {
    $this->app->singleton(NotificationRepository::class, function ($app) {
      return new NotificationRepository($app->make(\App\Services\Firebase\FirestoreService::class));
    });

    $this->app->singleton(NotificationService::class, function ($app) {
      return new NotificationService(
        $app->make(\App\Services\FcmService::class),
        $app->make(NotificationRepository::class),
        $app->make(NotificationDecisionLayer::class),
        $app->make(SuppressionService::class)
      );
    });

    $this->app->singleton(NotificationDecisionLayer::class, function ($app) {
      return new NotificationDecisionLayer($app->make(NotificationRepository::class));
    });

    $this->app->singleton(SuppressionService::class, function ($app) {
      return new SuppressionService($app->make(NotificationRepository::class));
    });
  }

  public function boot(): void
  {
    //
  }
}

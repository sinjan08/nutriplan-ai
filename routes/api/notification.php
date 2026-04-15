<?php


use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\NotificationController;


Route::controller(NotificationController::class)->group(function () {

  Route::get('run', 'run');
  Route::post('/test', 'testNotification');
});

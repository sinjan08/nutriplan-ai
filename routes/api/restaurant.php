<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\RestaurantController;


Route::controller(RestaurantController::class)->group(function () {
  Route::get('/all', 'getNearestRestaurant');
  Route::get('/details', 'getSingle');
  /**
   * Menu routes
   */
  Route::prefix('menu')->group(function () {
    Route::get('/', 'menuSearch');
  });
});

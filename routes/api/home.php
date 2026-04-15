<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\HomeController;

Route::controller(HomeController::class)->group(function () {
  Route::get('/', 'index');
  Route::get('/journey', 'getMyJourney');
});

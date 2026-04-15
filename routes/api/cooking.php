<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CookingController;


Route::controller(CookingController::class)->group(function () {

  Route::get('/steps', 'getSteps');
  Route::put('/complete', 'completeCooking');
});

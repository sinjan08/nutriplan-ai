<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TrackerController;


Route::controller(TrackerController::class)->group(function () {

  Route::get('/', 'index');
});

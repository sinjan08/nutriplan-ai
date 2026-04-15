<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PreferenceController;


Route::controller(PreferenceController::class)->group(function () {
  Route::post('/save', 'savePrefernce');
  Route::get('/', 'getPreference');
});

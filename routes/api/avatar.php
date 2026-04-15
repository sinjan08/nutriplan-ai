<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AvatarController;


Route::controller(AvatarController::class)->group(function () {
  Route::get('/all', 'getAllAvatars');
  Route::post('/create', 'create');
});

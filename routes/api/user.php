<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController;


Route::controller(UserController::class)->prefix('profile')->group(function () {
  Route::get('/', 'getProfile');
  Route::post('/update', 'update');
  Route::post('/image', 'updateProfileImage');
  Route::put('/avatar', 'updateAvatar');
  Route::put('/notification/save', 'setNotification');
  Route::delete('/delete', 'deleteAccount');
  Route::delete('/image/remove', 'removeProfileImage');
});
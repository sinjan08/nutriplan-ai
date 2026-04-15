<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;


Route::controller(AuthController::class)->group(function () {

  Route::post('/register', 'register');
  Route::post('/verify', 'verifyOtp');
  Route::post('/login', 'login');
  Route::post('/forgot-password', 'forgot');
  Route::post('/change-password', 'reset');
  Route::post('/resend', 'resend');
  Route::post('/social-login', 'socialLogin');

  Route::middleware(['auth:api'])->post('/logout', 'logout');
});

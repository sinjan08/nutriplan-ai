<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CookBookController;


Route::controller(CookBookController::class)->group(function () {
  Route::get('/', 'getCookBookList');
  Route::post('/delsert', 'addorUpdate');
});

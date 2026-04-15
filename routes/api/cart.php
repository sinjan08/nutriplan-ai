<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\OrderController;


Route::controller(OrderController::class)->group(function () {
  Route::get('/', 'getCart');
  Route::post('/add', 'addIngredients');
});

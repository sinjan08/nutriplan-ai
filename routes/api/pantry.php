<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PantryController;


Route::controller(PantryController::class)->group(function () {

  Route::get('/', 'getPantry');
  Route::post('/add', 'create');
  Route::get('/meal/suggested', 'getSuggestedMeals');
  Route::get('/meal/recipe', 'getRecipeDetails');
});

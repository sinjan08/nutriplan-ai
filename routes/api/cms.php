<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CmsController;


Route::controller(CmsController::class)->group(function () {

  // Fetch CMS by key
  Route::get('/', 'fetch');

  // Create CMS
  Route::post('/', 'create');

  // Update CMS by key
  Route::put('{key}', 'update');

  // Hard delete CMS by key
  Route::delete('{key}', 'delete');
});
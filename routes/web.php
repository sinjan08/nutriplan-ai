<?php

use App\Http\Controllers\Api\Auth\AuthController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;


Route::get('/', function () {
    return view('welcome');
});

Route::get('/clear-all-cache', function () {
    Artisan::call('optimize:clear');

    return "All cache is cleared successfully";
});

Route::get('/test', [AuthController::class, 'index'])->name('test-route');

Route::get('/run-storage-link', function () {
    Artisan::call('storage:link');

    return 'Storage link created successfully!';
});

Route::get('/test-firestore', function () {
    return class_exists(\Google\Cloud\Firestore\FirestoreClient::class)
        ? 'Firestore SDK Found'
        : 'Firestore SDK Missing';
});


Route::get('/server-debug/{key}', function ($key) {

    if ($key !== 'MY_SECRET_123') {
        abort(403);
    }

    $output = [];

    // 1️⃣ Composer dump-autoload
    $composerOutput = shell_exec('composer dump-autoload 2>&1');
    $output['composer'] = $composerOutput;

    // 2️⃣ Clear caches
    Artisan::call('optimize:clear');
    $output['optimize_clear'] = Artisan::output();

    // 3️⃣ Route list
    Artisan::call('route:list');
    $output['routes'] = Artisan::output();

    return response()->json($output);
});
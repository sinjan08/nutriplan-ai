<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PantryController;

// auth routes group
Route::prefix('auth')->group(function () {
    require base_path('routes/api/auth.php');
});

// avatar routes group
Route::prefix('avatar')->group(function () {
    require base_path('routes/api/avatar.php');
});

// cms routes group
Route::prefix('cms')->group(function () {
    require base_path('routes/api/cms.php');
});

// this route is only using to upload image from javascript file
Route::controller(PantryController::class)->group(function () {
    Route::post('menu/image/upload', 'uploadMenuImage');
});

/* ---------------------------------
        PROTECTED ROUTES
---------------------------------- */
Route::middleware(['auth:api', 'user.activity.log'])->group(function () {
    // subscription routes group
    Route::prefix('subscription')->group(function () {
        require base_path('routes/api/subscription.php');
    });

    Route::middleware(['subscription.check'])->group(function () {
        // home routes
        Route::prefix('home')->group(function () {
            require base_path('routes/api/home.php');
        });

        // tracker routes 
        Route::prefix('tracker')->group(function () {
            require base_path('routes/api/tracker.php');
        });

        // pantry routes
        Route::prefix('pantry')->group(function () {
            require base_path('routes/api/pantry.php');
        });
    });

    // user preference routes
    Route::prefix('preference')->group(function () {
        require base_path('routes/api/prefrence.php');
    });

    // user routes
    Route::prefix('user')->group(function () {
        require base_path('routes/api/user.php');
    });

    // restaurant routes
    Route::prefix('restaurant')->group(function () {
        require base_path('routes/api/restaurant.php');
    });

    // cookbook routes
    Route::prefix('cookbook')->group(function () {
        require base_path('routes/api/cookbook.php');
    });

    // cart routes
    Route::prefix('cart')->group(function () {
        require base_path('routes/api/cart.php');
    });

    // order routes
    Route::prefix('order')->group(function () {
        require base_path('routes/api/order.php');
    });

    // cooking routes
    Route::prefix('cooking')->group(function () {
        require base_path('routes/api/cooking.php');
    });

    // notification routes group
    Route::prefix('notification')->group(function () {
        require base_path('routes/api/notification.php');
    });
});

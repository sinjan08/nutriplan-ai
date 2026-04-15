<?php

use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\UserActivityLog;
use App\Http\Middleware\SubscriptionMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'jwt.auth' => AuthMiddleware::class,
            'user.activity.log' => UserActivityLog::class,
            'subscription.check' => SubscriptionMiddleware::class,
        ]);

        // THIS IS THE FIX
        $middleware->redirectGuestsTo(function () {
            return null; // prevents redirect to login route
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Prevent redirect to login route
        $exceptions->render(function (AuthenticationException $e, $request) {

            return response()->json([
                'success' => false,
                'message' => 'Unathorized. Token missing or expired.',
                'data' => []
            ], Response::HTTP_UNAUTHORIZED);
        });

        // THIS IS THE IMPORTANT PART
        $exceptions->shouldRenderJsonWhen(function ($request, $e) {
            return true;
        });
    })->create();

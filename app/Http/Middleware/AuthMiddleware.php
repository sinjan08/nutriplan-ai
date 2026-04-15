<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Exception;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use App\Helpers\ApiResponse;

class AuthMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            return ApiResponse::error('Tokennnnn.', 401);
            if (!$token = JWTAuth::getToken()) {
                return ApiResponse::error('Token not provided.', 401);
            }

            $payload = JWTAuth::parseToken()->getPayload();

            $user = (object)[
                'id' => $payload['sub'],
            ];

            $request->setUserResolver(function () use ($user) {
                return $user;
            });
        } catch (TokenExpiredException $e) {
            return ApiResponse::error('Token expired.', 401);
        } catch (TokenInvalidException $e) {
            return ApiResponse::error('Token invalid.', 401);
        } catch (Exception $e) {
            return ApiResponse::error('Authorization failed.', 401);
        }

        return $next($request);
    }
}

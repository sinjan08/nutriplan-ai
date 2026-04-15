<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Exception;
use App\Services\AppLogger;
use App\Repositories\Firestore\UserRepository;
use App\Repositories\Firestore\SubscriptionRepository;
use App\Helpers\ApiResponse;
use Carbon\Carbon;

class SubscriptionMiddleware
{
  protected $userRepo;
  protected $subscriptionRepo;

  public function __construct(
    UserRepository $userRepo,
    SubscriptionRepository $subscriptionRepo
  ) {
    $this->userRepo = $userRepo;
    $this->subscriptionRepo = $subscriptionRepo;
  }

  /**
   * Handle an incoming request.
   *
   * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
   */
  /* public function handle(Request $request, Closure $next)
  {
    try {
      $user = $request->user();

      if (!$user) {
        return ApiResponse::error("Unauthorized: Please login", 401);
      }

      $userId = $user->getAuthIdentifier();

      // checking subscription attribute is in request or not
      $subscription = $request->attributes->get('subscription') ?? null;

      if (!$subscription) {
        // fetching user to check user has subscription or not
        $userData = $this->userRepo->find($userId);
        if (!$userData)  return ApiResponse::error("No user found", 400);
        
        if (!isset($userData['subscription'])) {
          return ApiResponse::error("User has not subscribed yet.", 401);
        }

        $subscription = $userData['subscription'];
        $request->attributes->set('subscription', $subscription);
      }

      $expiry = $subscription['expiry_date'] ?? null;
      $isActive = $subscription['is_active'] ?? false;
      // MAIN CHECK
      if (!$expiry || $expiry <= time()) {
        return ApiResponse::error("Subscription has expired.", 401);
      }

      if (!$isActive) {
        return ApiResponse::error("User has no active subscription.", 401);
      }

      //$request->attributes->set('hasSubscription', $subscription);

      return $next($request);
    } catch (Exception $e) {
      AppLogger::error($e->getMessage());
      return ApiResponse::error("Something went wrong", 500);
    }
  }*/

  public function handle(Request $request, Closure $next)
  {
    try {
      $user = $request->user();

      if (!$user) {
        return ApiResponse::error("Unauthorized: Please login", 400);
      }

      $userId = $user->getAuthIdentifier();

      $subscription = $request->attributes->get('subscription') ?? null;

      if (!$subscription) {
        $userData = $this->userRepo->find($userId);

        if (!$userData) {
          return ApiResponse::error("No user found", 400);
        }

        $subscription = $userData['subscription'] ?? null;
        $request->attributes->set('subscription', $subscription);
      }

      $expiry = $subscription['expiry_date'] ?? null;
      $isActive = $subscription['is_active'] ?? false;

      $hasSubscription = false;

      if ($expiry && $expiry > time() && $isActive) {
        $hasSubscription = true;
      }

      // Store flag in request
      $request->attributes->set('hasSubscription', $hasSubscription);

      // ❗ continue request first
      $response = $next($request);

      // ❗ Modify response AFTER controller
      $data = $response->getData(true);

      if (isset($data['data'])) {
        $data['data']['hasSubscription'] = $hasSubscription;
        $response->setData($data);
      }

      return $response;
    } catch (Exception $e) {
      AppLogger::error($e->getMessage());
      return ApiResponse::error("Something went wrong", 500);
    }
  }
}

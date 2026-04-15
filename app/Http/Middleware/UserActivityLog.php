<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Exception;
use App\Services\AppLogger;
use App\Repositories\Firestore\UserRepository;
use Carbon\Carbon;

class UserActivityLog
{
  protected $userRepo;
  protected $apiBaseUrl;

  public function __construct(
    UserRepository $userRepo
  ) {
    $this->userRepo = $userRepo;
    $this->apiBaseUrl = url('/api');
  }

  /**
   * Handle an incoming request.
   *
   * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
   */
  public function handle(Request $request, Closure $next)
  {
    try {
      // validating user
      $user = $request->user();
      if (!$user) {
        AppLogger::error("User not found");
        return $next($request);
      }
      $userId = $user->getAuthIdentifier();

      // fetching requested url
      $url = $request->url();
      // dd($this->apiBaseUrl);
      $lastRequest = str_replace($this->apiBaseUrl, '', $url);

      // updating user
      $updateData = [
        'lastRequest' => $lastRequest,
        'lastActiveOn' => Carbon::now(),
      ];

      $this->userRepo->update($userId, $updateData);

      return $next($request);
    } catch (Exception $e) {
      AppLogger::error($e->getMessage());
    }
  }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Repositories\Firestore\UserRepository;
use App\Repositories\Firestore\SubscriptionRepository;
use App\Services\AppLogger;
use Exception;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
  protected $userRepo;
  protected $appleService;
  protected $subscriptionRepo;

  public function __construct(
    UserRepository $userRepo,
    SubscriptionRepository $subscriptionRepo
  ) {
    $this->userRepo = $userRepo;
    $this->subscriptionRepo = $subscriptionRepo;
  }

  public function handleSubscription(Request $request)
  {
    try {
      $type = $request->type;
      // validating user
      $user = $request->user();
      if (!$user) {
        return ApiResponse::error('Unauthorized.', 401);
      }

      $userId = $user->getAuthIdentifier();

      if (!$type || !$userId) {
        return ApiResponse::error("Missing required fields");
      }

      // Basic validation
      if ($type === 'purchase') {

        $expiry = $request->expiryDate;

        if (!$expiry) {
          return ApiResponse::error("Invalid purchase payload");
        }

        $isActive = $expiry > time();

        $data = [
          'user_id' => $userId,
          'product_id' => $request->productId,
          'transaction_id' => $request->transactionId,
          'original_transaction_id' => $request->originalTransactionId,
          'expiry_date' => $expiry,
          'status' => $isActive ? 'active' : 'expired',
          'environment' => $request->environment,
        ];
      } elseif ($type === 'status') {

        $data = [
          'user_id' => $userId,
          'product_id' => $request->productId,
          'transaction_id' => $request->transactionId,
          'original_transaction_id' => $request->originalTransactionId,
          'expiry_date' => $request->expiryDate,
          'status' => $request->status === 'expired' ? 'cancelled' : 'active',
          'environment' => $request->environment,
        ];
      } else {
        return ApiResponse::error("Invalid type");
      }

      // Save
      $this->subscriptionRepo->create($data);

      // Update user
      $this->userRepo->update($userId, [
        'subscription' => [
          'is_active' => ($data['status'] === 'active'),
          'product_id' => $data['product_id'],
          'expiry_date' => $data['expiry_date'],
        ]
      ]);
      

      return ApiResponse::success("Subscription saved", $data);
    } catch (Exception $e) {
      return ApiResponse::error("Error: " . $e->getMessage(), 500);
    }
  }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\Firestore\PantryRepositary;
use App\Helpers\ApiResponse;
use App\Repositories\Firestore\OrderRepositary;
use App\Repositories\Firestore\CartRepositary;
use App\Repositories\Firestore\UserRepository;
use App\Services\AppLogger;
use App\Services\InstacartService;
use App\Services\OpenAIService;
use Exception;
use Illuminate\Http\Request;
use App\Http\Requests\AddToCartRequest;
use App\Helpers\FileUploadHelper;


class OrderController extends Controller
{
  protected $cartRepo;
  protected $openAI;
  protected $suggestedMealRepo;
  protected $cookBookRepo;
  protected $orderRepo;
  protected $pantryRepo;
  protected $userRepo;
  protected $instaCart;

  public function __construct(
    CartRepositary $cartRepo,
    OrderRepositary $orderRepo,
    InstacartService $instaCart,
    PantryRepositary $pantryRepo,
    UserRepository $userRepo,
  ) {
    $this->cartRepo = $cartRepo;
    $this->orderRepo = $orderRepo;
    $this->pantryRepo = $pantryRepo;
    $this->userRepo = $userRepo;
    $this->instaCart = $instaCart;
  }

  /**
   * To add ingredients into cart
   * @param AddToCartRequest $request
   * @route POST /cart/add
   */
  public function addIngredients(AddToCartRequest $request)
  {
    try {
      // validating request
      $data = $request->validated();
      // validating user
      $user = $request->user();
      if (!$user) {
        return ApiResponse::error('Unauthorized.', 401);
      }

      $userId = $user->getAuthIdentifier();
      $isOrder = filter_var($data['isOrder'], FILTER_VALIDATE_BOOLEAN);

      if ($isOrder) {
        // saving orders  
        $this->insertOrder($data, $userId);
        return ApiResponse::success("Item added in orders successfully");
      }

      // adding into cart
      $instaCartUrl = $this->insertCart($data, $userId);
      if (!$instaCartUrl) {
        return ApiResponse::error("Failed to create recipe in Instacart", 500);
      }

      return ApiResponse::success("Items added into cart successfully", ['instaCartUrl' => $instaCartUrl]);
    } catch (Exception $e) {
      $message = "Failed to add to cart " . $e->getMessage();
      AppLogger::error($message);

      return ApiResponse::error(
        $message,
        500
      );
    }
  }

  /**
   * Item insert function for cart
   * @param mixed $data
   * @param mixed $userId
   * @throws Exception
   */
  private function insertCart($data, $userId)
  {
    try {
      // fetching user details
      $userData = $this->userRepo->find($userId);
      $recipeTitle = $userData['name'] . "'s Shopping Busket" ?? 'Wisefork Recipe Busket';

      // creating insta cart
      $instaCartUrl = $this->instaCart->createRecipe($recipeTitle, $data['ingredients']);

      if (!$instaCartUrl) {
        throw new Exception("Failed to create recipe in Instacart");
      }

      // preparing cart data
      $cartData = array_map(function ($item) use ($userId) {
        return [
          ...$item,
          'user_id' => $userId,
        ];
      }, $data['ingredients']);

      $this->cartRepo->bulkCreate($cartData);

      return $instaCartUrl;
    } catch (Exception $e) {
      AppLogger::error($e->getMessage());
      throw new Exception($e->getMessage());
    }
  }

  /**
   * To save items in order
   * @param mixed $data
   * @param mixed $userId
   * @throws Exception
   */
  private function insertOrder($data, $userId)
  {
    try {
      // preparing cart data
      $orderData = array_map(function ($item) use ($userId) {
        return [
          ...$item,
          'user_id' => $userId,
        ];
      }, $data['ingredients']);

      $newOrder = $this->orderRepo->bulkCreate($orderData);
      // removing cart
      $this->cartRepo->deleteByUserId($userId);
      // adding into pantry
      $this->pantryRepo->bulkCreate($orderData);

      return $newOrder;
    } catch (Exception $e) {
      AppLogger::error($e->getMessage());
      throw new Exception($e->getMessage());
    }
  }

  /**
   * To fetch cart items by user
   * @param Request $request
   * @route GET /cart
   */
  public function getCart(Request $request)
  {
    try {
      // validating user
      $user = $request->user();
      if (!$user) {
        return ApiResponse::error('Unauthorized.', 401);
      }

      $userId = $user->getAuthIdentifier();
      $isOrder = filter_var($request->isOrder, FILTER_VALIDATE_BOOLEAN) ?? false;
      $items = null;
      $flag = 'Cart';
      if ($isOrder) {
        $items = $this->orderRepo->getOrdersByUser($userId);
        $flag = 'Order';
      } else {
        // fetching cart for this user
        $items = $this->cartRepo->getCartByUser($userId);
      }

      if (empty($items)) {
        return ApiResponse::success("No item found in {$flag}");
      }

      // formatting response
      $finalResponse = array_map(function ($item) {
        return collect($item)->except(['user_id', 'createdAt', 'updatedAt'])->toArray();
      }, $items);

      return ApiResponse::success("{$flag} items fetched", $finalResponse);
    } catch (Exception $e) {
      $message = $e->getMessage();
      AppLogger::error($message);

      return ApiResponse::error(
        $message,
        500
      );
    }
  }
}

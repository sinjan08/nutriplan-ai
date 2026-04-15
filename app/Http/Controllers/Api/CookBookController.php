<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\Firestore\CookBookRepositary;
use App\Helpers\ApiResponse;
use App\Repositories\Firestore\MenuItemsRepositary;
use App\Services\AppLogger;
use Exception;
use Illuminate\Http\Request;


class CookBookController extends Controller
{
  protected $cookBookRepo;
  protected $menuRepo;

  public function __construct(
    CookBookRepositary $cookBookRepo,
    MenuItemsRepositary $menuRepo
  ) {
    $this->cookBookRepo = $cookBookRepo;
    $this->menuRepo = $menuRepo;
  }

  public function addorUpdate(Request $request)
  {
    try {
      // validating request
      if (!$request->menuId) {
        AppLogger::error("Required parameter is missing");
        return ApiResponse::error("Required parameter 'menuId' is missing", 422);
      }

      // getting logged in user id from request
      $user = $request->user();
      if (!$user) {
        AppLogger::warning("Unauthorized profile update attempt.");
        return ApiResponse::error('Unauthorized.', 401);
      }
      $userId = $user->getAuthIdentifier();

      $menuId = $request->menuId;

      // inserting menu id in cook book if not exist
      // if exist then removing for this user id
      $result = $this->cookBookRepo->delsert($userId, $menuId);

      if (empty($result)) {
        AppLogger::error("Falied to insert or delete");
        return ApiResponse::error("Failed to delete or insert", 500);
      } else {
        if ($result['deleted'] == false) {
          AppLogger::debug("Recipe added");
          return ApiResponse::success("Recipe added in cook book");
        } else {
          AppLogger::debug("Recipe removed");
          return ApiResponse::success("Recipe removed in cook book");
        }
      }
    } catch (Exception $e) {
      $message = "An error occurred: " . $e->getMessage();
      AppLogger::error($message);

      return ApiResponse::error(
        $message,
        500
      );
    }
  }

  /**
   * To get paginated cook book list of logged in user
   * @param Request $request
   */
  public function getCookBookList(Request $request)
  {
    try {
      // validating user
      $user = $request->user();
      if (!$user) {
        return ApiResponse::error('Unauthorized.', 401);
      }

      $userId = $user->getAuthIdentifier();
      // pagination input
      $currentPage = max((int) $request->input('page', 1), 1);
      $perPage = max((int) $request->input('per_page', 10), 1);
      $search = strtolower(trim($request->search)) ?? null;
      // fetching cook book data for logged in user
      $finalResponse = $this->getCookBookByUserId($userId, $search);
      if (empty($finalResponse)) {
        return ApiResponse::success("No cook book found");
      }

      // making pagination
      $responsePayload = getPaginatedData($finalResponse, $currentPage, $perPage);

      return ApiResponse::success("Cook books are fetched", $responsePayload, 200);
    } catch (Exception $e) {
      $message = "An error occurred: " . $e->getMessage();
      AppLogger::error($message);

      return ApiResponse::error($message, 500);
    }
  }

  /**
   * Commin function to fetch menu added in cook book for the logged in user
   * @param mixed $userId
   */
  public function getCookBookByUserId($userId, $search = null)
  {
    try {
      // fecthing cook book list for this user
      $cookBook = $this->cookBookRepo->getCookBookByUser($userId);

      if (empty($cookBook)) {
        return [];
      }

      // Extract menu IDs
      $menuIds = [];
      foreach ($cookBook as $item) {
        if (!empty($item['menu_id'])) {
          $menuIds[] = $item['menu_id'];
        }
      }

      if (empty($menuIds)) {
        return [];
      }

      // Fetch menus
      $menus = $this->menuRepo->getMenuByMultipleIds($menuIds);

      // Index menus by id for order preservation
      $menuMap = [];
      foreach ($menus as $menu) {
        $menuMap[$menu['id']] = $menu;
      }

      $finalResponse = [];

      // Preserve cookbook order
      foreach ($menuIds as $id) {

        if (!isset($menuMap[$id])) {
          continue;
        }

        $menu = $menuMap[$id];

        // SEARCH FILTER
        if ($search) {
          $tokens = explode(' ', $search);
          $menuTokens = $menu['searchTokens'] ?? [];

          $matched = false;

          foreach ($tokens as $token) {
            if (in_array($token, $menuTokens)) {
              $matched = true;
              break;
            }
          }

          if (!$matched) {
            continue;
          }
        }

        $priceRaw = isset($menu['price']) ? (float) $menu['price'] : 0;
        $price = number_format($priceRaw / 100, 2, '.', '');

        $finalResponse[] = [
          "id" => $menu['id'],
          "restaurant_id" => $menu['restaurant_id'] ?? null,
          "title" => $menu['title'] ?? null,
          "restaurantName" => $menu['restaurantName'] ?? null,
          "price" => $price,
          "imageUrl" => $menu['imageUrl'] ?? getPlaceholderImage(),
          "calorie" => isset($menu['calorie']) ? (int) $menu['calorie'] : 250,
          "cookingTime" => $menu['cookingTime'] ?? "25-30 mins",
          "ingredientCount" => isset($menu['ingredientCount']) ? (int) $menu['ingredientCount'] : 15,
          "itemDescription" => $menu['itemDescription'] ?? null,
          "costComparision" => [
            "takeOut" => $price,
            "homeCookCost" => "5.90"
          ],
          "isCookBook" => true
        ];
      }

      return $finalResponse;
    } catch (Exception $e) {
      $message = "An error occurred: " . $e->getMessage();
      AppLogger::error($message);

      return ApiResponse::error($message, 500);
    }
  }
}

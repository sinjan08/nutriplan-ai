<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\PantryController;
use App\Repositories\Firestore\PantryRepositary;
use App\Helpers\ApiResponse;
use App\Repositories\Firestore\SuggestedMealRepositary;
use App\Repositories\Firestore\CookBookRepositary;
use App\Repositories\Firestore\CookingReposiatry;
use App\Repositories\Firestore\RestaurantRecipeRepositary;
use App\Repositories\Firestore\MenuItemsRepositary;
use App\Services\AppLogger;
use App\Services\InstacartService;
use Exception;
use Illuminate\Http\Request;
use App\Http\Requests\PantryInsertRequest;
use App\Helpers\FileUploadHelper;


class CookingController extends Controller
{
  protected $pantryRepo;
  protected $suggestedMealRepo;
  protected $cookBookRepo;
  protected $cookingRepo;
  protected $restaurantRecipeRepo;
  protected $pantryController;
  protected $menuItemRepo;
  protected $instaCartService;

  public function __construct(
    PantryRepositary $pantryRepo,
    SuggestedMealRepositary $suggestedMealRepo,
    CookBookRepositary $cookBookRepo,
    CookingReposiatry $cookingRepo,
    RestaurantRecipeRepositary $restaurantRecipeRepo,
    PantryController $pantryController,
    MenuItemsRepositary $menuItemRepo,
    InstacartService $instaCartService,
  ) {
    $this->pantryRepo = $pantryRepo;
    $this->suggestedMealRepo = $suggestedMealRepo;
    $this->cookBookRepo = $cookBookRepo;
    $this->cookingRepo = $cookingRepo;
    $this->restaurantRecipeRepo = $restaurantRecipeRepo;
    $this->pantryController = $pantryController;
    $this->menuItemRepo = $menuItemRepo;
    $this->instaCartService = $instaCartService;
  }


  /**
   * To get steps of a recipe
   * @param mixed $id
   * @param Request $request
   * @route GET /cooking/steps?id=
   */
  public function getSteps(Request $request)
  {
    try {
      $id = $request->id;
      if (!$id) {
        return ApiResponse::error('Required parameter is missing.', 422);
      }
      // validating user
      $user = $request->user();
      if (!$user) {
        return ApiResponse::error('Unauthorized.', 401);
      }
      $userId = $user->getAuthIdentifier();

      $finalResponse = $this->pantryController->getMenuDetails($id, $userId, true);

      if (empty($finalResponse)) {
        return ApiResponse::success("No recipe found");
      }

      return ApiResponse::success("Recipe details found", $finalResponse);
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
   * To mark a recipe as cooked
   * @param mixed $id
   * @param Request $request
   * @route PUT /cooking/complete
   */
  public function completeCooking(Request $request)
  {
    try {
      $id = $request->id;
      if (!$id) {
        return ApiResponse::error("Required parameter is missing", 422);
      }
      // validating user
      $user = $request->user();
      if (!$user) {
        return ApiResponse::error('Unauthorized.', 401);
      }

      $userId = $user->getAuthIdentifier();
      $isSuggestedMeal = false;
      // getting reciepe details
      $recipe = $this->menuItemRepo->findById($id);

      if (!$recipe || empty($recipe)) {
        // checkinh menu is in ai generated suggested meal
        $recipe = $this->suggestedMealRepo->findById($id);

        if (!$recipe || empty($recipe)) {
          return ApiResponse::success("No recipe found");
        }
        $isSuggestedMeal = true;
      }

      $restaurantPrice = $recipe['estimated_restaurant_price_usd'] ?? (float)($recipe['price'] / 100);

      $moneySaved = (float)($restaurantPrice - $recipe['estimated_home_cost_usd']);

      $finalResponse = [
        'estimated_restaurant_price_usd' => $restaurantPrice,
        'estimated_home_cost_usd' => $recipe['estimated_home_cost_usd'],
        'moneySaved' => $moneySaved,
        'calorie' => $recipe['nutrition']['calories_kcal'] ?? 0,
        'carbs' => $recipe['nutrition']['carbs_g'] ?? 0,
        'protein' => $recipe['nutrition']['protein_g'] ?? 0,
        'fat' => $recipe['nutrition']['fat_g'] ?? 0,
        'sugar' => $recipe['nutrition']['sugar_g'] ?? 0,
        'fiber' => $recipe['nutrition']['fiber_g'] ?? 0,
      ];

      // marking this recipe as cooked
      // $cooked = $this->cookingRepo->create([
      //   ...$finalResponse,
      //   'user_id' => $userId,
      //   'recipe_id' => $id,
      //   'isSuggestedMeal' => $isSuggestedMeal
      // ]);

      // if (!$cooked) {
      //   return ApiResponse::error("Failed to mark as cooked");
      // }

      // updating pantry after cooking
      $this->updatePantryAfterCooking($userId, $recipe['ingredients']);

      unset($finalResponse['estimated_restaurant_price_usd'], $finalResponse['estimated_home_cost_usd']);

      $finalResponse['moneySaved'] = number_format($moneySaved ?? 0, 2, '.', '');

      return ApiResponse::success("Cooking Complete", $finalResponse);
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
   * To update pantry after successfull coocking
   * @param mixed $userId
   * @param mixed $ingredients
   * @throws Exception
   * @return bool
   */
  public function updatePantryAfterCooking($userId, $ingredients)
  {
    try {
      $pantryItems = $this->pantryRepo->getPantryItemsByUser($userId);

      if (empty($pantryItems)) {
        throw new Exception("No pantry items found");
      }

      // 🔥 normalize ingredients using Instacart logic
      $normalizedIngredients = $this->instaCartService->normalizeIngredients($ingredients);

      // 🔥 create pantry map (name => item)
      $pantryMap = [];
      foreach ($pantryItems as $item) {
        $pantryMap[strtolower($item['name'])] = $item;
      }

      $updates = [];
      $deletes = [];

      foreach ($normalizedIngredients as $ingredient) {

        $name = strtolower($ingredient['name']);
        $usedQty = $ingredient['measurements'][0]['quantity'];
        $unit = $ingredient['measurements'][0]['unit'];

        if (!isset($pantryMap[$name])) {
          continue; // skip if not in pantry
        }

        $pantryItem = $pantryMap[$name];

        // 🔥 normalize pantry item also
        $normalizedPantry = $this->instaCartService->normalizeIngredients([[
          'name' => $pantryItem['name'],
          'qty' => (float)$pantryItem['qty'],
          'unit' => $pantryItem['unit']
        ]])[0];

        $pantryQty = $normalizedPantry['measurements'][0]['quantity'];

        // 🚨 IMPORTANT: ensure same unit type
        if ($unit !== $normalizedPantry['measurements'][0]['unit']) {
          continue; // skip incompatible (weight vs volume)
        }

        $remainingQty = $pantryQty - $usedQty;

        if ($remainingQty <= 0) {
          $deletes[] = $pantryItem['id'];
        } else {
          $updates[] = [
            'id' => $pantryItem['id'],
            'qty' => round($remainingQty, 2),
            'unit' => $unit, // base unit
            'updatedAt' => now()->toISOString(),
          ];
        }
      }

      // 🔥 batch delete
      if (!empty($deletes)) {
        foreach (array_chunk($deletes, 500) as $chunk) {
          $this->pantryRepo->batchDelete($chunk);
        }
      }

      // 🔥 batch update (manual loop since no batch update method)
      foreach ($updates as $item) {
        $this->pantryRepo->update($item['id'], [
          'qty' => $item['qty'],
          'unit' => $item['unit'],
          'updatedAt' => $item['updatedAt'],
        ]);
      }

      return true;
    } catch (Exception $e) {
      AppLogger::error($e->getMessage());
      throw new Exception($e->getMessage());
    }
  }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\Firestore\CookBookRepositary;
use App\Helpers\ApiResponse;
use App\Repositories\Firestore\MenuItemsRepositary;
use App\Repositories\Firestore\PreferenceRepository;
use App\Repositories\Firestore\UserRepository;
use App\Repositories\Firestore\CookingReposiatry;
use App\Services\AppLogger;
use Exception;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\CookBookController;
use App\Http\Controllers\Api\TrackerController;
use Carbon\Carbon;


class HomeController extends Controller
{
  protected $cookBookRepo;
  protected $menuRepo;
  protected $preferenceRepo;
  protected $userRepo;
  protected $cookBookController;
  protected $restaurantController;
  protected $pantryController;
  protected $trackerController;
  protected $cookingRepo;

  public function __construct(
    CookBookRepositary $cookBookRepo,
    MenuItemsRepositary $menuRepo,
    PreferenceRepository $preferenceRepo,
    UserRepository $userRepo,
    CookingReposiatry $cookingRepo,
    CookBookController $cookBookController,
    RestaurantController $restaurantController,
    PantryController $pantryController,
    TrackerController $trackerController,
  ) {
    $this->cookBookRepo = $cookBookRepo;
    $this->menuRepo = $menuRepo;
    $this->preferenceRepo = $preferenceRepo;
    $this->userRepo = $userRepo;
    $this->cookingRepo = $cookingRepo;
    $this->cookBookController = $cookBookController;
    $this->restaurantController = $restaurantController;
    $this->pantryController = $pantryController;
    $this->trackerController = $trackerController;
  }

  /**
   * Home data fetch
   * @param Request $request
   * @route GET /home?type=&lat=long=page=&per_page=&search=
   */
  public function index(Request $request)
  {
    try {
      // validating user
      $user = $request->user();
      if (!$user) {
        return ApiResponse::error('Unauthorized.', 401);
      }

      $userId = $user->getAuthIdentifier();
      // validating request input
      if (!$request->lat || !$request->long) {
        AppLogger::error("lat or long is missing");
        return ApiResponse::error("Required parameters is missing", 500);
      }

      // pagination input
      $currentPage = max((int) $request->input('page', 1), 1);
      $perPage = max((int) $request->input('per_page', 10), 1);
      $search = strtolower(trim($request->search)) ?? null;
      $lat = $request->lat;
      $long = $request->long;
      $type = $request->type;

      // response for home 
      $finalResponse = $this->getJourney($userId);

      // checking type is cookbook or not
      if (strtolower($type) == 'cookbook') {
        $finalResponse['cookbook'] = $this->getCookBook($userId, $search, $currentPage, $perPage);
        return ApiResponse::success("Cook book fetched", $finalResponse, 200);
      }

      // restaurant list
      $finalResponse['restaurantList'] = $this->restaurantController->getRestaurantsByCoordinates($lat, $long, $currentPage, $perPage, $search);

      // suggested meals
      $suggestedMeals = $this->getSuggestedMeal($userId);
      if (!empty($suggestedMeals)) $finalResponse['suggestedMeals'] = $suggestedMeals;

      // recent meal
      $recentMeal = $this->getRecentMeal($userId);
      if (!empty($recentMeal)) $finalResponse['recentMeal'] = $recentMeal;

      return ApiResponse::success("Home data fetched", $finalResponse, 200);
    } catch (Exception $e) {
      $message = "An error occurred: " . $e->getMessage();
      AppLogger::error($message);
      return ApiResponse::error($message, 500);
    }
  }

  /**
   * To fetch saving goal, savings calories goal, calories achieved
   * @param mixed $userId
   * @throws Exception
   * @return array{currentSavings: int|string, myJourney: array{calories: int, calories_goal: int, money: float|string, signup_date: mixed, totalSavingGoal: array|int|string}}
   */
  private function getJourney($userId)
  {
    try {
      // fetching preferences by user id
      $preference = $this->preferenceRepo->finPreferenceByUser($userId);
      $userData = $this->userRepo->find($userId);
      $userCreatedAt = $userData['createdAt'];
      // fetch all data from user creation till today
      $start = Carbon::parse($userCreatedAt)->startOfDay()->toDateString();
      $end   = Carbon::today()->endOfDay()->toDateString();
      $today = Carbon::today()->toDateString();
      // fetching cooked meal of this user according to the date range
      $cookedMeals = $this->cookingRepo->getCookedMealByDateRange($userId, $start, $end);
      $savingsSummary = $this->trackerController->getSavingsSummary($cookedMeals);
      $journeySummary = $this->trackerController->getSummaryByDate($cookedMeals, $today, $userCreatedAt);

      // final response for home 
      return [
        'totalSavingGoal' => isset($preference['targetSaving']) && $preference['targetSaving'] ? str_replace("$", "", $preference['targetSaving']) : "0",
        'currentSavings' => $savingsSummary['total_saving'] ?? "0",
        'myJourney' => [
          "signup_date" => $userCreatedAt,
          'money' => $journeySummary['money'] ?? "389.89",
          'calories' => (int)$journeySummary['calories'] ?? 0,
          'calories_goal' => (int)$preference['calories'] ?? 0,
        ],
      ];
    } catch (Exception $e) {
      AppLogger::error($e->getMessage());

      throw new Exception($e->getMessage());
    }
  }

  /**
   * to fetch cook book for this user
   * @param mixed $userId
   * @param mixed $search
   * @param mixed $currentPage
   * @param mixed $perPage
   * @throws Exception
   */
  private function getCookBook($userId, $search, $currentPage, $perPage)
  {
    try {
      $cookBook = $this->cookBookController->getCookBookByUserId($userId, $search);

      if (empty($cookBook)) {
        return ApiResponse::success("No cook book found");
      }
      // if cook book data then 
      $cookBookMenus = getPaginatedData($cookBook, $currentPage, $perPage);
      return $cookBookMenus;
    } catch (Exception $e) {
      AppLogger::error($e->getMessage());

      throw new Exception($e->getMessage());
    }
  }


  /**
   * To fetch suggested meal
   * @param mixed $userId
   * @throws Exception
   * @return array|array{price: int|string, restaurantName: null, restaurant_id: null}
   */
  private function getSuggestedMeal($userId)
  {
    try {
      $suggestedMealsResponse = [];
      // fetching suggested meal 
      $suggestedMeals = $this->pantryController->fetchSuggestedMeal($userId);
      if ($suggestedMeals && !empty($suggestedMeals)) {
        //$formattedSuggestedMeal=
        $paginatedSuggestedMeal = getPaginatedData($suggestedMeals, 1, 1, true);
        $formattedSuggestedMeal = $this->pantryController->formatData($paginatedSuggestedMeal['data'], $userId);

        // final response
        $suggestedMealsResponse = [
          ...$formattedSuggestedMeal[0],
          'price' => "0",
          'restaurant_id' => null,
          'restaurantName' => null
        ] ?? [];
      }

      return $suggestedMealsResponse;
    } catch (Exception $e) {
      AppLogger::error($e->getMessage());

      throw new Exception($e->getMessage());
    }
  }

  /**
   * To fetch recent last cooked meal
   * @param mixed $userId
   * @throws Exception
   * @return array|array{price: int|string, restaurantName: null, restaurant_id: null}
   */
  private function getRecentMeal($userId)
  {
    try {
      // fetching last cooked meal
      $lastCookedMeal = $this->cookingRepo->getLastCookedMeal($userId);

      $recentMeal = [];
      $menuDetails = [];
      $isCookBook = false;
      if (empty($lastCookedMeal)) {
        return $recentMeal;
      }

      $menuDetails = $this->menuRepo->findById($lastCookedMeal['recipe_id']);
      // checking is in cookbook or not
      $isCookBook = $this->cookBookRepo->isCookBookMenu($userId, $lastCookedMeal['recipe_id']);

      $price = (float)$menuDetails['price'] ?? 0;

      // defining recipe placeholder and checking if image is available or not, if available then getting public url; 
      $recipeImage = getPlaceholderImage(true);
      if (isset($menuDetails['image']) && $menuDetails['image']) {
        $recipeImage = publicStorageUrl($menuDetails['image']);
      }

      // recent meal
      $recentMeal = [
        'id' => $menuDetails['id'],
        'restaurant_id' => $menuDetails['restaurantUuid'] ?? null,
        'title' => $menuDetails['title'] ?? "",
        'restaurantName' => $menuDetails['restaurantName'] ?? "",
        'price' => number_format($price / 100, 2, '.', ''),
        'imageUrl' => $recipeImage,
        'calorie' => $menuDetails['nutrition']['calories_kcal'] ?? 0,
        'cookingTime' => $menuDetails['estimated_cook_time'] ?? '0 mins',
        'ingredientCount' => $menuDetails['ingredient_count'],
        'itemDescription' => $menuDetails['short_description'],
        "costComparision" => [
          "takeOut" => number_format($price / 100, 2, '.', ''),
          "homeCookCost" => number_format($menuDetails['estimated_home_cost_usd'] ?? 0, 2, '.', '') ?? 0,
        ],
        'calorie_vs_takeout' => $menuDetails['nutrition_gain']['calories_vs_takeout_percent'] ?? 0,
        'protein_content' => $menuDetails['nutrition_gain']['protein_vs_takeout_percent'] ?? 0,
        'sodium_levels' => $menuDetails['nutrition_gain']['sodium_vs_takeout_percent'] ?? 0,
        'ingredients' => $menuDetails['ingredients'] ?? [],
        'appliances' => $menuDetails['required_appliances'] ?? [],
        'isCookBook' => $isCookBook,
      ];

      return $recentMeal;
    } catch (Exception $e) {
      AppLogger::error($e->getMessage());

      throw new Exception($e->getMessage());
    }
  }

  /**
   * To fetch journey by date
   * @param Request $request
   * @route GET /home/journey?date=
   */
  public function getMyJourney(Request $request)
  {
    try {

      // Validate required parameter: date
      if (!$request->date) {
        $message = "Required parameter 'date' is missing";
        AppLogger::error($message);
        return ApiResponse::error($message);
      }

      // Parse input date once
      $requestedDate = Carbon::parse($request->date)->startOfDay();

      // Validate authenticated user
      $user = $request->user();
      if (!$user) {
        AppLogger::warning("Unauthorized access attempt in getMyJourney");
        return ApiResponse::error('Unauthorized.', 401);
      }

      // Get authenticated user ID
      $userId = $user->getAuthIdentifier();
      $userData = $this->userRepo->find($userId);

      $userCreatedAt = Carbon::parse($userData['createdAt'])->startOfDay();

      // Validate date against signup date
      if ($requestedDate->lt($userCreatedAt)) {
        return ApiResponse::error("Invalid date", 200);
      }

      // Date range
      $start = $userCreatedAt->toDateString();
      $end   = Carbon::today()->toDateString();

      // Fetch cooked meals
      $cookedMeals = $this->cookingRepo->getCookedMealByDateRange($userId, $start, $end);

      // Summary
      $journeySummary = $this->trackerController->getSummaryByDate(
        $cookedMeals,
        $requestedDate->toDateString(),
        $userCreatedAt
      );

      // Preferences
      $preference = $this->preferenceRepo->finPreferenceByUser($userId);

      // Response
      $response = [
        "money"          => $journeySummary['money'] ?? "389.89",
        "calories"       => isset($journeySummary['calories']) ? (int)$journeySummary['calories'] : 0,
        "calories_goal"  => $preference && isset($preference['calories']) ? (int)$preference['calories'] : 0,
        "signup_date"    => $userCreatedAt,
      ];

      return ApiResponse::success("My journey fetched successfully", [
        "myJourney" => $response
      ]);
    } catch (Exception $e) {

      $message = "An error occurred in getMyJourney: " . $e->getMessage();
      AppLogger::error($message);

      return ApiResponse::error($message, 500);
    }
  }
  /* public function getMyJourney(Request $request)
  {
    try {

      // Validate required parameter: date
      if (!$request->date) {
        $message = "Required parameter 'date' is missing";
        AppLogger::error($message);
        return ApiResponse::error($message);
      }

      // Parse input date safely
      //$date = Carbon::parse($request->date);
      $date = Carbon::parse($request->date)->startOfDay();
      // Validate authenticated user
      $user = $request->user();
      if (!$user) {
        AppLogger::warning("Unauthorized access attempt in getMyJourney");
        return ApiResponse::error('Unauthorized.', 401);
      }

      // Get authenticated user ID
      $userId = $user->getAuthIdentifier();
      $userData = $this->userRepo->find($userId);
      $userCreatedAt = $userData['createdAt'];
      $signupDate = Carbon::parse($userCreatedAt->startOfDay());

      if ($date->lt($signupDate)) {
        return ApiResponse::error("Invalid date", 200);
      }

      // fetch all data from user creation till today
      $start = Carbon::parse($userCreatedAt)->startOfDay()->toDateString();
      $end   = Carbon::today()->endOfDay()->toDateString();
      $requestedDate = Carbon::parse($request->date)->toDateString();
      // fetching cooked meal of this user according to the date range
      $cookedMeals = $this->cookingRepo->getCookedMealByDateRange($userId, $start, $end);
      $journeySummary = $this->trackerController->getSummaryByDate($cookedMeals, $requestedDate, $userCreatedAt);

      // fetching preferences by user id
      $preference = $this->preferenceRepo->finPreferenceByUser($userId);

      // Prepare response structure
      $response = [
        "money"    => $journeySummary['money'] ?? "389.89",
        "calories" => (int)$journeySummary['calories'] ?? 0,
        'calories_goal' => $preference ? (int)$preference['calories'] ?? 0 : 0,
        "signup_date" => $userCreatedAt,
      ];

      return ApiResponse::success("My journey fetched successfully", [
        "myJourney" => $response
      ]);
    } catch (Exception $e) {

      $message = "An error occurred in getMyJourney: " . $e->getMessage();
      AppLogger::error($message);

      return ApiResponse::error($message, 500);
    }
  }*/
}

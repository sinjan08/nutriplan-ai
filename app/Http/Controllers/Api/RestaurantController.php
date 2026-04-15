<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GetNearestRestaurantRequest;
use App\Repositories\Firestore\RestaurantRepositary;
use App\Repositories\Firestore\CookBookRepositary;
use App\Helpers\ApiResponse;
use App\Repositories\Firestore\MenuItemsRepositary;
use App\Services\AppLogger;
use Exception;
use Illuminate\Http\Request;


class RestaurantController extends Controller
{
  protected $restaurantRepo;
  protected $menuRepo;
  protected $cookBookRepo;

  public function __construct(
    RestaurantRepositary $restaurantRepo,
    MenuItemsRepositary $menuRepo,
    CookBookRepositary $cookBookRepo
  ) {
    $this->restaurantRepo = $restaurantRepo;
    $this->menuRepo = $menuRepo;
    $this->cookBookRepo = $cookBookRepo;
  }

  /**
   * To fetch nearest restaurant list according to user location
   * After fetching data from firebase using filter repositary function
   * is returning lattitude, longitude filtered data, then 
   * 
   * @param float $lat
   * @param float $long
   * @param int $page
   * @param int $perPage
   */
  public function getRestaurantsByCoordinates(float $lat, float $long, int $page = 1, int $perPage = 10, ?string $search = null)
  {
    try {
      AppLogger::debug("Arguments recieved: lat = {$lat}, long = {$long}, page = {$page}, perPage = {$perPage}, search = {$search}");
      $restaurants = [];
      /**
       * Progressive radius levels (KM). 
       * used to gradually increase radius during the call. 
       * it will be gradually increased either when page number will be increased 
       * or when nearest data will less than required per page data
       * for example: if 50 data is needed, but for 5KM radius got only 10 data, then
       * will increase radius to 10km then also got 20 data, got 30 total, 
       * so again radius will increase to 20KM and so on
       */
      $radiusLevels = [5, 10, 20, 30, 50];

      // Progressive radius loop
      foreach ($radiusLevels as $radius) {
        AppLogger::debug("Radius loop start with: {$radius}");
        // Prevent division by zero near poles
        $cosLat = cos(deg2rad($lat));
        if ($cosLat == 0) {
          $cosLat = 0.000001;
        }
        // calculating lat long range by radius 
        $latRange = $radius / 111;
        $longRange = $radius / (111 * $cosLat);

        $minLat = $lat - $latRange;
        $maxLat = $lat + $latRange;
        $minLong = $long - $longRange;
        $maxLong = $long + $longRange;
        AppLogger::debug("Calculated lat and long: minLat = {$minLat}, maxLat = {$maxLat}, minLong = {$minLong}, maxLong = {$maxLong}");
        // fetching restaurants based on radius and input lat long
        $restaurants = $this->restaurantRepo->getByBoundingBox(
          $minLat,
          $maxLat,
          $minLong,
          $maxLong
        );

        // if required per page item reached, then breaking loop to imporove response time
        if (count($restaurants) >= ($page * $perPage)) {
          break;
        }
      }
      // if restaurat is not an array then return empty array
      if (!is_array($restaurants)) {
        $restaurants = [];
      }

      // Apply search filter (ILIKE %search%)
      if (!empty($search)) {
        AppLogger::debug("Searching filter is applying");
        $restaurants = array_filter($restaurants, function ($restaurant) use ($search) {
          return isset($restaurant['name']) &&
            stripos($restaurant['name'], $search) !== false;
        });

        $restaurants = array_values($restaurants);
      }

      $result = [];
      // now looping over restaurants to make paginated
      foreach ($restaurants as $restaurant) {
        AppLogger::debug("Longitude filtering is starting for: " . $restaurant['name']);
        if (
          !isset($restaurant['latitude'], $restaurant['longitude']) ||
          !is_numeric($restaurant['latitude']) ||
          !is_numeric($restaurant['longitude'])
        ) {
          continue;
        }
        // calculating distance of restaurant from the input lat long
        $distance = calculateDistance(
          $lat,
          $long,
          (float) $restaurant['latitude'],
          (float) $restaurant['longitude']
        );

        // Convert distance to meters with 3 decimal places
        $distanceInMeters = $distance * 1000; // Convert km to meters
        $formattedDistance = number_format($distanceInMeters, 0, '.', '');

        // preparing response 
        $result[] = [
          'id' => $restaurant['id'] ?? null,
          'name' => $restaurant['name'] ?? null,
          'restaurantImage' => !empty($restaurant['restaurantImage'])
            ? $restaurant['restaurantImage']
            : getPlaceholderImage(),
          'latitude' => number_format((float)$restaurant['latitude'], 6, '.', ''),
          'longitude' => number_format((float)$restaurant['longitude'], 6, '.', ''),
          'distance_m' => $formattedDistance, // Now in meters with 3 decimals
        ];
      }

      // Sort by nearest distance
      usort($result, function ($a, $b) {
        return $a['distance_m'] <=> $b['distance_m'];
      });


      $paginatedData = getPaginatedData($result, $page, $perPage);

      return $paginatedData;
    } catch (Exception $e) {
      $message = "An error occurred: " . $e->getMessage();
      AppLogger::error($message);
      return ApiResponse::error($message, 500);
    }
  }


  private function getLunchRestaurants(float $lat, float $long, int $page = 1, int $perPage = 10, ?string $search = null)
  {
    try {
    } catch (Exception $e) {
      $message = "An error occurred: " . $e->getMessage();
      AppLogger::error($message);
      return ApiResponse::error($message, 500);
    }
  }



  /**
   * API endpoint function to get nearest restaurants
   * from given lat and long
   * 
   * @param GetNearestRestaurantRequest $request
   * @return JsonResponse
   * @route GET /restaurant?lat=&long=&search=&page=&per_page=
   */
  public function getNearestRestaurant(GetNearestRestaurantRequest $request)
  {
    try {
      // validating request
      $data = $request->validated();

      // getting params from request
      $lat = (float) $data['lat'];
      $long = (float) $data['long'];
      $search = $data['search'] ?? null;
      $page = max(1, (int) ($data['page'] ?? 1));
      $perPage = max(1, (int) ($data['per_page'] ?? 10));
      $type = $data['type'];

      // calling fetching restaurants functions
      $result = $this->getRestaurantsByCoordinates($lat, $long, $page, $perPage, $search);
      // giving empty result response
      if (empty($result)) {
        return ApiResponse::success(
          'No restaurants found.',
          [
            'total_records' => 0,
            'total_pages' => 0,
            'current_page' => $page,
            'per_page' => $perPage,
            'restaurants' => [],
          ],
          200
        );
      }
      // giving success response
      return ApiResponse::success(
        'Nearest restaurants fetched successfully.',
        $result,
        200
      );
    } catch (Exception $e) {

      AppLogger::error("RestaurantController@getNearestRestaurant error: " . $e->getMessage());

      return ApiResponse::error(
        'An error occurred: ' . $e->getMessage(),
        500
      );
    }
  }

  /**
   * Fetch Single restaurant details
   * 
   * @param mixed $id
   * @route GET /restaurant/:id
   */
  public function getSingle(Request $request)
  {
    try {

      if (empty($request->id)) {
        return ApiResponse::error("Required parameter restaurant id is missing", 422);
      }

      // getting logged in user id from request
      $user = $request->user();
      if (!$user) {
        AppLogger::warning("Unauthorized profile update attempt.");
        return ApiResponse::error('Unauthorized.', 401);
      }
      $userId = $user->getAuthIdentifier();

      $id = $request->id;
      $page = max(1, (int) $request->get('page', 1));
      $perPage = max(1, (int) $request->get('per_page', 10));

      $restaurant = $this->restaurantRepo->findById($id);

      if (!$restaurant) {
        return ApiResponse::error("Restaurant not found", 404);
      }

      // Return only required restaurant fields
      $restaurantData = [
        "id" => $restaurant['id'] ?? null,
        "name" => $restaurant['name'] ?? "",
        "description" => $restaurant['description'] ?? "",
        "restaurantImage" => $restaurant['restaurantImage'] ?? getPlaceholderImage(),
      ];

      $cookBookResults = $this->cookBookRepo->getCookBookByUser($userId);

      // Fetch paginated menu
      $menuData = $this->menuRepo->getMenuByRestaurant($id, $cookBookResults, $page, $perPage);

      return ApiResponse::success(
        "Restaurant details fetched",
        [
          "restaurant" => $restaurantData,
          "menu" => $menuData
        ],
        200
      );
    } catch (Exception $e) {

      $message = "An error occurred: " . $e->getMessage();
      AppLogger::error($message);

      return ApiResponse::error($message, 500);
    }
  }

  /**
   * To search menu items by menu name
   * 
   * @param Request $request
   * @route GET /restaurant/menu?search=&page=&per_page=&restaurantId=
   */
  public function menuSearch(Request $request)
  {
    try {

      if (empty($request->search) || !$request->restaurantId) {
        AppLogger::error("Search parameter is missing");
        return ApiResponse::error("Required field is missing", 422);
      }

      // getting logged in user id from request
      $user = $request->user();
      if (!$user) {
        AppLogger::warning("Unauthorized profile update attempt.");
        return ApiResponse::error('Unauthorized.', 401);
      }
      $userId = $user->getAuthIdentifier();

      $search = trim($request->search) ?? null;
      $restaurantId = $request->restaurantId;
      $page = max(1, (int) $request->get('page', 1));
      $perPage = max(1, (int) $request->get('per_page', 10));

      $cookBookResults = $this->cookBookRepo->getCookBookByUser($userId);

      // Fetch paginated menu
      $menu = $this->menuRepo->getMenuByRestaurant($restaurantId, $cookBookResults, $page, $perPage, $search);

      return ApiResponse::success(
        "Menu fetched successfully",
        $menu,
        200
      );
    } catch (Exception $e) {

      $message = "An error occurred: " . $e->getMessage();
      AppLogger::error($message);

      return ApiResponse::error($message, 500);
    }
  }
}

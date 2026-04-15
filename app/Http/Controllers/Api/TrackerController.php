<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\Firestore\PantryRepositary;
use App\Helpers\ApiResponse;
use App\Repositories\Firestore\SuggestedMealRepositary;
use App\Repositories\Firestore\CookBookRepositary;
use App\Repositories\Firestore\CookingReposiatry;
use App\Repositories\Firestore\RestaurantRecipeRepositary;
use App\Repositories\Firestore\PreferenceRepository;
use App\Repositories\Firestore\UserRepository;
use App\Services\AppLogger;
use App\Services\OpenAIService;
use Exception;
use Illuminate\Http\Request;
use App\Http\Requests\PantryInsertRequest;
use App\Helpers\FileUploadHelper;
use Carbon\Carbon;


class TrackerController extends Controller
{
  protected $pantryRepo;
  protected $suggestedMealRepo;
  protected $cookBookRepo;
  protected $cookingRepo;
  protected $restaurantRecipeRepo;
  protected $preferenceRepo;
  protected $userRepo;

  public function __construct(
    PantryRepositary $pantryRepo,
    SuggestedMealRepositary $suggestedMealRepo,
    CookBookRepositary $cookBookRepo,
    CookingReposiatry $cookingRepo,
    RestaurantRecipeRepositary $restaurantRecipeRepo,
    PreferenceRepository $preferenceRepo,
    UserRepository $userRepo,
  ) {
    $this->pantryRepo = $pantryRepo;
    $this->suggestedMealRepo = $suggestedMealRepo;
    $this->cookBookRepo = $cookBookRepo;
    $this->cookingRepo = $cookingRepo;
    $this->restaurantRecipeRepo = $restaurantRecipeRepo;
    $this->preferenceRepo = $preferenceRepo;
    $this->userRepo = $userRepo;
  }

  /**
   * To get full tracking screen data
   * @param Request $request
   * @route GET /tracker
   */
  public function index(Request $request)
  {
    try {

      $user = $request->user();
      if (!$user) {
        return ApiResponse::error('Unauthorized.', 401);
      }

      $userId = $user->getAuthIdentifier();

      // get user created date
      $userData = $this->userRepo->find($userId);
      $userCreatedAt = $userData['createdAt'];

      // fetch all data from user creation till today
      $start = Carbon::parse($userCreatedAt)->startOfDay()->toDateString();
      $end   = Carbon::today()->endOfDay()->toDateString();
      // fetching cooked meal of this user according to the date range
      $cookedMeals = $this->cookingRepo->getCookedMealByDateRange($userId, $start, $end);
      // fetching user prefrences 
      $targets = $this->getUserTargets($userId);
      // building data for response
      $data = [
        'today_nutrition' => $this->getTodayNutrition($cookedMeals),

        'goals' => $targets,

        'report' => [
          'last_week' => $this->getReportData($targets, $cookedMeals, 'last_week'),
          'this_week' => $this->getReportData($targets, $cookedMeals, 'this_week'),
          'monthly' => $this->getReportData($targets, $cookedMeals, 'monthly'),
          'last_6_months' => $this->getReportData($targets, $cookedMeals, 'last_6_months'),
          'yearly' => $this->getReportData($targets, $cookedMeals, 'yearly')
        ],

        'savings_summary' => $this->getSavingsSummary($cookedMeals),

        'savings_chart' => [
          'weekly' => $this->getSavingsChart($cookedMeals, 'weekly', $userCreatedAt),
          'monthly' => $this->getSavingsChart($cookedMeals, 'monthly', $userCreatedAt),
          'last_6_months' => $this->getSavingsChart($cookedMeals, 'last_6_months', $userCreatedAt),
          'yearly' => $this->getSavingsChart($cookedMeals, 'yearly', $userCreatedAt)
        ]
      ];

      return ApiResponse::success("Tracker report fetched.", $data);
    } catch (Exception $e) {
      AppLogger::error($e->getMessage());
      return ApiResponse::error($e->getMessage());
    }
  }

  /**
   * getting logged in user's preference which was set at the time of registration
   * @param mixed $userId
   * @return array{calories_target: int, cook_home_target: int, protein_target: int, save_money_target: int}
   */
  public function getUserTargets($userId)
  {
    $preferences = $this->preferenceRepo->finPreferenceByUser($userId);
    // default static values
    $saveMoney = 50;
    $calories  = 2000;
    $protein   = 30;
    $cookHome  = 5;

    if (!empty($preferences)) {

      if (!empty($preferences['targetSaving'])) {
        $saveMoney = (int) filter_var($preferences['targetSaving'], FILTER_SANITIZE_NUMBER_INT);
      }

      if (!empty($preferences['calories'])) {
        $calories = (int) $preferences['calories'];
      }

      if (!empty($preferences['priority'])) {
        $protein = match ($preferences['priority']) {
          'Gain Weight' => 40,
          'Lose Weight' => 35,
          default => 30
        };
      }

      if (!empty($preferences['cookingSkil'])) {
        $cookHome = match ($preferences['cookingSkil']) {
          'Beginner' => 3,
          'Intermediate' => 5,
          'Advance' => 7,
          default => 5
        };
      }
    }

    return [
      'save_money_target' => $saveMoney,
      'calories_target'   => $calories,
      'protein_target'    => $protein,
      'cook_home_target'  => $cookHome
    ];
  }

  /**
   * Helper function to calculate or group the nutrition values
   * @param mixed $meals
   * @return array{calorie: int, carbs: int, fats: int, fiber: int, meals: int, money: int, protein: int}
   */
  public function sumNutrition($meals)
  {
    $sum = [
      'protein' => 0,
      'carbs'   => 0,
      'fats'    => 0,
      'fiber'   => 0,
      'calorie' => 0,
      'money'   => 0,
      'meals'   => 0
    ];

    foreach ($meals as $item) {
      $sum['protein'] += $item['protein'] ?? 0;
      $sum['carbs']   += $item['carbs'] ?? 0;
      $sum['fats']    += $item['fat'] ?? 0;
      $sum['fiber']   += $item['fiber'] ?? 0;
      $sum['calorie'] += $item['calorie'] ?? 0;
      $sum['money']   += $item['moneySaved'] ?? 0;
      $sum['meals']++;
    }

    return $sum;
  }

  /**
   * calculating today's cooked meals nutritions
   * @param mixed $cookedMeals
   * @return array{carbs: string, fats: string, fiber: string, protein: string}
   */
  public function getTodayNutrition($cookedMeals)
  {
    $today = Carbon::today()->toDateString();

    $filtered = array_filter(
      $cookedMeals,
      fn($item) =>
      str_starts_with($item['createdAt'], $today)
    );

    $sum = $this->sumNutrition($filtered);

    return [
      'protein' => $this->decimal($sum['protein']),
      'carbs'   => $this->decimal($sum['carbs']),
      'fats'    => $this->decimal($sum['fats']),
      'fiber'   => $this->decimal($sum['fiber']),
    ];
  }


  /**
   * To generate report last week, week, month, last 6 months and yearly
   * @param mixed $targets
   * @param mixed $cookedMeals
   * @param mixed $type
   * @return array{calorie_target: array{current: string, target: string, unit: string, cook_at_home: array{current: string, target: string, unit: string}, protein_intake: array{current: string, target: string, unit: string}, save_money: array{current: string, target: string, unit: string}}}
   */
  public function getReportData($targets, $cookedMeals, $type)
  {
    $now = Carbon::now();
    // filtering data as per filters
    $filtered = array_filter($cookedMeals, function ($item) use ($type, $now) {

      if (empty($item['createdAt'])) return false;

      $date = Carbon::createFromFormat('Y-m-d H:i:s', $item['createdAt']);

      return match ($type) {
        'this_week' => $date->between($now->copy()->startOfWeek(), $now->copy()->endOfWeek()),
        'last_week' => $date->between($now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek()),
        'monthly'   => $date->month === $now->month && $date->year === $now->year,
        'yearly'    => $date->year === $now->year,
        default     => true,
      };
    });
    // getting sum of nutirion values
    $sum = $this->sumNutrition($filtered);
    $days = max(1, count($filtered));
    // returning final report for all filters
    return [
      'save_money' => [
        'current' => $this->decimal($sum['money'] / $days),
        'target'  => $this->decimal($targets['save_money_target']),
        'unit'    => 'dollars'
      ],
      'calorie_target' => [
        'current' => $this->decimal($sum['calorie'] / $days),
        'target'  => $this->decimal($targets['calories_target']),
        'unit'    => 'cal/day'
      ],
      'protein_intake' => [
        'current' => $this->decimal($sum['protein'] / $days),
        'target'  => $this->decimal($targets['protein_target']),
        'unit'    => 'g/day'
      ],
      'cook_at_home' => [
        'current' => $this->decimal($sum['meals']),
        'target'  => $this->decimal($targets['cook_home_target']),
        'unit'    => 'meals'
      ]
    ];
  }

  /**
   * calculating savings summary this week and total
   * @param mixed $cookedMeals
   * @return array{increase_percentage: string, this_week_saving: string, total_saving: string}
   */
  /**
   * calculating savings summary this week and total
   * @param mixed $cookedMeals
   * @return array{increase_percentage: string, this_week_saving: string, total_saving: string}
   */
  public function getSavingsSummary($cookedMeals)
  {
    $now = Carbon::now();

    // filter this week data
    $thisWeek = array_filter($cookedMeals, function ($item) use ($now) {

      if (empty($item['createdAt'])) return false;

      $date = Carbon::createFromFormat('Y-m-d H:i:s', $item['createdAt']);

      return $date->between(
        $now->copy()->startOfWeek(),
        $now->copy()->endOfWeek()
      );
    });

    // filter last week data
    $lastWeek = array_filter($cookedMeals, function ($item) use ($now) {

      if (empty($item['createdAt'])) return false;

      $date = Carbon::createFromFormat('Y-m-d H:i:s', $item['createdAt']);

      return $date->between(
        $now->copy()->subWeek()->startOfWeek(),
        $now->copy()->subWeek()->endOfWeek()
      );
    });

    // calculate sums
    $thisWeekSum = $this->sumNutrition($thisWeek);
    $lastWeekSum = $this->sumNutrition($lastWeek);
    $totalSum    = $this->sumNutrition($cookedMeals);

    // calculate percentage increase
    $increase = 0;

    if ($lastWeekSum['money'] > 0) {
      $increase = (
        ($thisWeekSum['money'] - $lastWeekSum['money'])
        / $lastWeekSum['money']
      ) * 100;
    }

    return [
      'this_week_saving' => $this->decimal($thisWeekSum['money']),
      'total_saving'     => $this->decimal($totalSum['money']),
      'increase_percentage' => $this->decimal($increase)
    ];
  }

  /**
   * to calculate savings data weekly, monthly, last 6 months and yearlt based on user registration
   * @param mixed $cookedMeals
   * @param mixed $period
   * @param mixed $userCreatedAt
   * @return array
   */
  public function getSavingsChart($cookedMeals, $period, $userCreatedAt)
  {
    $grouped = [];
    $userStart = Carbon::parse($userCreatedAt);

    foreach ($cookedMeals as $item) {

      if (empty($item['createdAt'])) continue;

      $date = Carbon::createFromFormat('Y-m-d H:i:s', $item['createdAt']);

      // skip data before user creation
      if ($date->lt($userStart)) continue;

      switch ($period) {
        case 'weekly':
          $key = $date->format('D');
          break;
        case 'monthly':
        case 'last_6_months':
          $key = $date->format('F');
          break;
        case 'yearly':
          $key = $date->format('Y');
          break;
        case 'today':
          // only today's data
          if (!$date->isToday()) continue 2;
          $key = $date->format('Y-m-d');
          break;

        case 'byDate':
          // group by exact date
          $key = $date->format('Y-m-d');
          break;
      }

      if (!isset($grouped[$key])) {
        $grouped[$key] = ['takeout' => 0, 'home' => 0];
      }

      $grouped[$key]['home'] += $item['moneySaved'] ?? 0;
      $grouped[$key]['takeout'] += $item['estimated_restaurant_price_usd'] ?? 0;
    }

    $result = [];

    foreach ($grouped as $key => $val) {

      // skip empty
      if ($val['home'] == 0 && $val['takeout'] == 0) continue;

      $result[] = [
        'day' => (string) $key,
        'takeout_food' => $this->decimal($val['takeout']),
        'home_cooking' => $this->decimal($val['home'])
      ];
    }

    return array_values($result);
  }

  /**
   * to format data into decimals
   * @param mixed $value
   * @param mixed $precision
   * @return string
   */
  public function decimal($value, $precision = 2)
  {
    return number_format((float)$value, $precision, '.', '');
  }


  /**
   * Get money and calories by specific date
   * @param mixed $cookedMeals
   * @param string $date (Y-m-d)
   * @return array{money: string, calories: string}
   */
  /**
   * Get money and calories by specific date (respect userCreatedAt)
   * @param mixed $cookedMeals
   * @param string $date (Y-m-d)
   * @param string $userCreatedAt
   * @return array{money: string, calories: string}
   */
  public function getSummaryByDate($cookedMeals, $date, $userCreatedAt)
  {
    $userStart = Carbon::parse($userCreatedAt)->startOfDay();
    $targetDate = Carbon::parse($date)->startOfDay();

    // if requested date is before user creation return 0
    if ($targetDate->lt($userStart)) {
      return [
        'money'    => $this->decimal(0),
        'calories' => $this->decimal(0),
      ];
    }

    $filtered = array_filter($cookedMeals, function ($item) use ($date, $userStart) {

      if (empty($item['createdAt'])) return false;

      $itemDate = Carbon::createFromFormat('Y-m-d H:i:s', $item['createdAt']);

      // skip data before user creation
      if ($itemDate->lt($userStart)) return false;

      // match exact date
      return str_starts_with($item['createdAt'], $date);
    });

    $sum = $this->sumNutrition($filtered);

    return [
      'money'    => $this->decimal($sum['money']),
      'calories' => $this->decimal($sum['calorie']),
    ];
  }
}

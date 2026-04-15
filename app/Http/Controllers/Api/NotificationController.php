<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use App\Services\Notification\NotificationService;
use App\Services\FcmService;
use App\Services\AppLogger;
use App\Helpers\ApiResponse;
use Exception;
use Illuminate\Http\Request;
use App\Repositories\Firestore\NotificationRepository;
use App\Repositories\Firestore\CookBookRepositary;
use App\Repositories\Firestore\CookingReposiatry;
use App\Repositories\Firestore\MenuItemsRepositary;
use App\Repositories\Firestore\SuggestedMealRepositary;
use App\Repositories\Firestore\CartRepositary;
use App\Repositories\Firestore\OrderRepositary;
use App\Repositories\Firestore\PantryRepositary;
use App\Http\Controllers\Api\PantryController;

class NotificationController extends Controller
{
    private $notificationService;
    private $fcmService;
    private $notificationRepo;
    private $cookBookRepo;
    private $cookingRepo;
    private $menuItemRepo;
    private $suggestedMealRepo;
    private $cartRepo;
    private $orderRepo;
    private $pantryController;
    private $pantryRepo;

    public function __construct(
        NotificationService $notificationService,
        FcmService $fcmService,
        NotificationRepository $notificationRepo,
        CookBookRepositary $cookBookRepo,
        CookingReposiatry $cookingRepo,
        MenuItemsRepositary $menuItemRepo,
        SuggestedMealRepositary $suggestedMealRepo,
        CartRepositary $cartRepo,
        OrderRepositary $orderRepo,
        PantryController $pantryController,
        PantryRepositary $pantryRepo,
    ) {
        $this->notificationService = $notificationService;
        $this->fcmService = $fcmService;
        $this->notificationRepo = $notificationRepo;
        $this->cookBookRepo = $cookBookRepo;
        $this->cookingRepo = $cookingRepo;
        $this->menuItemRepo = $menuItemRepo;
        $this->suggestedMealRepo = $suggestedMealRepo;
        $this->cartRepo = $cartRepo;
        $this->orderRepo = $orderRepo;
        $this->pantryController = $pantryController;
        $this->pantryRepo = $pantryRepo;
    }

    public function run()
    {
        AppLogger::info("Cron started");

        $lastDoc = Cache::get('notif_cursor');

        $result = $this->notificationService->processChunk($lastDoc);

        if (!empty($result['last_doc'])) {
            Cache::put('notif_cursor', $result['last_doc'], 3600);
        } else {
            Cache::forget('notif_cursor');
        }

        AppLogger::info("Cron finished | processed={$result['count']} | cursor=" . ($result['last_doc'] ?? 'null'));

        return response()->json([
            'success' => true,
            'processed' => $result['count'],
            'cursor' => $result['last_doc'] ?? null
        ]);
    }

    /**
     * Test api for notification
     * @param Request $request
     */
    public function testNotification(Request $request)
    {
        try {
            $type = strtoupper($request->type);
            $fcmToken = $request->fcm;

            $user = $request->user();
            if (!$user) {
                return ApiResponse::error('Unauthorized.', 401);
            }

            $userId = $user->getAuthIdentifier();

            if (!$type || !$fcmToken) {
                return ApiResponse::error("type and fcm required");
            }

            $config = $this->getTriggerConfig($type);

            if (!$config) {
                return ApiResponse::error("Invalid trigger type");
            }

            /*
            // Cooldown via repository ONLY
            if ($this->notificationRepo->wasSentRecently(
                $userId,
                $type,
                $config['cooldown'] ?? null
            )) {
                return ApiResponse::error("Cooldown active for {$type}");
            }*/

            switch ($type) {

                case 'LUNCH_PROMPT':
                case 'DINNER_PROMPT':
                    $finalResponse = [];
                    break;

                case 'ONBOARDING_INCOMPLETE':
                    $finalResponse = [];
                    break;

                case 'RESTAURANT_INSPIRED_RECIPE':
                    $finalResponse = [];
                    break;

                case 'SIMILAR_RECIPE_RECOMMENDATION':
                    $finalResponse = $this->getSimilarMeals($userId) ?? [];
                    break;

                case 'SAVINGS_OPPORTUNITY':
                    $finalResponse = $this->getSavingsRecipe($userId) ?? [];
                    break;

                case 'PANTRY_RECIPE_MATCH':
                    $finalResponse = $this->getSuggestedMeal($userId) ?? [];
                    break;

                case 'SAVED_NOT_COOKED':
                    $finalResponse = $this->getNotCookedMeals($userId) ?? [];
                    break;

                case 'GROCERY_NOT_COMPLETED':
                    $cart = $this->checkGroceryNotCompleted($userId) ?? [];
                    if (empty($finalResponse)) break;
                    $finalResponse = array_map(function ($item) {
                        return collect($item)->except(['user_id', 'createdAt', 'updatedAt'])->toArray();
                    }, $cart);
                    break;
            }

            $fireBaseData = [
                'type' => $type,
                'title' => $config['title'] ?? "",
                'body' => $config['body'] ?? "",
            ];

            if (!empty($finalResponse)) $fireBaseData['recipe'] = $finalResponse;

            // Send notification
            $response = $this->fcmService->sendNotification(
                $config['title'],
                $config['body'],
                $fcmToken,
                $fireBaseData
            );


            $status = $response['result'] === FALSE ? 'failed' : 'sent';

            // Store EXACT structure like your DB screenshot
            $this->notificationRepo->create([
                'user_id' => $userId,
                'trigger_id' => $type,
                'title' => $config['title'],
                'body' => $config['body'],
                'status' => $status,
                'sent_at' => now()->toISOString(),
            ]);

            return response()->json(['status' => true, ...$fireBaseData], 200);
        } catch (Exception $e) {
            AppLogger::error("Test notification error: " . $e->getMessage());
            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    /**
     * To get triggers
     * @param mixed $type
     * @return array{body: string, cooldown: int, title: string|array{body: string, cooldown: null, one_time: bool, title: string}|array{body: string, cooldown: null, title: string}|null}
     */
    private function getTriggerConfig($type)
    {
        return [
            'ONBOARDING_INCOMPLETE' => [
                'cooldown' => 48,
                'title' => 'Finish Setup',
                'body' => 'Finish setting up WiseFork so your meal ideas actually match what you like.',
            ],
            'FIRST_VALUE_MILESTONE' => [
                'cooldown' => null,
                'one_time' => true,
                'title' => 'Good Start',
                'body' => 'We’ll use this to improve WiseFork\'s next recommendations.',
            ],
            'LUNCH_PROMPT' => [
                'cooldown' => 96,
                'title' => 'Lunch Time',
                'body' => 'Lunch idea: make something you’d usually order, for less.',
            ],
            'DINNER_PROMPT' => [
                'cooldown' => 120,
                'title' => 'Dinner Tonight',
                'body' => 'Dinner tonight: a cheaper version of something you’d normally order is ready.',
            ],
            'RESTAURANT_INSPIRED_RECIPE' => [
                'cooldown' => 72,
                'title' => 'Cook Your Favorite',
                'body' => 'WiseFork turned one of your go-to meals into something you can make at home!',
            ],
            'SIMILAR_RECIPE_RECOMMENDATION' => [
                'cooldown' => 72,
                'title' => 'You’ll Like This',
                'body' => 'Based on what you saved, this should be a strong fit.',
            ],
            'SAVINGS_OPPORTUNITY' => [
                'cooldown' => 120,
                'title' => 'Save Money',
                'body' => 'You could save money tonight by making this at home.',
            ],
            'PANTRY_RECIPE_MATCH' => [
                'cooldown' => 96,
                'title' => 'Use Your Pantry',
                'body' => 'You already have enough ingredients for at least one good meal.',
            ],
            'SAVED_NOT_COOKED' => [
                'cooldown' => 168,
                'title' => 'Cook What You Saved',
                'body' => 'You saved this. Want to make it tonight?',
            ],
            'GROCERY_NOT_COMPLETED' => [
                'cooldown' => 72,
                'title' => 'Finish Your List',
                'body' => 'Finish your list and make tonight easier.',
            ],
            'INACTIVE_7_DAYS' => [
                'cooldown' => null,
                'title' => 'Come Back',
                'body' => 'A cheaper, easier food decision is waiting for you.',
            ],
        ][$type] ?? null;
    }


    /**
     * To get recipe which are saved in cookbook but not cooked
     * @param mixed $userId
     * @throws Exception
     * @return array{appliances: mixed, calorie: mixed, calorie_vs_takeout: mixed, cookingTime: mixed, costComparision: array{homeCookCost: int|string, takeOut: string, id: mixed, imageUrl: mixed, ingredientCount: mixed, ingredients: mixed, isCookBook: bool, itemDescription: mixed, protein_content: mixed, sodium_levels: mixed, title: mixed}|array{appliances: mixed, calorie: mixed, calorie_vs_takeout: mixed, cookingTime: mixed, costComparision: array{homeCookCost: int|string, takeOut: string}, id: null, imageUrl: mixed, ingredientCount: mixed, ingredients: mixed, isCookBook: bool, itemDescription: mixed, protein_content: mixed, sodium_levels: mixed, title: mixed}|null}
     */
    private function getNotCookedMeals($userId)
    {
        try {
            $savedMenus = $this->cookBookRepo->getCookBookByUser($userId);
            $cookedMenus = $this->cookingRepo->getCookedRecipesByUser($userId);

            if (empty($savedMenus)) return null;

            // Create lookup map
            $cookedMap = [];
            foreach ($cookedMenus as $item) {
                if (!empty($item['recipe_id'])) {
                    $cookedMap[$item['recipe_id']] = true;
                }
            }

            // store here
            $notCookedMenuId = null;

            foreach ($savedMenus as $menu) {
                if (empty($menu['menu_id'])) continue;

                if (!isset($cookedMap[$menu['menu_id']])) {
                    $notCookedMenuId = $menu['menu_id'];
                    break; // important
                }
            }

            // getting reciepe details
            $recipe = $this->menuItemRepo->findById($notCookedMenuId);

            if (!$recipe || empty($recipe)) {
                $recipe = $this->suggestedMealRepo->findById($notCookedMenuId);
            }

            return $this->pantryController->formatData($recipe, $userId, true);
        } catch (Exception $e) {
            AppLogger::error($e->getMessage());
            throw new Exception($e->getMessage());
        }
    }

    /**
     * fecth cart items
     * @param mixed $userId
     * @throws Exception
     * @return array
     */
    private function checkGroceryNotCompleted($userId)
    {
        try {
            $cartItems = $this->cartRepo->getCartByUser($userId);

            if ($cartItems && !empty($cartItems)) {
                return $cartItems;
            }

            return [];
        } catch (Exception $e) {
            AppLogger::error($e->getMessage());
            throw new Exception($e->getMessage());
        }
    }

    /**
     * To get suggested meal based on pantry
     * @param mixed $userId
     * @throws Exception
     */
    private function getSuggestedMeal($userId)
    {
        try {
            $savedMeals = $this->pantryController->fetchSuggestedMeal($userId);
            $suggestedMeals = [];
            if ($savedMeals && !empty($savedMeals)) {
                // extracting only selected values from item
                $suggestedMeals = $this->pantryController->formatData($savedMeals, $userId);
            } else {
                // no suggested meals are found
                // getting pantry for this user
                $pantry = $this->pantryRepo->getPantryItemsByUser($userId);
                if (!$pantry) {
                    return [];
                }

                // generating suggested meals
                $suggestedMeals = $this->pantryController->generateMeals($pantry, $userId);
            }

            return $suggestedMeals && !empty($suggestedMeals) ? $suggestedMeals[0] : [];
        } catch (Exception $e) {
            AppLogger::error($e->getMessage());
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Common function to get saved and cooked menues
     * @param mixed $userId
     * @param mixed $type
     * @throws Exception
     */
    private function findMealByStrategy($userId, $type = 'similar')
    {
        try {
            $savedMenus = $this->cookBookRepo->getCookBookByUser($userId);
            $cookedMenus = $this->cookingRepo->getCookedRecipesByUser($userId);

            // Collect IDs (IMPORTANT)
            $menuIds = [];

            foreach ($savedMenus as $item) {
                if (!empty($item['menu_id'])) {
                    $menuIds[] = $item['menu_id'];
                }
            }

            foreach ($cookedMenus as $item) {
                if (!empty($item['recipe_id'])) {
                    $menuIds[] = $item['recipe_id'];
                }
            }

            $menuIds = array_values(array_unique($menuIds));

            if (empty($menuIds)) {
                return null;
            }

            // Fetch base menu items (user history)
            $baseMenus = $this->menuItemRepo->getMenuByMultipleIds($menuIds);

            if (empty($baseMenus)) {
                return null;
            }

            /*
        |--------------------------------------------------------------------------
        | STRATEGY SWITCH
        |--------------------------------------------------------------------------
        */

            if ($type === 'similar') {
                return $this->findSimilarFromTokens($baseMenus, $userId);
            }

            if ($type === 'saving') {
                return $this->findCheaperMeal($baseMenus, $userId);
            }

            return null;
        } catch (Exception $e) {
            AppLogger::error($e->getMessage());
            throw new Exception($e->getMessage());
        }
    }

    /**
     * To get same meals like cooked or saved
     * @param mixed $baseMenus
     * @param mixed $userId
     */
    private function findSimilarFromTokens($baseMenus, $userId)
    {
        $tokens = [];

        foreach ($baseMenus as $item) {

            if (!empty($item['searchTokens'])) {
                foreach ($item['searchTokens'] as $t) {
                    $tokens[] = strtolower(trim($t));
                }
            } elseif (!empty($item['title'])) {
                $words = preg_split('/\s+/', strtolower($item['title']));
                foreach ($words as $w) {
                    if (strlen($w) > 2) {
                        $tokens[] = $w;
                    }
                }
            }
        }

        $tokens = array_values(array_unique(array_filter($tokens)));

        foreach ($tokens as $token) {

            $results = $this->menuItemRepo->searchByTokens($token);

            if (!empty($results)) {
                foreach ($results as $item) {
                    return $this->pantryController->formatData($item, $userId, true);
                }
            }
        }

        return null;
    }

    /**
     * To get cheaper meal than saved or cooked
     * @param mixed $baseMenus
     * @param mixed $userId
     * @throws Exception
     */
    private function findCheaperMeal($baseMenus, $userId)
    {
        try {
            // Step 1: get max cost
            $maxCost = 0;

            foreach ($baseMenus as $item) {
                $cost = $item['estimated_home_cost_usd'] ?? 0;
                if ($cost > $maxCost) {
                    $maxCost = $cost;
                }
            }

            if ($maxCost <= 0) {
                return null;
            }

            // Step 2: paginated scan (CONTROLLED)
            $lastDoc = null;
            $limit = 25; // keep small (important)

            while (true) {

                $results = $this->menuItemRepo->getMenuItemsByLimit($limit, $lastDoc);

                if (empty($results)) {
                    break; // no more data
                }

                foreach ($results as $item) {

                    $cost = $item['estimated_home_cost_usd'] ?? 0;

                    // ONLY CONDITION
                    if ($cost > 0 && $cost < $maxCost) {
                        return $this->pantryController->formatData($item, $userId, true);
                    }
                }

                // move cursor (VERY IMPORTANT)
                $lastDoc = end($results)['__name__'] ?? null;

                // safety break (avoid infinite loop)
                if (!$lastDoc) {
                    break;
                }
            }

            return null;
        } catch (Exception $e) {
            AppLogger::error($e->getMessage());
            throw new Exception($e->getMessage());
        }
    }


    private function getSimilarMeals($userId)
    {
        return $this->findMealByStrategy($userId, 'similar');
    }

    private function getSavingsRecipe($userId)
    {
        return $this->findMealByStrategy($userId, 'saving');
    }
}

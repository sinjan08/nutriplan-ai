<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\Firestore\PantryRepositary;
use App\Helpers\ApiResponse;
use App\Repositories\Firestore\SuggestedMealRepositary;
use App\Repositories\Firestore\CookBookRepositary;
use App\Repositories\Firestore\RestaurantRecipeRepositary;
use App\Repositories\Firestore\PreferenceRepository;
use App\Repositories\Firestore\MenuItemsRepositary;
use App\Services\AppLogger;
use App\Services\OpenAIService;
use Exception;
use Illuminate\Http\Request;
use App\Http\Requests\PantryInsertRequest;
use App\Helpers\FileUploadHelper;


class PantryController extends Controller
{
  protected $pantryRepo;
  protected $openAI;
  protected $suggestedMealRepo;
  protected $cookBookRepo;
  protected $restaurantRecipeRepo;
  protected $preferenceRepo;
  protected $menuItemRepo;

  public function __construct(
    PantryRepositary $pantryRepo,
    OpenAIService $openAI,
    SuggestedMealRepositary $suggestedMealRepo,
    CookBookRepositary $cookBookRepo,
    RestaurantRecipeRepositary $restaurantRecipeRepo,
    PreferenceRepository $preferenceRepo,
    MenuItemsRepositary $menuItemRepo,
  ) {
    $this->pantryRepo = $pantryRepo;
    $this->openAI = $openAI;
    $this->suggestedMealRepo = $suggestedMealRepo;
    $this->cookBookRepo = $cookBookRepo;
    $this->restaurantRecipeRepo = $restaurantRecipeRepo;
    $this->preferenceRepo = $preferenceRepo;
    $this->menuItemRepo = $menuItemRepo;
  }
  /**
   * To fetch all pantry items based on logged in user id
   *
   * @param Request $request
   * @route GET /pantry
   */
  public function getPantry(Request $request)
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
      $finalResponse = $this->pantryRepo->getPantryItemsByUser($userId);
      if (empty($finalResponse)) {
        return ApiResponse::success("No item found");
      }

      // Apply search on name
      if (!empty($search)) {
        $finalResponse = array_values(array_filter($finalResponse, function ($item) use ($search) {
          return isset($item['name']) && str_contains(strtolower($item['name']), $search);
        }));
      }

      // Convert imageUrl to public url
      $finalResponse = array_map(function ($item) {
        $item['imageUrl'] = isset($item['imageUrl']) && $item['imageUrl'] ? publicStorageUrl($item['imageUrl']) : getPlaceholderImage(true);
        $item['qty'] = (float)$item['qty'];
        return $item;
      }, $finalResponse);

      // making pagination
      $responsePayload = getPaginatedData($finalResponse, $currentPage, $perPage);

      return ApiResponse::success("Pantry items are fetched", $responsePayload, 200);
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
   * Summary of create
   * @param PantryInsertRequest $request
   * @route POST /pantry/add
   */
  public function create(PantryInsertRequest $request)
  {
    try {
      // validating request
      $item = $request->validated();

      // validating user
      $user = $request->user();
      if (!$user) {
        return ApiResponse::error('Unauthorized.', 401);
      }

      $userId = $user->getAuthIdentifier();
      $path = null;

      // validating request has file or not
      if ($request->hasFile('itemImage')) {
        // validating request has file or not
        $file = $request->file('itemImage');
        // if file is not corrupted
        if ($file->isValid()) {
          // uploading
          $path = FileUploadHelper::upload($file, "pantry");
        } else {
          return ApiResponse::error('Invalid image upload.', 422);
        }
      }

      // creating new pantry  
      $newPantry = $this->pantryRepo->create([
        'user_id' => $userId,
        'name' => $item['name'],
        'imageUrl' => $path,
        'qty' => $item['qty'],
        'unit' => $item['unit'],
      ]);

      if (!$newPantry) {
        $message = "Failed to create new pantry";
        AppLogger::error($message);
        return ApiResponse::error($message);
      }

      $latestInsertedItem = $this->pantryRepo->findById($newPantry['id']);

      return ApiResponse::success("Item has inserted into pantry", $latestInsertedItem, 201);
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
   * To generate suggested meal using open ai based on pantry items
   * @param array $pantryItems
   */
  public function suggestMealsFromPantry(array $pantryItems, $userId)
  {
    try {

      if (empty($pantryItems)) {
        return [];
      }

      // Reduce payload to only required fields
      $pantry = array_map(function ($item) {
        return [
          'name' => $item['name'],
          'qty'  => $item['qty'],
          'unit' => $item['unit']
        ];
      }, $pantryItems);

      $pantryJson = json_encode($pantry);
      // fetching user preference
      $preferences = $this->preferenceRepo->finPreferenceByUser($userId);
      $preferencesJson = json_encode($preferences);
      $prompt = "
You are a professional chef, recipe developer, and nutrition expert.

Generate realistic meal ideas using pantry ingredients and user preferences.

PANTRY INGREDIENTS (JSON):
$pantryJson

USER PREFERENCES (JSON):
$preferencesJson

PREFERENCE RULES
- Preferences JSON may have missing fields; ignore missing keys.
- Meals MUST respect dietaryPreference, preferredCuisin, priority goals, cookingSkil, appliances, and budget fields when provided.
- Support goals like Gain Weight / Lose Weight.
- Align meals with calorie guidance if present.
- Match recipe difficulty to cookingSkil.
- Prefer cuisines in preferredCuisin.
- Respect dietary restrictions.

OBJECTIVE
Generate multiple meal suggestions. Each suggestion must be ONE standalone dish.

CRITICAL RULES
1. Only ONE dish per meal.
2. No combo meals, plates, platters, bowls, or sides.
3. Avoid phrases: served with, alongside, plate, combo, platter, bowl, meal set.
4. Do not combine multiple meals.
5. Meals must satisfy BOTH pantry ingredients and user preferences.

VALID
Scrambled Eggs
Garlic Butter Pasta
Chicken Stir Fry
Mushroom Omelette
Turkey Meatballs
Creamy Tomato Pasta

INVALID
Chicken with rice
Rice bowl
Breakfast plate
Steak with mashed potatoes
Pasta with salad
Combo meal
Burrito bowl

INGREDIENT RULES
- Each meal must use at least 1–2 pantry ingredients.
- Prefer pantry ingredients whenever possible.
- Additional ingredients allowed if necessary.
- Avoid rare ingredients unless already in pantry.
- Assume common staples exist: salt, pepper, oil, butter, garlic, onion, flour, milk, herbs, spices.

INSTACART MEASUREMENTS

MEASURED
cup,cups,c
fl oz can
fl oz container
fl oz jar
fl oz pouch
fl oz ounce
gallon,gal
milliliter,ml
liter,l
pint,pt
pt container
quart,qt
tablespoon,tbsp
teaspoon,tsp

WEIGHED
gram,g
kilogram,kg
ounce,oz
pound,lb
lb bag
lb can
lb container
oz bag
oz can
oz container
per lb

COUNTABLE
each
bunch
can
ears
head
large
medium
small
package
packet
small ears
small head

RULES
- Quantities must be numeric.
- Use 'each' for countable items.
- Avoid vague units: handful, pinch, splash, dash, to taste.
- Ingredient names should resemble grocery store product names.

GOOD
{\"name\":\"egg\",\"qty\":2,\"unit\":\"each\"}
{\"name\":\"garlic\",\"qty\":3,\"unit\":\"clove\"}
{\"name\":\"olive oil\",\"qty\":1,\"unit\":\"tablespoon\"}
{\"name\":\"chicken breast\",\"qty\":1,\"unit\":\"lb\"}
{\"name\":\"milk\",\"qty\":2,\"unit\":\"cup\"}
{\"name\":\"tomatoes\",\"qty\":2,\"unit\":\"each\"}

BAD
{\"name\":\"salt\",\"qty\":\"to taste\",\"unit\":\"\"}
{\"name\":\"spinach\",\"qty\":\"handful\",\"unit\":\"\"}
{\"name\":\"butter\",\"qty\":\"some\",\"unit\":\"\"}

APPLIANCES
Return only appliances required for cooking.
Prefer appliances listed in preferences.
If unavailable, suggest alternatives (Air Fryer→oven/pan, Blender→immersion blender, Grill→skillet).

COOKING METHODS
saute, stir fry, bake, pan fry, simmer, roast, scramble, grill, braise, steam, confit, sous vide, reduce, caramelize

STEP RULES
Steps must include:
step_number
instruction
estimated_time_minutes
optional_tip

Requirements
- 1–2 concise sentences
- realistic times
- tips only when useful
- logical order

Example
{\"step_number\":1,\"instruction\":\"Cut chicken into cubes and season.\",\"estimated_time_minutes\":5,\"tip\":\"Uniform pieces cook evenly.\"}

Return total_estimated_time_minutes equal to the sum of step times.

NUTRITION (per serving)
calories_kcal
protein_g
carbs_g
fat_g
sugar_g
fiber_g

TAKEOUT COMPARISON
Return:
calories_vs_takeout_percent
protein_vs_takeout_percent
sodium_vs_takeout_percent

Rules
- calories usually LOWER
- protein usually HIGHER
- sodium usually LOWER
- integer percentages

COST
estimated_restaurant_price_usd
estimated_home_cost_usd
money_saved_usd = restaurant − home

OUTPUT
Return STRICT JSON ONLY.

{
\"meals\":[
{
\"title\":\"Meal title\",
\"short_description\":\"Short description\",
\"cuisine_type\":\"Cuisine type\",
\"difficulty\":\"easy | medium | hard\",
\"meal_type\":\"breakfast | lunch | dinner\",
\"ingredients\":[{\"name\":\"ingredient\",\"qty\":\"amount\",\"unit\":\"unit\"}],
\"required_appliances\":[\"appliance\"],
\"matched_ingredients\":[\"ingredient name\"],
\"steps\":[{\"step_number\":1,\"instruction\":\"Instruction text\",\"estimated_time_minutes\":5,\"tip\":\"Optional cooking tip\"}],
\"total_estimated_time_minutes\":0,
\"nutrition\":{\"calories_kcal\":0,\"protein_g\":0,\"carbs_g\":0,\"fat_g\":0,\"sugar_g\":0,\"fiber_g\":0},
\"nutrition_gain\":{\"calories_vs_takeout_percent\":0,\"protein_vs_takeout_percent\":0,\"sodium_vs_takeout_percent\":0},
\"estimated_cook_time\":\"15-25 minutes\",
\"ingredient_count\":0,
\"matched_ingredient_count\":0,
\"estimated_restaurant_price_usd\":0,
\"estimated_home_cost_usd\":0,
\"money_saved_usd\":0,
\"pantry_usage_percentage\":0,
\"cooking_method\":\"primary cooking technique\",
\"flavor_profile\":\"savory | spicy | creamy | tangy | herby\",
\"skill_tags\":[\"quick\",\"high-protein\"],
\"appliances\":[\"...\"]
}
]
}
";


      $content = $this->openAI->generateText($prompt);

      if (!$content) {
        AppLogger::warning("OpenAI returned empty meal response");
        return [];
      }
      $content = trim($content);

      // remove markdown code fences
      $content = preg_replace('/```(json)?/i', '', $content);

      $content = trim($content);

      $data = json_decode($content, true);

      if (!$data || !isset($data['meals'])) {
        AppLogger::error("Invalid JSON response from OpenAI meals");
        return [];
      }

      // Generate images for meals
      foreach ($data['meals'] as &$meal) {
        $meal['image'] = $this->getMenuImage($meal['title']);
      }

      return $data;
    } catch (Exception $e) {

      AppLogger::error("OpenAI Meal Suggestion Error: " . $e->getMessage());

      return null;
    }
  }

  /**
   * To generate image using chatgpt
   * @param mixed $mealTitle
   */
  public function getMenuImage($mealTitle)
  {
    try {
      // $imagePrompt = "Professional food photography of {$mealTitle}, beautifully plated, restaurant quality lighting, high detail, shot with 85mm lens";
      //$imagePrompt = "A casual, realistic food delivery app photo of {$mealTitle}, served in a plain white plate on a restaurant table. Slightly messy but appetizing plating, visible texture, natural oil gloss, uneven sauce spread. Ambient indoor lighting, mild shadows, no studio perfection. Background shows subtle restaurant elements (table edge, napkin, cutlery slightly out of focus). Shot at 45-degree angle, iPhone camera style, authentic and unedited look.";
      $imagePrompt = "Realistic {$mealTitle}, neatly sliced turkey fanned with gravy, mashed potatoes and green beans, balanced plating, slight herb or cranberry garnish, warm indoor lighting, soft shadows, restaurant table, 45-degree smartphone photo, no text.";


      $mealImage = $this->openAI->generateImage($imagePrompt);
      if (!$mealImage) {
        $mealImage = getPlaceholderImage(true);
      }

      return $mealImage;
    } catch (Exception $e) {

      AppLogger::error("OpenAI Meal Suggestion Error: " . $e->getMessage());

      return null;
    }
  }

  /**
   * To generate ai data of recipe details which are scrapped 
   * from restaurant using uber eats
   * @param string $userId
   * @param string $title
   * @param mixed $preferencesJson
   */
  public function generateRecipeData(string $userId, string $title, $preferencesJson = '{}')
  {
    try {
      $preferences = $this->preferenceRepo->finPreferenceByUser($userId);
      $preferencesJson = json_encode($preferences);
      // $fields = implode(', ', $missingFields);

      $prompt = "
You are a professional chef, nutritionist, and meal planner.

USER PREFERENCES (JSON):
{$preferencesJson}

IMPORTANT RULES:
- Respect dietaryPreference, preferredCuisin, cookingSkil, appliances, and goals if present
- Match difficulty to cookingSkil
- Prefer appliances from preferences
- Align with health goals (Lose Weight / Gain Weight)
- Follow cuisine preference strictly when provided

OBJECTIVE:
Generate ONE recipe for: {$title}

CRITICAL:
- Return EXACT same schema as provided
- Do NOT change structure
- Do NOT skip keys
- Fill realistic values
- Use valid grocery-style ingredient format
- Units must be valid (tsp, tbsp, cup, each, lb, etc.)

STRICT JSON ONLY

OUTPUT:
{
\"short_description\":\"...\",
\"cuisine_type\":\"...\",
\"difficulty\":\"easy | medium | hard\",
\"meal_type\":\"breakfast | lunch | dinner\",
\"ingredients\":[
  {\"name\":\"ingredient\",\"qty\":1,\"unit\":\"unit\"}
],
\"required_appliances\":[\"appliance\"],
\"steps\":[
  {\"step_number\":1,\"instruction\":\"...\",\"estimated_time_minutes\":5,\"tip\":\"...\"}
],
\"total_estimated_time_minutes\":0,
\"nutrition\":
{
  \"calories_kcal\":0,
  \"protein_g\":0,
  \"carbs_g\":0,
  \"fat_g\":0,
  \"sugar_g\":0,
  \"fiber_g\":0
},
\"nutrition_gain\":
{
  \"calories_vs_takeout_percent\":0,
  \"protein_vs_takeout_percent\":0,
  \"sodium_vs_takeout_percent\":0
},
\"estimated_cook_time\":\"15-25 minutes\",
\"ingredient_count\":0,
\"estimated_home_cost_usd\":0,
\"cooking_method\":\"...\",
\"flavor_profile\":\"...\",
\"skill_tags\":[\"...\"],
\"appliances\":[\"...\"]
}
";

      $response = $this->openAI->generateText($prompt);
      // Remove ```json and ``` wrappers
      $cleaned = preg_replace('/^```json\s*|\s*```$/', '', trim($response));

      // Decode
      $decoded = json_decode($cleaned, true);

      if (json_last_error() !== JSON_ERROR_NONE || empty($decoded)) {
        return [];
      }

      return $decoded;
    } catch (Exception $e) {
      AppLogger::error("AI Recipe Generation Error: " . $e->getMessage());
      return [];
    }
  }


  /**
   * To fetch all suggested meals
   * @param mixed $userId
   */
  public function fetchSuggestedMeal($userId)
  {
    try {
      // getting all suggested meal for provided user id
      $suggestedMeals = $this->suggestedMealRepo->getSuggestedMealByUser($userId);

      if (empty($suggestedMeals)) {
        return [];
      }

      return $suggestedMeals;
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
   * To foramt data for the response of suggested meal only
   * @param mixed $data
   * @param mixed $userId
   * @throws Exception
   */
  public function formatData($data, $userId, $isSingle = false)
  {
    try {
      if (!empty($data)) {
        $cookbooks = $this->cookBookRepo->getCookBookByUser($userId);
        $cookBookMealIds = array_column($cookbooks, 'menu_id');

        if ($isSingle) {
          // $isCookBook = $this->cookBookRepo->isCookBookMenu($userId, $item['id']);
          $isCookBook = in_array($data['id'], $cookBookMealIds);

          return [
            "id" => $data['id'],
            "title" => $data['title'] ?? '',
            "itemDescription" => $data['short_description'] ?? '',
            "imageUrl" => isset($data['image']) && $data['image'] ? publicStorageUrl($data['image']) : getPlaceholderImage(true),

            "calorie" => $data['nutrition']['calories_kcal'] ?? 0,
            "cookingTime" => $data['estimated_cook_time'] ?? '25-30 mins',

            "ingredientCount" => $data['ingredient_count'] ?? 0,
            "pantryHasIngredientCount" => $data['matched_ingredient_count'] ?? 0,

            "costComparision" => [
              "takeOut" => number_format($data['estimated_restaurant_price_usd'] ?? 0, 2, '.', ''),
              "homeCookCost" => number_format($data['estimated_home_cost_usd'] ?? 0, 2, '.', ''),
            ],

            'appliances' => $data['required_appliances'] ?? [],

            'ingredients' => $data['ingredients'] ?? [],

            "isCookBook" => $isCookBook
          ];
        }


        $collection = collect($data);

        // extracting only selected values from item
        $result = $collection->map(function ($item) use ($cookBookMealIds) {
          // $isCookBook = $this->cookBookRepo->isCookBookMenu($userId, $item['id']);
          $isCookBook = in_array($item['id'], $cookBookMealIds);

          return [
            "id" => $item['id'],
            "title" => $item['title'] ?? '',
            "itemDescription" => $item['short_description'] ?? '',
            "imageUrl" => $item['image'] ? publicStorageUrl($item['image']) : getPlaceholderImage(true),

            "calorie" => $item['nutrition']['calories_kcal'] ?? 0,
            "cookingTime" => $item['estimated_cook_time'] ?? '25-30 mins',

            "ingredientCount" => $item['ingredient_count'] ?? 0,
            "pantryHasIngredientCount" => $item['matched_ingredient_count'] ?? 0,

            "costComparision" => [
              "takeOut" => number_format($item['estimated_restaurant_price_usd'] ?? 0, 2, '.', ''),
              "homeCookCost" => number_format($item['estimated_home_cost_usd'] ?? 0, 2, '.', ''),
            ],

            'appliances' => $item['required_appliances'] ?? [],

            'ingredients' => $item['ingredients'] ?? [],

            "isCookBook" => $isCookBook
          ];
        })->values()->toArray();

        return $result;
      } else {
        return [];
      }
    } catch (Exception $e) {
      $message = "An error occurred: " . $e->getMessage();
      AppLogger::error($message);

      throw new Exception($message);
    }
  }

  /**
   * To generate meals using ai and saving into db
   * @param mixed $pantryItems
   * @param mixed $userId
   * @throws Exception
   */
  public function generateMeals($pantryItems, $userId)
  {
    try {
      // extracting selected data from pantry items array
      $items = collect($pantryItems)->map(function ($item) {
        return [
          'name' => $item['name'],
          'qty'  => $item['qty'],
          'unit' => $item['unit']
        ];
      })->values()->toArray();
      if (empty($items)) {
        throw new Exception("No item found in pantry");
      }

      // Call OpenAI
      $suggestedMeals = $this->suggestMealsFromPantry($items, $userId);
      // dd('suggestedMeals', $suggestedMeals);
      if (empty($suggestedMeals)) {
        throw new Exception("No meal can be made by available items");
      }

      $savedMeals = [];
      // checking meals is send or not
      if (isset($suggestedMeals['meals'])) {
        // loop over open ai result
        foreach ($suggestedMeals['meals'] as $meal) {

          $saveData = [
            'user_id' => $userId,
            'searchTokens' => explode(" ", $meal['title']),
            ...$meal
          ];
          // saving suggested meal for this user
          $newMeal = $this->suggestedMealRepo->create($saveData);

          if ($newMeal) {
            $savedMeals[] = [
              'id' => $newMeal['id'],
              "title" => $meal['title'] ?? '',
              "itemDescription" => $meal['short_description'] ?? '',
              "imageUrl" => $meal['image'] ? publicStorageUrl($meal['image']) : getPlaceholderImage(true),

              "calorie" => $meal['nutrition']['calories_kcal'] ?? 0,
              "cookingTime" => $meal['estimated_cook_time'] ?? '25-30 mins',

              "ingredientCount" => $meal['ingredient_count'] ?? 0,
              "pantryHasIngredientCount" => $meal['matched_ingredient_count'] ?? 0,

              "costComparision" => [
                "takeOut" => number_format($meal['estimated_restaurant_price_usd'] ?? 0, 2, '.', ''),
                "homeCookCost" => number_format($meal['estimated_home_cost_usd'] ?? 0, 2, '.', ''),
              ],

              'appliances' => $meal['required_appliances'] ?? [],

              "isCookBook" => false
            ];
          }
        }


        // Format response
        //return $this->formatData($suggestedMeals['meals'], $userId);
        return $savedMeals;
      } else {
        return [];
      }
    } catch (Exception $e) {

      $message = "An error occurred: " . $e->getMessage();
      AppLogger::error($message);

      throw new Exception($message);
    }
  }

  /**
   * To fetch suggested meals for the logged in user endpoint 
   * 
   * @param Request $request
   * @route GET /pantry/suggested
   */
  public function getSuggestedMeals(Request $request)
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

      $finalResponse = [];
      $suggestedMeals = null;
      // getting sabed suggested meals
      $savedMeals = $this->fetchSuggestedMeal($userId);

      if ($savedMeals && !empty($savedMeals)) {
        // extracting only selected values from item
        $suggestedMeals = $this->formatData($savedMeals, $userId);
      } else {
        // no suggested meals are found
        // getting pantry for this user
        $pantry = $this->pantryRepo->getPantryItemsByUser($userId);
        if (!$pantry) {
          return ApiResponse::success("No item is in pantry");
        }

        // generating suggested meals
        $suggestedMeals = $this->generateMeals($pantry, $userId);
      }


      // filter by searching
      if ($search && !empty($search)) {
        $suggestedMeals = array_values(array_filter($suggestedMeals, function ($item) use ($search) {
          return isset($item['name']) && str_contains(strtolower($item['title']), $search);
        }));
      }

      // paginating the data
      $paginatedData = getPaginatedData($suggestedMeals, $currentPage, $perPage);

      return ApiResponse::success("Suggested meals are fetched", $paginatedData);
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
   * Helper function to get details and returning final response
   * @param mixed $id
   * @param mixed $userId
   * @throws Exception
   */
  public function getMenuDetails($id, $userId, $isSteps = false)
  {
    try {
      $isSuggestedMeal = false;
      // getting reciepe details
      $recipe = $this->menuItemRepo->findById($id);

      if (!$recipe || empty($recipe)) {
        $recipe = $this->suggestedMealRepo->findById($id);
        if (!$recipe || empty($recipe)) {
          return [];
        }
        $isSuggestedMeal = true;
      }

      if (isset($recipe['image']) && $recipe['image']) {
        $recipeImage = publicStorageUrl($recipe['image']);
      } else {
        $recipeImage = getPlaceholderImage(true);
      }

      $recipeImagePath = null;
      $aiData = [];
      // if not suggested meal then generating AI data for the selected menu
      if (!$isSuggestedMeal && isset($recipe['aiStatus']) && $recipe['aiStatus'] != 'completed') {
        // checking menu has image or not, if image is not available then generating image using ai
        if (!isset($recipe['image']) || !$recipe['image']) {
          $recipeImagePath = $this->getMenuImage($recipe['title']);
          $recipeImage = publicStorageUrl($recipeImagePath);
        }
        // dd('recipeImagePath', $recipeImagePath, 'recipeImage', $recipeImage);
        // fetching preferences to generate ai data
        $preferences = $this->preferenceRepo->finPreferenceByUser($userId);
        $preferencesJson = json_encode($preferences);
        // generating ai data based on preferences
        $aiData = $this->generateRecipeData($userId, $recipe['title'], $preferencesJson);
        // updating the menu by the generated ai data
        $updateData = [...$aiData, 'image' => $recipeImagePath, 'aiStatus' => 'completed'];

        $this->menuItemRepo->update($id, $updateData);
      }

      // checking is in cookbook or not
      $isCookBook = $this->cookBookRepo->isCookBookMenu($userId, $id);

      $takeOutPrice = $recipe['estimated_restaurant_price_usd'] ?? (float)($recipe['price'] / 100) ?? 0;
     
      // formatting the response
      $finalResponse = [
        'id' => $id,
        'title' => $recipe['title'],
        'itemDescription' => $recipe['short_description'] ?? ($aiData['short_description'] ?? null),
        'imageUrl' => $recipeImage,
        'calorie' => $recipe['nutrition']['calories_kcal'] ?? ($aiData['nutrition']['calories_kcal'] ?? 0),
        'cookingTime' => $recipe['estimated_cook_time'] ?? ($aiData['estimated_cook_time'] ?? '25-30 mins'),
        'ingredientCount' => $recipe['ingredient_count'] ?? ($aiData['ingredient_count'] ?? 0),
        "costComparision" => [
          "takeOut" => number_format($takeOutPrice, 2, '.', ''),
          "homeCookCost" => number_format($recipe['estimated_home_cost_usd'] ??  (float)($aiData['estimated_home_cost_usd'] ?? 0), 2, '.', '') ?? 0,
        ],
        'calorie_vs_takeout' => $recipe['nutrition_gain']['calories_vs_takeout_percent'] ?? ($aiData['nutrition_gain']['calories_vs_takeout_percent'] ?? 0),
        'protein_content' => $recipe['nutrition_gain']['protein_vs_takeout_percent'] ?? ($aiData['nutrition_gain']['protein_vs_takeout_percent'] ?? 0),
        'sodium_levels' => $recipe['nutrition_gain']['sodium_vs_takeout_percent'] ?? ($aiData['nutrition_gain']['sodium_vs_takeout_percent'] ?? 0),
        'ingredients' => $recipe['ingredients'] ?? ($aiData['ingredients'] ?? []),
        'appliances' => $recipe['required_appliances'] ?? ($aiData['required_appliances'] ?? []),
        'isCookBook' => $isCookBook,
      ];

      if ($isSteps) {
        $finalResponse = [...$finalResponse, 'steps' => $recipe['steps'] ?? ($aiData['steps'] ?? [])];
      }

      return $finalResponse;
    } catch (Exception $e) {
      $message = "An error occurred: " . $e->getMessage();
      AppLogger::error($message);
      throw new Exception($message);
    }
  }

  /**
   * To get recipe details by id
   * @param mixed $id
   * @route GET /pantry/meal/recipe?id=
   */
  public function getRecipeDetails(Request $request)
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

      $finalResponse = $this->getMenuDetails($id, $userId);

      if (empty($finalResponse)) {
        return ApiResponse::success("No recipe found");
      }

      return ApiResponse::success("Recipe details found", $finalResponse);
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
   * To upload menu image from node js script
   * @param Request $request
   * @unprotected
   * @route POST menu/image/upload
   */
  public function uploadMenuImage(Request $request)
  {
    try {
      // validating request has file or not
      if (!$request->hasFile('menuImage')) {
        return ApiResponse::error("No image found", 400);
      }

      // validation for file format
      $request->validate([
        'menuImage' => 'image|mimes:jpeg,png,jpg,webp|max:2048',
      ]);
      $file = $request->file('menuImage');
      // if file is not corrupted
      if ($file->isValid()) {
        // uploading
        $path = FileUploadHelper::upload($file, 'ai-images/suggested-meals');
        // checking path has value or not
        if (!$path) {
          return ApiResponse::error("Failed to upload menu image", 422);
        }

        return ApiResponse::success("Menu image uploaded successfully", ['path' => $path]);
      } else {
        return ApiResponse::error('Invalid image upload.', 400);
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
}

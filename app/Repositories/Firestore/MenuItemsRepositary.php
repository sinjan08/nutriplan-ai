<?php

namespace App\Repositories\Firestore;

use App\Services\AppLogger;
use App\Services\Firebase\FirestoreService;
use App\Repositories\Firestore\CookBookRepositary;
use Exception;

class MenuItemsRepositary
{
  protected $client;
  protected $collection = 'menu_items';

  public function __construct(FirestoreService $client)
  {
    $this->client = $client;
  }

  /**
   * Update menu item
   * @param string $id
   * @param array $data
   * @throws Exception
   * @return bool
   */
  public function update(string $id, array $data)
  {
    try {

      if (empty($data)) {
        throw new Exception("No fields provided for update.");
      }

      $data['updatedAt'] = now()->toDateTimeString();

      $response = $this->client->update($this->collection, $id, $data);

      // If Firestore returns error structure
      if (isset($response['error'])) {
        throw new Exception($response['error']['message'] ?? 'Firestore update failed.');
      }

      // Return updated document
      return true;
    } catch (Exception $e) {

      AppLogger::error("UserRepository@update failed: " . $e->getMessage());
      throw new Exception("Unable to update user: " . $e->getMessage());
    }
  }


  /**
   * To search by name in menu items
   * @param string $keyword
   * @param int $limit
   * @throws Exception
   * @return array
   */
  public function searchMenuByName(string $keyword, int $limit = 20, int $page = 1, int $perPage = 10): array
  {
    try {

      $keyword = strtolower(trim($keyword));

      if (strlen($keyword) < 2) {
        return [];
      }

      $tokens = preg_split('/\s+/', $keyword);
      $tokens = array_filter($tokens, function ($word) {
        return strlen($word) > 2;
      });

      if (empty($tokens)) {
        return [];
      }

      $primaryToken = array_shift($tokens);

      $structuredQuery = [
        "from" => [
          ["collectionId" => "menu_items"]
        ],
        "where" => [
          "fieldFilter" => [
            "field" => ["fieldPath" => "searchTokens"],
            "op" => "ARRAY_CONTAINS",
            "value" => ["stringValue" => $primaryToken]
          ]
        ],
        "limit" => 200
      ];

      $results = $this->client->runQuery($structuredQuery);

      if (!is_array($results)) {
        return [];
      }

      // 🔥 True ILIKE '%keyword%' simulation
      $filtered = array_filter($results, function ($item) use ($keyword) {

        $title = $item['titleLower'] ?? '';

        return stripos($title, $keyword) !== false;
      });

      $filtered = array_values($filtered);

      $totalRecords = count($filtered);
      $totalPages = $totalRecords > 0 ? ceil($totalRecords / $perPage) : 0;

      $offset = ($page - 1) * $perPage;
      $paginatedData = array_slice($filtered, $offset, $perPage);

      // 🔥 Required response formatting
      $formatted = array_map(function ($item) {

        $priceRaw = isset($item['price']) ? (float) $item['price'] : 0;
        $price = number_format($priceRaw / 100, 2, '.', '');

        return [
          "id" => $item['uuid'] ?? null,
          "restaurant_id" => $item['restaurant_id'] ?? null,
          "title" => $item['title'] ?? "",
          "restaurantName" => $item['restaurantName'] ?? "",
          "price" => $price,
          "imageUrl" => $item['imageUrl'] ?? getPlaceholderImage(),
          "calorie" => $item['calorie'] ?? 250,
          "cookingTime" => $item['cookingTime'] ?? "25-30 mins",
          "ingredientCount" => $item['ingredientCount'] ?? 15,
          "itemDescription" => $item['itemDescription'] ?? null,
          "costComparision" => [
            "takeOut" => $price,
            "homeCookCost" => "5.90"
          ]
        ];
      }, $paginatedData);

      return [
        "current_page" => $page,
        "per_page" => $perPage,
        "total_records" => $totalRecords,
        "total_pages" => $totalPages,
        "menus" => $formatted
      ];
    } catch (Exception $e) {
      AppLogger::error("MenuRepository@searchMenuByName failed: " . $e->getMessage());
      throw new Exception("Unable to search menu.");
    }
  }


  /**
   * To fetch menu details by id
   * @param mixed $id
   * @throws Exception
   * @return array{id: mixed|null}
   */
  public function findById($id)
  {
    try {
      // fetching from firbase
      $doc = $this->client->get($this->collection, $id);
      // checking data recieved or not
      if (!isset($doc['fields'])) {
        return [];
      }
      // finaly returning 
      return [
        'id' => $id,
        ...$this->client->decodeFields($doc['fields']),
      ];
    } catch (Exception $e) {
      AppLogger::error("MenuItemsRepositary@findById failed: " . $e->getMessage());
      throw new Exception("Unable to fetch menu details: " . $e->getMessage());
    }
  }

  /**
   * To search menu globally in firestore db by menu name only
   * @param mixed $restaurant_id
   * @param mixed $page
   * @param mixed $perPage
   * @throws Exception
   * @return array|array{current_page: int, menus: array, per_page: int, total_pages: float|int, total_records: int}
   */
  public function getMenuByRestaurantGlobally($restaurant_id, $page = 1, $perPage = 10)
  {
    try {

      $page = max(1, (int) $page);
      $perPage = max(1, (int) $perPage);
      $offset = ($page - 1) * $perPage;

      // Total count query (no offset, no limit)
      $countQuery = [
        "from" => [
          ["collectionId" => "menu_items"]
        ],
        "where" => [
          "fieldFilter" => [
            "field" => ["fieldPath" => "restaurant_id"],
            "op" => "EQUAL",
            "value" => ["stringValue" => (string) $restaurant_id]
          ]
        ]
      ];

      $countResults = $this->client->runQuery($countQuery);
      $totalRecords = is_array($countResults) ? count($countResults) : 0;

      // Your original paginated query (unchanged)
      $structuredQuery = [
        "from" => [
          ["collectionId" => "menu_items"]
        ],
        "where" => [
          "fieldFilter" => [
            "field" => ["fieldPath" => "restaurant_id"],
            "op" => "EQUAL",
            "value" => ["stringValue" => (string) $restaurant_id]
          ]
        ],
        "offset" => $offset,
        "limit" => $perPage
      ];

      $results = $this->client->runQuery($structuredQuery);

      if (!is_array($results)) {
        return [];
      }

      $formatted = array_map(function ($item) {

        $price = isset($item['price']) ? (float)$item['price'] : 0;

        return [
          "id" => $item['uuid'] ?? null,
          "restaurant_id" => $item['restaurant_id'] ?? null,
          "title" => $item['title'] ?? "",
          "restaurantName" => $item['restaurantName'] ?? "",
          "price" => number_format($price / 100, 2, '.', ''),
          "imageUrl" => $item['imageUrl'] ?? getPlaceholderImage(),
          "calorie" => $item['calorie'] ?? 250,
          "cookingTime" => $item['cookingTime'] ?? "25-30 mins",
          "ingredientCount" => $item['ingredientCount'] ?? 15,
          "itemDescription" => $item['itemDescription'] ?? null,
          "costComparision" => [
            "takeOut" => number_format($price / 100, 2, '.', ''),
            "homeCookCost" => number_format(5.90, 2, '.', ''),
          ]
        ];
      }, $results);

      return [
        "current_page" => $page,
        "per_page" => $perPage,
        "total_records" => $totalRecords,
        "total_pages" => $totalRecords > 0 ? ceil($totalRecords / $perPage) : 0,
        "menus" => $formatted
      ];
    } catch (Exception $e) {
      AppLogger::error("MenuItemsRepositary@getMenuByRestaurant failed: " . $e->getMessage());
      throw new Exception("Unable to fetch restaurant menu: " . $e->getMessage());
    }
  }

  /**
   * To find menu by restaurant if search parameter is passed then also done searching by menu name
   * @param mixed $restaurant_id
   * @param mixed $cookBookResults
   * @param mixed $page
   * @param mixed $perPage
   * @param mixed $search
   * @throws Exception
   * @return array{current_page: int, has_more: bool, menus: array, per_page: int, total_pages: null, total_records: null|array{current_page: int, menus: array, per_page: int, total_pages: int, total_records: int}}
   */
  public function getMenuByRestaurant($restaurant_id, $cookBookResults, $page = 1, $perPage = 10, $search = null)
  {
    try {

      $page = max(1, (int) $page);
      $perPage = max(1, (int) $perPage);
      $offset = ($page - 1) * $perPage;

      $search = $search ? strtolower(trim($search)) : null;

      /*
    |--------------------------------------------------------------------------
    | Build WHERE Filters
    |--------------------------------------------------------------------------
    */
      $whereFilters = [
        [
          "fieldFilter" => [
            "field" => ["fieldPath" => "restaurant_id"],
            "op" => "EQUAL",
            "value" => ["stringValue" => (string) $restaurant_id]
          ]
        ]
      ];

      if ($search) {
        $whereFilters[] = [
          "fieldFilter" => [
            "field" => ["fieldPath" => "searchTokens"],
            "op" => "ARRAY_CONTAINS",
            "value" => ["stringValue" => $search]
          ]
        ];
      }

      /*
    |--------------------------------------------------------------------------
    | PAGINATED QUERY ONLY (NO FULL COUNT SCAN)
    |--------------------------------------------------------------------------
    */
      $structuredQuery = [
        "from" => [
          ["collectionId" => "menu_items"]
        ],
        "where" => [
          "compositeFilter" => [
            "op" => "AND",
            "filters" => $whereFilters
          ]
        ],
        "offset" => $offset,
        "limit" => $perPage
      ];

      $results = $this->client->runQuery($structuredQuery);

      $countQuery = [
        "from" => [
          ["collectionId" => "menu_items"]
        ],
        "where" => [
          "compositeFilter" => [
            "op" => "AND",
            "filters" => $whereFilters
          ]
        ]
      ];

      $countResults = $this->client->runQuery($countQuery);
      $totalRecords = is_array($countResults) ? count($countResults) : 0;
      $totalPages = $totalRecords ? ceil($totalRecords / $perPage) : 0;

      if (!is_array($results)) {
        return [
          "current_page" => $page,
          "per_page" => $perPage,
          "total_records" => $totalRecords,
          "total_pages" => $totalPages,
          "menus" => []
        ];
      }

      /*
    |--------------------------------------------------------------------------
    | FETCH COOKBOOK ITEMS ONCE (NO N+1)
    |--------------------------------------------------------------------------
    */

      $cookBookMenuIds = [];

      if (!empty($cookBookResults)) {
        foreach ($cookBookResults as $cb) {
          if (!empty($cb['menu_id'])) {
            $cookBookMenuIds[$cb['menu_id']] = true;
          }
        }
      }

      /*
    |--------------------------------------------------------------------------
    | FORMAT RESPONSE
    |--------------------------------------------------------------------------
    */
      $formatted = array_map(function ($item) use ($cookBookMenuIds) {

        $price = isset($item['price']) ? (float)$item['price'] : 0;
        $menuId = $item['uuid'] ?? null;
        $homeCookCost = $item['estimated_home_cost_usd'] ?? 0;

        // getting public url or placeholder for the recipe
        $recipeImage = getPlaceholderImage(true);
        if (isset($item['image']) && $item['image']) {
          $recipeImage = publicStorageUrl($item['image']);
        } else if (isset($item['imageUrl']) && $item['imageUrl']) {
          $recipeImage = publicStorageUrl($item['image']);
        }

        return [
          "id" => $menuId,
          "restaurant_id" => $item['restaurant_id'] ?? null,
          "title" => $item['title'] ?? "",
          "restaurantName" => $item['restaurantName'] ?? "",
          "price" => number_format($price / 100, 2, '.', ''),
          "imageUrl" => $recipeImage,
          "calorie" => $item['nutrition']['calories_kcal'] ?? 0,
          "cookingTime" => $item['estimated_cook_time'] ?? "0 mins",
          "ingredientCount" => $item['ingredient_count'] ?? 0,
          "itemDescription" => $item['short_description'] ?? null,
          "costComparision" => [
            "takeOut" => number_format($price / 100, 2, '.', ''),
            "homeCookCost" => number_format($homeCookCost, 2, '.', ''),
          ],
          "isCookBook" => isset($cookBookMenuIds[$menuId]),
        ];
      }, $results);

      /*
    |--------------------------------------------------------------------------
    | Smart Pagination (Without Full Count Scan)
    |--------------------------------------------------------------------------
    */
      $hasMore = count($formatted) === $perPage;

      return [
        "current_page" => $page,
        "per_page" => $perPage,
        "total_records" => $totalRecords, // removed expensive count
        "total_pages" => $totalPages,   // removed expensive count
        "has_more" => $hasMore,
        "menus" => $formatted
      ];
    } catch (Exception $e) {

      AppLogger::error("MenuItemsRepositary@getMenuByRestaurant failed: " . $e->getMessage());
      throw new Exception("Unable to fetch restaurant menu: " . $e->getMessage());
    }
  }

  /**
   * To check menu is added in cookbook or not
   * @param mixed $userId
   * @param mixed $menuId
   * @throws Exception
   * @return bool
   */
  public function isCookBookMenu($userId, $menuId)
  {
    try {
      // preparing query to check 
      $query = $this->client->buildQuery(
        'cook_book',
        [
          ['field' => 'user_id', 'op' => 'EQUAL', 'value' => $userId],
          ['field' => 'menu_id', 'op' => 'EQUAL', 'value' => $menuId],
        ],
        1
      );
      // fetching 
      $results = $this->client->runQuery($query);

      // Proper existence check
      if (!empty($results) && isset($results[0]['id'])) {
        return true;
      } else {
        return false;
      }
    } catch (Exception $e) {
      AppLogger::error("MenuItemsRepositary@isCookBookMenu failed: " . $e->getMessage());
      throw new Exception("Unable to check is it available in cookbok or not: " . $e->getMessage());
    }
  }

  public function getMenuByMultipleIds(array $ids): array
  {
    try {

      if (empty($ids)) {
        return [];
      }

      // Firestore IN limit = 10
      $chunks = array_chunk($ids, 10);

      $finalResults = [];

      foreach ($chunks as $chunk) {

        $structuredQuery = [
          'from' => [
            ['collectionId' => $this->collection]
          ],
          'where' => [
            'fieldFilter' => [
              'field' => ['fieldPath' => 'id'], // querying normal field
              'op'    => 'IN',
              'value' => [
                'arrayValue' => [
                  'values' => array_map(function ($id) {
                    return ['stringValue' => (string) $id];
                  }, $chunk)
                ]
              ]
            ]
          ]
        ];

        $results = $this->client->runQuery($structuredQuery);

        if (!empty($results)) {
          $finalResults = array_merge($finalResults, $results);
        }
      }

      return $finalResults;
    } catch (Exception $e) {
      AppLogger::error("MenuItemsRepositary@getMenuByMultipleIds failed: " . $e->getMessage());
      throw new Exception("Unable to fetch menu: " . $e->getMessage());
    }
  }

  public function searchByTokens($token)
  {
    try {
      $structuredQuery = [
        "from" => [
          ["collectionId" => "menu_items"]
        ],
        "where" => [
          "fieldFilter" => [
            "field" => ["fieldPath" => "searchTokens"],
            "op" => "ARRAY_CONTAINS",
            "value" => ["stringValue" => $token]
          ]
        ],
        "limit" => 20 // 🔥 small batch only
      ];

      $results = $this->client->runQuery($structuredQuery);

      return $results ?? [];
    } catch (Exception $e) {
      AppLogger::error("MenuItemsRepositary@searchByTokens failed: " . $e->getMessage());
      throw new Exception("Unable to fetch menu: " . $e->getMessage());
    }
  }


  public function getMenuItemsByLimit($limit, $lastDoc)
  {
    try {
      $structuredQuery = [
        "from" => [
          ["collectionId" => "menu_items"]
        ],
        "limit" => $limit
      ];

      // pagination cursor
      if ($lastDoc) {
        $structuredQuery["startAfter"] = $lastDoc;
      }

      $results = $this->client->runQuery($structuredQuery);

      return $results ?? [];
    } catch (Exception $e) {
      AppLogger::error("MenuItemsRepositary@getMenuItemsByLimit failed: " . $e->getMessage());
      throw new Exception("Unable to fetch menu: " . $e->getMessage());
    }
  }
}

<?php

namespace App\Repositories\Firestore;

use App\Services\AppLogger;
use App\Services\Firebase\FirestoreService;
use Exception;
use Carbon\Carbon;

class NotificationRepository
{
  protected $client;
  protected $collection = 'notifications';

  public function __construct(FirestoreService $client)
  {
    $this->client = $client;
  }

  /**
   * To create new record
   * @param mixed $userId
   * @param mixed $menuId
   * @throws Exception
   * @return array{id: string|null}
   */
  public function create($data)
  {
    try {
      $createData = [
        ...$data,
        'createdAt' => Carbon::now(),
        'updatedAt' => null,
      ];

      $response = $this->client->create($this->collection, $createData);

      $id = $response['name'] ?? null;
      $id = $id ? basename($id) : null;

      return [
        'id' => $id,
      ];
    } catch (Exception $e) {
      AppLogger::error("CookBookRepositary@findById failed: " . $e->getMessage());
      throw new Exception("Unable to fetch Cook Book details: " . $e->getMessage());
    }
  }

  public function bulkCreate(array $items)
  {
    try {
      $now = Carbon::now()->toISOString();

      $documents = array_map(function ($item) use ($now) {
        return [
          ...$item,
          'createdAt' => $now,
          'updatedAt' => null,
        ];
      }, $items);

      return $this->client->batchCreate($this->collection, $documents);
    } catch (Exception $e) {
      AppLogger::error("CartRepository@bulkCreate failed: " . $e->getMessage());
      throw new Exception("Unable to create cart items");
    }
  }

  /**
   * to delete record permanenty
   * @param mixed $docId
   * @throws Exception
   * @return array{deleted: bool, id: mixed}
   */
  public function hardDelete($docId)
  {
    try {

      $this->client->delete($this->collection, $docId);

      return [
        'deleted' => true,
        'id' => $docId
      ];
    } catch (Exception $e) {
      AppLogger::error("CookBookRepository@hardDelete failed: " . $e->getMessage());
      throw new Exception("Unable to delete cook book: " . $e->getMessage());
    }
  }


  /**
   * To fetch details by id
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
      AppLogger::error("CookBookRepositary@findById failed: " . $e->getMessage());
      throw new Exception("Unable to fetch cookbook details: " . $e->getMessage());
    }
  }

  /**
   * To fetch all pantry items by user id
   * @param mixed $userId
   * @throws Exception
   * @return array
   */
  public function getNotificationUsers($limit, $lastDoc = null)
  {
    try {

      $structuredQuery = [
        "from" => [
          ["collectionId" => "users"]
        ],
        "where" => [
          "compositeFilter" => [
            "op" => "AND",
            "filters" => [
              [
                "fieldFilter" => [
                  "field" => ["fieldPath" => "notificationStatus"],
                  "op" => "EQUAL",
                  "value" => ["booleanValue" => true]
                ]
              ]
            ]
          ]
        ],
        "orderBy" => [
          [
            "field" => ["fieldPath" => "__name__"],
            "direction" => "ASCENDING"
          ]
        ],
        "limit" => $limit
      ];

      $results = $this->client->runQuery($structuredQuery);

      if (!is_array($results) || empty($results)) {
        AppLogger::error("No users found by the query");
        return [];
      }

      // STEP 2: cursor filtering
      $filtered = array_filter($results, function ($user) use ($lastDoc) {
        if (!$lastDoc) return true;
        return $user['id'] > $lastDoc;
      });

      return array_values($filtered);
    } catch (Exception $e) {
      AppLogger::error("Repository error: " . $e->getMessage());
      throw $e;
    }
  }


  public function wasSentRecently($userId, $triggerId, $hours = null)
  {
    try {
      if (!$hours) return false;

      // Step 1: fetch by indexed fields only
      $structuredQuery = [
        "from" => [
          ["collectionId" => $this->collection]
        ],
        "where" => [
          "compositeFilter" => [
            "op" => "AND",
            "filters" => [
              [
                "fieldFilter" => [
                  "field" => ["fieldPath" => "user_id"],
                  "op" => "EQUAL",
                  "value" => ["stringValue" => $userId]
                ]
              ],
              [
                "fieldFilter" => [
                  "field" => ["fieldPath" => "trigger_id"],
                  "op" => "EQUAL",
                  "value" => ["stringValue" => $triggerId]
                ]
              ]
            ]
          ]
        ]
      ];

      $results = $this->client->runQuery($structuredQuery);

      if (empty($results)) return false;

      // Step 2: filter in PHP
      $cutoff = now()->subHours($hours);

      foreach ($results as $item) {
        if (empty($item['sent_at'])) continue;

        try {
          $sentTime = Carbon::parse($item['sent_at']);

          if ($sentTime->gte($cutoff)) {
            return true;
          }
        } catch (Exception $e) {
          // ignore bad date
          continue;
        }
      }

      return false;
    } catch (Exception $e) {
      AppLogger::error("NotificationRepository@wasSentRecently failed: " . $e->getMessage());
      return false;
    }
  }
}

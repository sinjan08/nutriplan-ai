<?php

namespace App\Repositories\Firestore;

use App\Services\AppLogger;
use App\Services\Firebase\FirestoreService;
use Exception;
use Carbon\Carbon;

class OrderRepositary
{
  protected $client;
  protected $collection = 'orders';

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
  public function getOrdersByUser($userId)
  {
    try {

      if (empty($userId)) {
        throw new Exception("User ID is required.");
      }

      $query = $this->client->buildQuery(
        $this->collection,
        [
          ['field' => 'user_id', 'op' => 'EQUAL', 'value' => $userId],
        ]
      );

      $results = $this->client->runQuery($query);

      return is_array($results) ? $results : [];
    } catch (Exception $e) {
      $message = "An error occurred at repositary: " . $e->getMessage();
      AppLogger::error($message);
      throw new Exception($message);
    }
  }
}

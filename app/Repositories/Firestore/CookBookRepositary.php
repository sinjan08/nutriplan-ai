<?php

namespace App\Repositories\Firestore;

use App\Services\AppLogger;
use App\Services\Firebase\FirestoreService;
use Exception;

class CookBookRepositary
{
  protected $client;
  protected $collection = 'cook_book';

  public function __construct(FirestoreService $client)
  {
    $this->client = $client;
  }

  /**
   * To check if menu is exist or not in cookbook
   * if exist then removing else inserting
   * @param mixed $userId
   * @param mixed $menuId
   * @throws Exception
   * @return array{deleted: bool, id: mixed|array{id: string|null}|null}
   */
  public function delsert($userId, $menuId)
  {
    try {
      AppLogger::debug("Arguments received: userId = {$userId}, menuId = {$menuId}");

      if (!$userId || !$menuId) {
        AppLogger::error("Required parameter missing");
        return null;
      }

      $query = $this->client->buildQuery(
        $this->collection,
        [
          ['field' => 'user_id', 'op' => 'EQUAL', 'value' => $userId],
          ['field' => 'menu_id', 'op' => 'EQUAL', 'value' => $menuId],
        ],
        1
      );

      $results = $this->client->runQuery($query);

      // ✅ Proper existence check
      if (!empty($results) && isset($results[0]['id'])) {
        $docId = $results[0]['id'];
        return $this->hardDelete($docId);
      } else {

        return $this->create($userId, $menuId);
      }
    } catch (Exception $e) {
      AppLogger::error("CookBookRepository@upsert failed: " . $e->getMessage());
      throw new Exception("Unable to upsert cook book: " . $e->getMessage());
    }
  }

  /**
   * To create new record
   * @param mixed $userId
   * @param mixed $menuId
   * @throws Exception
   * @return array{id: string|null}
   */
  public function create($userId, $menuId)
  {
    try {
      $createData = [
        'user_id' => $userId,
        'menu_id' => $menuId,
        'createdAt' => now()->toDateTimeString(),
        'updatedAt' => null,
      ];

      $response = $this->client->create($this->collection, $createData);

      $id = $response['name'] ?? null;
      $id = $id ? basename($id) : null;

      return [
        'id' => $id,
        'deleted' => false,
      ];
    } catch (Exception $e) {
      AppLogger::error("CookBookRepositary@findById failed: " . $e->getMessage());
      throw new Exception("Unable to fetch Cook Book details: " . $e->getMessage());
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


  public function getCookBookByUser($userId)
  {
    try {

      if (empty($userId)) {
        throw new Exception("User ID is required.");
      }

      $cookBookQuery = $this->client->buildQuery(
        $this->collection,
        [
          ['field' => 'user_id', 'op' => 'EQUAL', 'value' => $userId],
        ]
      );

      $cookBookResults = $this->client->runQuery($cookBookQuery);

      return is_array($cookBookResults) ? $cookBookResults : [];
    } catch (Exception $e) {
      AppLogger::error("CookBookRepositary@getCookBookByUser failed: " . $e->getMessage());
      throw new Exception("Unable to fetch cookbook details: " . $e->getMessage());
    }
  }

  /**
   * To check wheteher menu is added in cookbook for the user or not
   * @param mixed $userId
   * @param mixed $menuId
   * @throws Exception
   * @return bool
   */
  public function isCookBookMenu($userId, $menuId)
  {
    try {

      $cookBookQuery = $this->client->buildQuery(
        $this->collection,
        [
          ['field' => 'user_id', 'op' => 'EQUAL', 'value' => $userId],
          ['field' => 'menu_id', 'op' => 'EQUAL', 'value' => $menuId],
        ]
      );

      $cookBookResults = $this->client->runQuery($cookBookQuery);

      return !empty($cookBookResults);
    } catch (Exception $e) {

      AppLogger::error("CookBookRepositary@isCookBookMenu failed: " . $e->getMessage());

      throw new Exception("Unable to check cookbook menu: " . $e->getMessage());
    }
  }
}

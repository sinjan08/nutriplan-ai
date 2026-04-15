<?php

namespace App\Repositories\Firestore;

use App\Services\AppLogger;
use App\Services\Firebase\FirestoreService;
use Exception;

class PreferenceRepository
{
  protected $client;
  protected $collection = 'user_preference';

  public function __construct(FirestoreService $client)
  {
    $this->client = $client;
  }

  public function create(array $data)
  {
    try {

      $preferenceData = [
        ...$data,
        'createdAt'  => now()->toDateTimeString(),
        'updatedAt'  => null,
      ];

      $response = $this->client->create($this->collection, $preferenceData);

      $id = $response['name'] ?? null;
      $id = $id ? basename($id) : null;

      if (!$id) {
        throw new Exception("Failed to generate document ID.");
      }

      // ✅ Fetch full document after creation
      $doc = $this->client->get($this->collection, $id);

      if (!isset($doc['fields'])) {
        throw new Exception("Failed to retrieve created document.");
      }

      return [
        'id' => $id,
        ...$this->client->decodeFields($doc['fields']),
      ];
    } catch (Exception $e) {
      AppLogger::error("Error occurred: " . $e->getMessage());
      throw new Exception("Unable to save user preference: " . $e->getMessage());
    }
  }

  public function update(string $id, array $data): bool
  {
    try {

      if (empty($data)) {
        return false;
      }

      $data['updatedAt'] = now()->toDateTimeString();

      $this->client->update(
        $this->collection,
        $id,
        $data
      );

      return true;
    } catch (Exception $e) {
      AppLogger::error("PreferenceRepository@update failed: " . $e->getMessage());
      throw new Exception("Unable to update preference.");
    }
  }

  public function finPreferenceByUser(string $user_id)
  {
    try {
      if (!$user_id) {
        AppLogger::error("User id not found");
        throw new Exception("User id not found");
      }

      // building the query to fetch preference by user id
      $query = $this->client->buildQuery($this->collection, [
        ['field' => 'user_id', 'op' => 'EQUAL', 'value' => $user_id]
      ], 1);

      // run query to fetch
      $results = $this->client->runQuery($query);
      // getting final doc
      foreach ($results as $doc) {
        return $doc;
      }

      return null;
    } catch (Exception $e) {
      AppLogger::error("Error occurred: " . $e->getMessage());
      throw new Exception("Unable to find user preference: " . $e->getMessage());
    }
  }

  public function findById(string $id): ?array
  {
    try {
      $doc = $this->client->get($this->collection, $id);

      if (!isset($doc['fields'])) {
        return null;
      }

      return [
        'id' => $id,
        ...$this->client->decodeFields($doc['fields']),
      ];
    } catch (Exception $e) {
      AppLogger::error("Error occurred: " . $e->getMessage());
      throw new Exception("Unable to find user preference: " . $e->getMessage());
    }
  }
}

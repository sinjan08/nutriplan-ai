<?php

namespace App\Repositories\Firestore;

use App\Services\AppLogger;
use App\Services\Firebase\FirestoreService;
use Exception;

class AvatarRepository
{
  protected $client;
  protected $collection = 'avatar';

  public function __construct(FirestoreService $client)
  {
    $this->client = $client;
  }

  public function create(array $data): array
  {
    try {

      $data['createdAt'] = now()->toDateTimeString();
      $data['updatedAt'] = null;

      $response = $this->client->create($this->collection, $data);

      $id = $response['name'] ?? null;
      $id = $id ? basename($id) : null;

      if (!$id) {
        throw new Exception("Failed to generate document ID.");
      }

      return $this->findById($id);
    } catch (Exception $e) {
      AppLogger::error("AvatarRepository@create failed: " . $e->getMessage());
      throw new Exception("Unable to save avatar.");
    }
  }

  public function update(string $id, array $data): array
  {
    try {

      if (empty($data)) {
        return $this->findById($id);
      }

      $data['updatedAt'] = now()->toDateTimeString();

      $this->client->update($this->collection, $id, $data);

      return $this->findById($id);
    } catch (Exception $e) {
      AppLogger::error("AvatarRepository@update failed: " . $e->getMessage());
      throw new Exception("Unable to update avatar.");
    }
  }

  public function getAll(): array
  {
    try {

      $query = $this->client->buildQuery(
        $this->collection,
        [
          ['field' => 'is_active', 'op' => 'EQUAL', 'value' => true]
        ]
      );

      $avatars = $this->client->runQuery($query);

      if (empty($avatars)) {
        return [];
      }

      return array_map(function ($avatar) {
        $avatar['path'] = publicStorageUrl($avatar['path']);
        return $avatar;
      }, $avatars);
    } catch (Exception $e) {
      AppLogger::error("AvatarRepository@getAll failed: " . $e->getMessage());
      throw new Exception("Unable to fetch avatars.");
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
      AppLogger::error("AvatarRepository@findById failed: " . $e->getMessage());
      throw new Exception("Unable to find avatar.");
    }
  }
}

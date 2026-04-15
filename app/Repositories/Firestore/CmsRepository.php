<?php

namespace App\Repositories\Firestore;

use App\Services\AppLogger;
use App\Services\Firebase\FirestoreService;
use Exception;

class CmsRepository
{
  protected $client;
  protected $collection = 'cms';

  public function __construct(FirestoreService $client)
  {
    $this->client = $client;
  }

  /**
   * Find CMS by document ID (key)
   * Example: privacy, about, terms
   */
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
      AppLogger::error("CmsRepository@findById failed: " . $e->getMessage());
      throw new Exception("Unable to fetch CMS content.");
    }
  }

  /**
   * Create CMS document using key as ID
   */
  public function createWithId(string $id, array $data): array
  {
    try {

      $payload = [
        'title'     => $data['title'] ?? null,
        'content'   => $data['content'] ?? null,
        'createdAt' => $data['createdAt'] ?? now()->toDateTimeString(),
        'updatedAt' => null,
      ];

      // Use update to create document with specific ID
      $this->client->update($this->collection, $id, $payload);

      AppLogger::info("CMS created with ID: {$id}");

      return $this->findById($id);
    } catch (Exception $e) {
      AppLogger::error("CmsRepository@createWithId failed: " . $e->getMessage());
      throw new Exception("Unable to create CMS content.");
    }
  }

  /**
   * Update CMS document
   */
  public function update(string $id, array $data): array
  {
    try {

      if (empty($data)) {
        throw new Exception("No fields provided for update.");
      }

      $data['updatedAt'] = now()->toDateTimeString();

      $response = $this->client->update(
        $this->collection,
        $id,
        $data
      );

      if (isset($response['error'])) {
        throw new Exception($response['error']['message'] ?? 'Firestore update failed.');
      }

      AppLogger::info("CMS updated for ID: {$id}");

      return $this->findById($id);
    } catch (Exception $e) {
      AppLogger::error("CmsRepository@update failed: " . $e->getMessage());
      throw new Exception("Unable to update CMS content.");
    }
  }

  /**
   * Hard delete CMS document
   */
  public function deleteById(string $id): bool
  {
    try {

      $this->client->delete($this->collection, $id);

      AppLogger::info("CMS hard deleted for ID: {$id}");

      return true;
    } catch (Exception $e) {
      AppLogger::error("CmsRepository@deleteById failed: " . $e->getMessage());
      throw new Exception("Unable to delete CMS content.");
    }
  }
}

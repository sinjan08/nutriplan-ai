<?php

namespace App\Repositories\Firestore;

use Illuminate\Support\Facades\Hash;
use App\Services\AppLogger;
use App\Services\Firebase\FirestoreService;
use Exception;

class UserRepository
{
  protected $client;
  protected $collection = 'users';

  public function __construct(FirestoreService $client)
  {
    $this->client = $client;
  }

  /**
   * Find user by ID
   */
  public function find(string $id): ?array
  {
    try {

      $response = $this->client->get($this->collection, $id);

      if (!isset($response['fields'])) {
        return null;
      }

      $data = [
        'id' => $id,
        ...$this->client->decodeFields($response['fields']),
      ];

      return $this->sanitize($data);
    } catch (Exception $e) {

      AppLogger::error("UserRepository@find failed: " . $e->getMessage());
      throw new Exception("Unable to fetch user.");
    }
  }

  /**
   * Find user by email
   */
  public function findByEmail(string $email): ?array
  {
    try {

      $query = $this->client->buildQuery(
        $this->collection,
        [
          ['field' => 'email', 'op' => 'EQUAL', 'value' => strtolower($email)],
        ],
        1
      );

      $results = $this->client->runQuery($query);

      foreach ($results as $doc) {
        return $doc;
      }

      return null;
    } catch (Exception $e) {

      AppLogger::error("UserRepository@findByEmail failed: " . $e->getMessage());
      throw new Exception("Unable to fetch user.");
    }
  }

  /**
   * To create into firebase
   * @param array $data
   * @throws Exception
   * @return array
   */
  public function create(array $data): array
  {
    try {

      $userData = [
        'name' => $data['name'],
        'email' => strtolower($data['email']),
        'password' => $data['password'],
        'avatar_id' => $data['avatar_id'] ?? null,
        'address' => null,
        'country' => null,
        'zipCode' => null,
        'lat' => null,
        'long' => null,
        'social_id' => $data['social_id'] ?? null,
        'notificationStatus' => true,
        'profileImage' => null,
        'deviceType' => $data['deviceType'] ?? null,
        'fcm' => $data['fcm'] ?? null,
        'isAvatar' => $data['isAvatar'] ?? true,
        'isVerified' => true,
        'isDeleted' => false,
        'createdAt' => now()->toDateTimeString(),
        'updatedAt' => null,
      ];

      $response = $this->client->create($this->collection, $userData);

      $id = $response['name'] ?? null;
      $id = $id ? basename($id) : null;

      return $this->sanitize([
        'id' => $id,
        ...$userData
      ]);
    } catch (Exception $e) {

      AppLogger::error("UserRepository@create failed: " . $e->getMessage());
      throw new Exception("Unable to create user.");
    }
  }

  /**
   * Update user
   */
  public function update(string $id, array $data): array
  {
    try {

      if (empty($data)) {
        throw new Exception("No fields provided for update.");
      }

      if (isset($data['password'])) {
        $data['password'] = Hash::make($data['password']);
      }

      $data['updatedAt'] = now()->toDateTimeString();

      AppLogger::debug("Updating user {$id} with payload: " . json_encode($data));

      $response = $this->client->update($this->collection, $id, $data);

      AppLogger::debug("Firestore update response: " . json_encode($response));

      // If Firestore returns error structure
      if (isset($response['error'])) {
        throw new Exception($response['error']['message'] ?? 'Firestore update failed.');
      }

      // Return updated document
      return $this->find($id);
    } catch (Exception $e) {

      AppLogger::error("UserRepository@update failed: " . $e->getMessage());
      throw new Exception("Unable to update user: " . $e->getMessage());
    }
  }


  /**
   * Soft Delete user
   */
  public function delete(string $id): bool
  {
    try {

      $this->client->update($this->collection, $id, [
        'isDeleted' => true,
        'deletedAt' => now()->toDateTimeString(),
      ]);

      return true;
    } catch (Exception $e) {

      AppLogger::error("UserRepository@delete failed: " . $e->getMessage());
      throw new Exception("Unable to delete user.");
    }
  }

  /**
   * Remove sensitive fields
   */
  private function sanitize(array $data): array
  {
    unset($data['password'], $data['isDeleted'], $data['isVerified']);
    return $data;
  }

  public function findByEmailForLogin(string $email): ?array
  {
    try {

      $query = $this->client->buildQuery(
        $this->collection,
        [
          ['field' => 'email', 'op' => 'EQUAL', 'value' => strtolower($email)],
          ['field' => 'isDeleted', 'op' => 'EQUAL', 'value' => false],
        ],
        1
      );

      $results = $this->client->runQuery($query);

      foreach ($results as $doc) {
        return $doc; // return FULL data including password
      }

      return null;
    } catch (Exception $e) {

      AppLogger::error("UserRepository@findByEmailForLogin failed: " . $e->getMessage());
      throw new Exception("Unable to fetch user.");
    }
  }

  public function findByEmailForSocialLogin($email, $socialId = null): ?array
  {
    try {

      $query = $this->client->buildQuery(
        $this->collection,
        [
          //['field' => 'social_id', 'op' => 'EQUAL', 'value' => $socialId],
          ['field' => 'email', 'op' => 'EQUAL', 'value' => $email],
          ['field' => 'isDeleted', 'op' => 'EQUAL', 'value' => false],
        ],
        1
      );

      $results = $this->client->runQuery($query);

      foreach ($results as $doc) {
        return $doc; // return FULL data including password
      }

      return null;
    } catch (Exception $e) {

      AppLogger::error("UserRepository@findByEmailForLogin failed: " . $e->getMessage());
      throw new Exception("Unable to fetch user.");
    }
  }

  /**
   * fetching all user who enabled notification
   * @throws Exception
   * @return array{id: string[]}
   */
  public function getNotificationUsers()
  {
    try {
      $query = $this->client->buildQuery(
        $this->collection,
        [
          ['field' => 'notificationStatus', 'op' => 'EQUAL', 'value' => true],
        ]
      );

      $results = $this->client->runQuery($query);

      return $results;
    } catch (Exception $e) {

      AppLogger::error("UserRepository@findByEmailForLogin failed: " . $e->getMessage());
      throw new Exception("Unable to fetch user.");
    }
  }
}

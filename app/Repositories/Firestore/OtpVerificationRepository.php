<?php

namespace App\Repositories\Firestore;


use App\Services\AppLogger;
use App\Services\Firebase\FirestoreService;
use Exception;

class OtpVerificationRepository
{
  protected $client;
  protected $collection = 'otp_verifications';

  public function __construct(FirestoreService $client)
  {
    $this->client = $client;
  }

  public function create(array $data): array
  {
    try {

      $otpData = [
        'user_id'   => $data['user_id'] ?? null,
        'email'     => strtolower($data['email']),
        'otp'       => $data['otp'],
        'type'      => $data['type'],
        'used'      => false,
        'expiresAt' => now()->addMinutes(10)->toDateTimeString(),
        'createdAt' => now()->toDateTimeString(),
      ];

      $response = $this->client->create($this->collection, $otpData);

      if (!isset($response['name'])) {
        AppLogger::error('Firestore create failed: ' . json_encode($response));
        throw new Exception('Firestore document creation failed.');
      }

      $id = basename($response['name']);


      return [
        'id' => $id,
        ...$otpData
      ];
    } catch (Exception $e) {

      AppLogger::error("OtpVerificationRepository@create failed: " . $e->getMessage());
      throw new Exception("Unable to create OTP record.");
    }
  }

  public function findValidOtp(string $email, string $type): ?array
  {
    try {

      $query = $this->client->buildQuery(
        $this->collection,
        [
          ['field' => 'email', 'op' => 'EQUAL', 'value' => strtolower($email)],
          ['field' => 'type', 'op' => 'EQUAL', 'value' => $type],
          ['field' => 'used', 'op' => 'EQUAL', 'value' => false],
        ],
        1
      );

      $results = $this->client->runQuery($query);

      foreach ($results as $doc) {

        if ($doc['expiresAt'] < now()->toDateTimeString()) {
          return null;
        }

        return $doc;
      }

      return null;
    } catch (Exception $e) {

      AppLogger::error("OtpVerificationRepository@findValidOtp failed: " . $e->getMessage());
      throw new Exception("Unable to verify OTP.");
    }
  }

  public function markAsUsed(string $id): bool
  {
    try {

      $this->client->update($this->collection, $id, [
        'used' => true,
      ]);

      return true;
    } catch (Exception $e) {

      AppLogger::error("OtpVerificationRepository@markAsUsed failed: " . $e->getMessage());
      throw new Exception("Unable to update OTP status.");
    }
  }

  public function deleteByEmail(string $email): void
  {
    try {

      $query = $this->client->buildQuery(
        $this->collection,
        [
          ['field' => 'email', 'op' => 'EQUAL', 'value' => strtolower($email)],
        ]
      );

      $results = $this->client->runQuery($query);

      foreach ($results as $doc) {
        $this->client->delete($this->collection, $doc['id']);
      }
    } catch (Exception $e) {

      AppLogger::error("OtpVerificationRepository@deleteByEmail failed: " . $e->getMessage());
    }
  }

  public function findByResetToken(string $resetToken): ?array
  {
    try {

      $query = $this->client->buildQuery(
        $this->collection,
        [
          ['field' => 'reset_token', 'op' => 'EQUAL', 'value' => $resetToken],
          ['field' => 'used', 'op' => 'EQUAL', 'value' => true], // must be verified OTP
        ],
        1
      );

      $results = $this->client->runQuery($query);

      foreach ($results as $doc) {

        // Check expiration
        if (
          !isset($doc['reset_token_expires_at']) ||
          $doc['reset_token_expires_at'] < now()->toDateTimeString()
        ) {
          return null;
        }

        return $doc;
      }

      return null;
    } catch (Exception $e) {

      AppLogger::error("OtpVerificationRepository@findByResetToken failed: " . $e->getMessage());
      throw new Exception("Unable to validate reset token.");
    }
  }

  public function update(string $id, array $data): bool
  {
    try {

      $data['updatedAt'] = now()->toDateTimeString();

      $this->client->update(
        $this->collection,
        $id,
        $data
      );

      return true;
    } catch (Exception $e) {

      AppLogger::error("OtpVerificationRepository@update failed: " . $e->getMessage());
      throw new Exception("Unable to update OTP record.");
    }
  }
}

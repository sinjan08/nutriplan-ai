<?php

namespace App\Services\Firebase;

use Illuminate\Support\Facades\Http;

class GoogleAuthService
{
  protected $credentials;
  protected $tokenPath;

  public function __construct()
  {
    $this->credentials = json_decode(
      file_get_contents(storage_path('app/firebase/firebase.json')),
      true
    );

    $this->tokenPath = storage_path('app/firebase/token_cache.json');
  }

  public function getAccessToken(): string
  {
    // 🔥 Check file cache first
    if (file_exists($this->tokenPath)) {
      $cached = json_decode(file_get_contents($this->tokenPath), true);

      if ($cached && $cached['expires_at'] > time()) {
        return $cached['access_token'];
      }
    }

    // 🔥 Generate new token
    $jwt = $this->createJwt();

    $response = Http::asForm()->post(
      'https://oauth2.googleapis.com/token',
      [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
      ]
    );

    $data = $response->json();

    $tokenData = [
      'access_token' => $data['access_token'],
      'expires_at'   => time() + $data['expires_in'] - 60,
    ];

    // 🔥 Save to file
    file_put_contents($this->tokenPath, json_encode($tokenData));

    return $tokenData['access_token'];
  }

  protected function createJwt(): string
  {
    $header = $this->base64UrlEncode(json_encode([
      'alg' => 'RS256',
      'typ' => 'JWT',
    ]));

    $now = time();

    $payload = $this->base64UrlEncode(json_encode([
      'iss'   => $this->credentials['client_email'],
      // 'scope' => 'https://www.googleapis.com/auth/datastore',
      'scope' => 'https://www.googleapis.com/auth/datastore https://www.googleapis.com/auth/firebase.messaging',
      'aud'   => 'https://oauth2.googleapis.com/token',
      'iat'   => $now,
      'exp'   => $now + 3600,
    ]));

    $signatureInput = $header . '.' . $payload;

    openssl_sign(
      $signatureInput,
      $signature,
      $this->credentials['private_key'],
      'sha256'
    );

    return $signatureInput . '.' . $this->base64UrlEncode($signature);
  }

  protected function base64UrlEncode($data)
  {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }
}

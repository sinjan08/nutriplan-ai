<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Services\AppLogger;
use Exception;

class FcmService
{
  protected $projectId;
  protected $accessToken;
  protected $credentials;

  public function __construct()
  {
    $this->projectId = config('services.firebase.project_id');

    $this->credentials = json_decode(
      file_get_contents(storage_path('app/firebase/wiseforkapp-36292-firebase-adminsdk-fbsvc-382e68bfbc.json')),
      true
    );

    //$this->accessToken = $this->generateAccessToken();
  }

  public function generateAccessToken()

  {

    // $serviceAccountKeyFile = 'https://frontendauth.nextpetapp.com/push_notification/petquest-sign-in-firebase-adminsdk-iswbv-5219f4f5e0.json';
    //$serviceAccountKeyFile = url('wiseforkapp-36292-firebase-adminsdk-fbsvc-087f121c09.json');
    $serviceAccountKeyFile = json_decode(
      file_get_contents(storage_path('app/firebase/wiseforkapp-36292-firebase-adminsdk-fbsvc-382e68bfbc.json')),
      true
    );
    // Fetch JSON file content from the URL

    //  $jsonContent = file_get_contents($serviceAccountKeyFile);
    //
    //  $serviceAccountKeyFile = json_decode($jsonContent, true);

    // print_r($serviceAccountKeyFile['private_key']);exit;

    $now = time();

    //$privateKey = chunk_split($serviceAccountKeyFile['private_key'], 64, "\n");
    $privateKey = $serviceAccountKeyFile['private_key'];
    // print_r($privateKey);exit;

    $clientEmail = $serviceAccountKeyFile['client_email'];

    $header = ['alg' => 'RS256', 'typ' => 'JWT'];

    $payload = [

      'iss' => $clientEmail,

      'scope' => 'https://www.googleapis.com/auth/firebase.messaging',

      'aud' => 'https://oauth2.googleapis.com/token',

      'iat' => $now,

      'exp' => $now + 3600

    ];

    $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($header)));

    $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($payload)));

    $signature = '';

    // openssl_sign($base64UrlHeader . "." . $base64UrlPayload, $signature, $privateKey, 'SHA256');

    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

    if (!openssl_sign($base64UrlHeader . "." . $base64UrlPayload, $signature, $privateKey, 'SHA256')) {
      throw new Exception("JWT signing failed: " . openssl_error_string());
    }

    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

    $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;

    $postFields = http_build_query([

      'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',

      'assertion' => $jwt

    ]);

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');

    curl_setopt($ch, CURLOPT_POST, true);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);

    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {

      echo 'cURL error: ' . curl_error($ch);
    }

    curl_close($ch);

    $tokenData = json_decode($response, true);

    return $tokenData['access_token'];
  }

  // string $fcmToken, string $title, string $body, array $data = []
  public function sendFCMNotification($title, $body, $fcmToken, $accessToken, $data)

  {

    // Ensure that the body is an array and extract the necessary fields

    $bodyContent = $body;  // Default if not set

    $titleContent = $title; // Default if not set

    // The notification payload that will be sent to FCM

    $data = array_map(function ($value) {
      return is_array($value) ? json_encode($value) : (string) $value;
    }, $data);

    $notification = [

      'message' => [

        'token' => $fcmToken,

        'notification' => [

          'title' => $titleContent,  // String title

          'body' => $bodyContent,    // String body

        ],

        'data' => $data,

      ]

    ];

    $headers = [

      'Authorization: Bearer ' . $accessToken,

      'Content-Type: application/json',

    ];

    // cURL request

    $ch = curl_init();

    // curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send');

    $url = "https://fcm.googleapis.com/v1/projects/" . $this->projectId . "/messages:send";
    curl_setopt($ch, CURLOPT_URL, $url);

    curl_setopt($ch, CURLOPT_POST, true);

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($notification));

    $result = curl_exec($ch);
    // dd($result);
    // Check for cURL errors

    curl_close($ch);

    return ['result' => $result, 'notification' => $notification];
  }


  public function sendNotification($title, $body, $fcmToken, $data)

  {

    $accessToken = $this->generateAccessToken();


    // dd($body, $fcmToken, $title, $accessToken);

    return $this->sendFCMNotification($title, $body, $fcmToken, $accessToken, $data);
  }
}

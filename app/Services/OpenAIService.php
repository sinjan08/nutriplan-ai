<?php

namespace App\Services;

use Exception;
use GuzzleHttp\Client;
use App\Services\AppLogger;
use Illuminate\Http\UploadedFile;
use App\Helpers\FileUploadHelper;

class OpenAIService
{
  protected $client;

  public function __construct()
  {
    $this->client = new Client([
      'base_uri' => config('services.openai.base_url'),
      'headers' => [
        'Authorization' => 'Bearer ' . config('services.openai.key'),
        'Content-Type'  => 'application/json',
      ],
    ]);
  }

  /**
   * Text Generation (Chat Completion)
   */
  public function generateText($prompt, $model = 'gpt-4.1-mini')
  {
    try {

      $response = $this->client->post('chat/completions', [
        'json' => [
          'model' => $model,
          'messages' => [
            [
              'role' => 'user',
              'content' => $prompt
            ]
          ],
          'temperature' => 0.7
        ]
      ]);

      $result = json_decode($response->getBody(), true);

      return $result['choices'][0]['message']['content'] ?? null;
    } catch (Exception $e) {
      AppLogger::error("OpenAI Text Generation Error: " . $e->getMessage());
      return null;
    }
  }

  /**
   * Chat Conversation
   */
  public function chat(array $messages, $model = 'gpt-4.1-mini')
  {
    try {

      $response = $this->client->post('chat/completions', [
        'json' => [
          'model' => $model,
          'messages' => $messages
        ]
      ]);

      return json_decode($response->getBody(), true);
    } catch (Exception $e) {

      AppLogger::error("OpenAI Chat Error: " . $e->getMessage());
      return null;
    }
  }

  /**
   * Image Generation
   */
  public function generateImage($prompt, $size = '1024x1024')
  {
    try {

      $response = $this->client->post('images/generations', [
        'json' => [
          'model' => 'gpt-image-1',
          'prompt' => $prompt,
          'size' => $size
        ]
      ]);

      $result = json_decode($response->getBody(), true);

      if (!isset($result['data'][0]['b64_json'])) {
        return null;
      }

      $base64 = $result['data'][0]['b64_json'];
      $imageData = base64_decode($base64);

      // create temporary file
      $tempPath = tempnam(sys_get_temp_dir(), 'meal_img_');
      file_put_contents($tempPath, $imageData);

      // convert to UploadedFile
      $file = new UploadedFile(
        $tempPath,
        'meal.png',
        'image/png',
        null,
        true
      );

      // upload using your helper
      $path = FileUploadHelper::upload($file, 'ai-images/suggested-meals');

      return $path;
    } catch (Exception $e) {
      
      AppLogger::error("OpenAI Image Generation Error: " . $e->getMessage());

      return null;
    }
  }

  /**
   * Embeddings
   */
  public function createEmbedding($text)
  {
    try {

      $response = $this->client->post('embeddings', [
        'json' => [
          'model' => 'text-embedding-3-small',
          'input' => $text
        ]
      ]);

      $result = json_decode($response->getBody(), true);

      return $result['data'][0]['embedding'] ?? null;
    } catch (Exception $e) {

      AppLogger::error("OpenAI Embedding Error: " . $e->getMessage());
      return null;
    }
  }

  /**
   * Audio Transcription
   */
  public function speechToText($filePath)
  {
    try {

      $response = $this->client->post('audio/transcriptions', [
        'multipart' => [
          [
            'name' => 'file',
            'contents' => fopen($filePath, 'r')
          ],
          [
            'name' => 'model',
            'contents' => 'gpt-4o-transcribe'
          ]
        ]
      ]);

      $result = json_decode($response->getBody(), true);

      return $result['text'] ?? null;
    } catch (Exception $e) {

      AppLogger::error("OpenAI Speech To Text Error: " . $e->getMessage());
      return null;
    }
  }

  /**
   * Content Moderation
   */
  public function moderateContent($text)
  {
    try {

      $response = $this->client->post('moderations', [
        'json' => [
          'model' => 'omni-moderation-latest',
          'input' => $text
        ]
      ]);

      return json_decode($response->getBody(), true);
    } catch (Exception $e) {

      AppLogger::error("OpenAI Moderation Error: " . $e->getMessage());
      return null;
    }
  }
}

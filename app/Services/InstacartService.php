<?php

namespace App\Services;

use Exception;
use GuzzleHttp\Client;
use App\Services\AppLogger;

class InstacartService
{
  protected $client;
  protected $unitMap;

  public function __construct()
  {
    $this->client = new Client([
      'base_uri' => config('services.instacart.base_url'),
      'headers' => [
        'Authorization' => 'Bearer ' . config('services.instacart.key'),
        'Content-Type'  => 'application/json',
        'Accept'        => 'application/json',
      ],
    ]);

    $this->unitMap = [

      // LENGTH
      'inch' => ['type' => 'length', 'to' => 'm', 'factor' => 0.0254],
      'foot' => ['type' => 'length', 'to' => 'm', 'factor' => 0.3048],

      // WEIGHT
      'ounce' => ['type' => 'weight', 'to' => 'g', 'factor' => 28.3495],
      'pound' => ['type' => 'weight', 'to' => 'g', 'factor' => 453.592],

      // VOLUME
      'teaspoon' => ['type' => 'volume', 'to' => 'ml', 'factor' => 4.92892],
      'tablespoon' => ['type' => 'volume', 'to' => 'ml', 'factor' => 14.7868],
      'fluid ounce' => ['type' => 'volume', 'to' => 'ml', 'factor' => 29.5735],
      'cup' => ['type' => 'volume', 'to' => 'ml', 'factor' => 240],
      'half cup' => ['type' => 'volume', 'to' => 'ml', 'factor' => 120],
      'quarter cup' => ['type' => 'volume', 'to' => 'ml', 'factor' => 60],
      'pint' => ['type' => 'volume', 'to' => 'ml', 'factor' => 473.176],
      'quart' => ['type' => 'volume', 'to' => 'ml', 'factor' => 946.353],
      'gallon' => ['type' => 'volume', 'to' => 'ml', 'factor' => 3785.41],
      'dash' => ['type' => 'volume', 'to' => 'ml', 'factor' => 0.62],
      'pinch' => ['type' => 'volume', 'to' => 'ml', 'factor' => 0.31],
      'drop' => ['type' => 'volume', 'to' => 'ml', 'factor' => 0.05],

      // AREA
      'square_foot' => ['type' => 'area', 'to' => 'm2', 'factor' => 0.092903],

      // COUNT
      'piece' => ['type' => 'count', 'to' => 'piece', 'factor' => 1],
      'pieces' => ['type' => 'count', 'to' => 'piece', 'factor' => 1],
      'each' => ['type' => 'count', 'to' => 'piece', 'factor' => 1],
      'item' => ['type' => 'count', 'to' => 'piece', 'factor' => 1],
      'clove' => ['type' => 'count', 'to' => 'piece', 'factor' => 1],
      'slice' => ['type' => 'count', 'to' => 'piece', 'factor' => 1],

      // ------------------ ADDED ------------------

      // VOLUME (base units)
      'milliliter' => ['type' => 'volume', 'to' => 'ml', 'factor' => 1],
      'liter' => ['type' => 'volume', 'to' => 'ml', 'factor' => 1000],

      // WEIGHT (base units)
      'gram' => ['type' => 'weight', 'to' => 'g', 'factor' => 1],
      'kilogram' => ['type' => 'weight', 'to' => 'g', 'factor' => 1000],

      // COUNT (additional)
      'bunch' => ['type' => 'count', 'to' => 'piece', 'factor' => 1],
      'can' => ['type' => 'count', 'to' => 'piece', 'factor' => 1],
      'ear' => ['type' => 'count', 'to' => 'piece', 'factor' => 1],
      'head' => ['type' => 'count', 'to' => 'piece', 'factor' => 1],
      'large' => ['type' => 'count', 'to' => 'piece', 'factor' => 1],
      'medium' => ['type' => 'count', 'to' => 'piece', 'factor' => 1],
      'small' => ['type' => 'count', 'to' => 'piece', 'factor' => 1],
      'package' => ['type' => 'count', 'to' => 'piece', 'factor' => 1],
    ];
  }

  /**
   * To open instacart page
   * @param array $recipe
   * @param string $author
   * @param int $cookingTime
   * @param array $ingredients
   * @throws Exception
   */
  public function createRecipe(string $recipeTitle, array $ingredients)
  {
    try {
      // validating request  
      if (empty($ingredients)) throw new Exception("Reuired parameter is missing");

      $ingredients = $this->normalizeIngredients($ingredients);

      // declaring api endpoints, method
      $endpoint = 'products/recipe';
      // preparing payload
      $payload = [
        "title" => $recipeTitle,
        "expires_in" => 30,
        "ingredients" => $ingredients,
      ];

      // calling api
      $response = $this->client->post($endpoint, [
        'json' => $payload,
      ]);

      // decoding
      $result = json_decode($response->getBody(), true);

      if (!isset($result['products_link_url'])) {
        return null;
      }

      return $result['products_link_url'];
    } catch (Exception $e) {
      AppLogger::error($e->getMessage());
      throw new Exception($e->getMessage());
    }
  }

  /**
   * Normalization of units
   * @param array $ingredients
   * @return array{name: string, qty: float, unit: string[]}
   */
  public function normalizeIngredients(array $ingredients): array
  {
    return array_map(function ($ingredient) {

      $unit = strtolower(trim($ingredient['unit'] ?? 'each'));
      $quantity = (float) ($ingredient['qty'] ?? 1);

      if (isset($this->unitMap[$unit])) {
        $convertedQuantity = $quantity * $this->unitMap[$unit]['factor'];
        $baseUnit = $this->unitMap[$unit]['to'];
      } else {
        // already metric or unknown → store as-is
        $convertedQuantity = $quantity;
        $baseUnit = $unit;
      }

      return [
        "name" => strtolower(trim($ingredient['name'] ?? '')),
        "display_text" => ucwords(trim($ingredient['name'] ?? '')),
        "measurements" => [
          [
            "quantity" => round($convertedQuantity, 2),
            "unit" => $baseUnit
          ]
        ]
      ];
    }, $ingredients);
  }
}

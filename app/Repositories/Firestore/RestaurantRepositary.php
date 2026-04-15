<?php

namespace App\Repositories\Firestore;

use App\Services\AppLogger;
use App\Services\Firebase\FirestoreService;
use Exception;

class RestaurantRepositary
{
  protected $client;
  protected $collection = 'restaurants';

  public function __construct(FirestoreService $client)
  {
    $this->client = $client;
  }


  /**
   * Get restaurants within bounding box
   * Firebase does not support multiple composite filter. 
   * So fetching data from firebase using lattitude filter only.
   * Then after fetching data from firbase applying filter on longitude at php end
   * and returning the latitude, longitude filtered data
   *
   * @param float $minLat
   * @param float $maxLat
   * @param float $minLong
   * @param float $maxLong
   * @param int $limit
   * @throws Exception
   * @return array
   */
  public function getByBoundingBox(
    float $minLat,
    float $maxLat,
    float $minLong,
    float $maxLong,
    int $limit = 400,
  ): array {
    try {
      // preparing query with lattitude only. because firebase does not support multiple compositefilter
      // taking 500 data from restaurant collection by the lattitude filter and order by lattitude
      $structuredQuery = [
        "from" => [
          ["collectionId" => $this->collection]
        ],
        "where" => [
          "compositeFilter" => [
            "op" => "AND",
            "filters" => [
              [
                "fieldFilter" => [
                  "field" => ["fieldPath" => "latitude"],
                  "op" => "GREATER_THAN_OR_EQUAL",
                  "value" => ["doubleValue" => $minLat]
                ]
              ],
              [
                "fieldFilter" => [
                  "field" => ["fieldPath" => "latitude"],
                  "op" => "LESS_THAN_OR_EQUAL",
                  "value" => ["doubleValue" => $maxLat]
                ]
              ]
            ]
          ]
        ],
        "orderBy" => [
          [
            "field" => ["fieldPath" => "latitude"],
            "direction" => "ASCENDING"
          ]
        ],
        "limit" => $limit
      ];

      // running query
      $results = $this->client->runQuery($structuredQuery);

      // checking actually data is there or not
      if (!is_array($results)) {
        return [];
      }
      // finally filtering by longitude
      $filtered = array_filter($results, function ($restaurant) use ($minLong, $maxLong) {
        return isset($restaurant['longitude']) &&
          $restaurant['longitude'] >= $minLong &&
          $restaurant['longitude'] <= $maxLong;
      });

      // returning onoy array values
      return array_values($filtered);
    } catch (Exception $e) {
      AppLogger::error("RestaurantRepository@getByBoundingBox failed: " . $e->getMessage());
      throw new Exception("Unable to fetch restaurants.");
    }
  }

  /**
   * To fetch restaurant details by id
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
      AppLogger::error("RestaurantRepository@findById failed: " . $e->getMessage());
      throw new Exception("Unable to fetch restaurant details: " . $e->getMessage());
    }
  }
  
}

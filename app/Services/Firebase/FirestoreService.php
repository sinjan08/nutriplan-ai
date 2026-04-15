<?php

namespace App\Services\Firebase;

use Illuminate\Support\Facades\Http;

class FirestoreService
{
  protected $projectId;
  protected $dataSetId;
  protected $auth;

  public function __construct(GoogleAuthService $auth)
  {
    $this->projectId = config('services.firebase.project_id'); // env('FIREBASE_PROJECT_ID');
    $this->dataSetId = config('services.firebase.database'); // env('FIRESTORE_DATABASE');
    $this->auth = $auth;
  }

  protected function baseUrl(): string
  {
    return "https://firestore.googleapis.com/v1/projects/{$this->projectId}/databases/{$this->dataSetId}/documents";
  }

  protected function request()
  {
    return Http::withToken($this->auth->getAccessToken());
  }

  /* ===============================
       CREATE
    =============================== */

  public function create(string $collection, array $data)
  {
    $url = "{$this->baseUrl()}/{$collection}";

    return $this->request()
      ->post($url, [
        'fields' => $this->formatFields($data),
      ])
      ->json();
  }

  /* ===============================
       GET DOCUMENT
    =============================== */

  public function get(string $collection, string $docId)
  {
    $url = "{$this->baseUrl()}/{$collection}/{$docId}";

    return $this->request()
      ->get($url)
      ->json();
  }

  /* ===============================
       UPDATE
    =============================== */

  public function update(string $collection, string $docId, array $data)
  {
    $url = "{$this->baseUrl()}/{$collection}/{$docId}";

    // Build proper query string manually
    $queryString = '';

    foreach (array_keys($data) as $field) {
      $queryString .= 'updateMask.fieldPaths=' . urlencode($field) . '&';
    }

    $queryString = rtrim($queryString, '&');

    return $this->request()
      ->patch($url . '?' . $queryString, [
        'fields' => $this->formatFields($data),
      ])
      ->json();
  }


  /* ===============================
       DELETE
    =============================== */

  public function delete(string $collection, string $docId)
  {
    $url = "{$this->baseUrl()}/{$collection}/{$docId}";

    return $this->request()
      ->delete($url)
      ->json();
  }

  /* ===============================
       QUERY
    =============================== */

  public function buildQuery(string $collection, array $filters = [], int $limit = null, array $orderBy = null)
  {
    $structured = [
      'from' => [['collectionId' => $collection]],
    ];

    if (!empty($filters)) {

      $firestoreFilters = [];

      foreach ($filters as $filter) {
        $firestoreFilters[] = [
          'fieldFilter' => [
            'field' => ['fieldPath' => $filter['field']],
            'op'    => $filter['op'],
            'value' => $this->formatValue($filter['value']),
          ],
        ];
      }

      $structured['where'] = [
        'compositeFilter' => [
          'op' => 'AND',
          'filters' => $firestoreFilters,
        ],
      ];
    }

    if (!empty($orderBy)) {
      $structured['orderBy'] = [
        [
          'field' => ['fieldPath' => $orderBy['field']],
          'direction' => $orderBy['direction'] ?? 'DESCENDING'
        ]
      ];
    }

    if ($limit) {
      $structured['limit'] = $limit;
    }

    return $structured;
  }

  public function runQuery(array $structuredQuery)
  {
    $url = "https://firestore.googleapis.com/v1/projects/{$this->projectId}/databases/{$this->dataSetId}/documents:runQuery";

    $response = $this->request()
      ->post($url, [
        'structuredQuery' => $structuredQuery,
      ])
      ->json();

    $results = [];

    foreach ($response as $item) {
      if (isset($item['document'])) {
        $doc = $item['document'];

        $results[] = [
          'id' => basename($doc['name']),
          ...$this->decodeFields($doc['fields']),
        ];
      }
    }

    return $results;
  }

  /* ===============================
       FIELD FORMATTERS
    =============================== */

  protected function formatFields(array $data): array
  {
    $formatted = [];

    foreach ($data as $key => $value) {
      $formatted[$key] = $this->formatValue($value);
    }

    return $formatted;
  }

  protected function formatValue($value)
  {
    if (is_int($value)) {
      return ['integerValue' => $value];
    }

    if (is_float($value)) {
      return ['doubleValue' => $value];
    }

    if (is_bool($value)) {
      return ['booleanValue' => $value];
    }

    if (is_null($value)) {
      return ['nullValue' => null];
    }

    if (is_array($value)) {

      // associative array → mapValue
      if ($this->isAssoc($value)) {
        $fields = [];

        foreach ($value as $k => $v) {
          $fields[$k] = $this->formatValue($v);
        }

        return [
          'mapValue' => [
            'fields' => $fields
          ]
        ];
      }

      // indexed array → arrayValue
      $values = [];

      foreach ($value as $v) {
        $values[] = $this->formatValue($v);
      }

      return [
        'arrayValue' => [
          'values' => $values
        ]
      ];
    }

    return ['stringValue' => (string) $value];
  }

  private function isAssoc(array $array): bool
  {
    return array_keys($array) !== range(0, count($array) - 1);
  }


  public function decodeFields(array $fields): array
  {
    $decoded = [];

    foreach ($fields as $key => $value) {

      if (isset($value['stringValue'])) {
        $decoded[$key] = $value['stringValue'];
      } elseif (isset($value['integerValue'])) {
        $decoded[$key] = (int) $value['integerValue'];
      } elseif (isset($value['doubleValue'])) {
        $decoded[$key] = (float) $value['doubleValue'];
      } elseif (isset($value['booleanValue'])) {
        $decoded[$key] = (bool) $value['booleanValue'];
      } elseif (array_key_exists('nullValue', $value)) {
        $decoded[$key] = null;
      } elseif (isset($value['arrayValue'])) {

        $decoded[$key] = array_map(function ($item) {
          return $this->decodeSingleValue($item);
        }, $value['arrayValue']['values'] ?? []);
      } elseif (isset($value['mapValue'])) {

        $decoded[$key] = $this->decodeFields($value['mapValue']['fields'] ?? []);
      }
    }

    return $decoded;
  }

  private function decodeSingleValue(array $value)
  {
    if (isset($value['stringValue'])) {
      return $value['stringValue'];
    } elseif (isset($value['integerValue'])) {
      return (int) $value['integerValue'];
    } elseif (isset($value['doubleValue'])) {
      return (float) $value['doubleValue'];
    } elseif (isset($value['booleanValue'])) {
      return (bool) $value['booleanValue'];
    } elseif (array_key_exists('nullValue', $value)) {
      return null;
    } elseif (isset($value['mapValue'])) {
      return $this->decodeFields($value['mapValue']['fields'] ?? []);
    }

    return null;
  }

  public function set(string $collection, string $docId, array $data)
  {
    $url = "{$this->baseUrl()}/{$collection}/{$docId}";

    return $this->request()
      ->patch($url, [
        'fields' => $this->formatFields($data),
      ])
      ->json();
  }


  public function batchCreate(string $collection, array $documents)
  {
    $url = "https://firestore.googleapis.com/v1/projects/{$this->projectId}/databases/{$this->dataSetId}/documents:commit";

    $writes = [];

    foreach ($documents as $doc) {
      $writes[] = [
        'update' => [
          'name' => "projects/{$this->projectId}/databases/{$this->dataSetId}/documents/{$collection}/" . uniqid(),
          'fields' => $this->formatFields($doc),
        ]
      ];
    }

    $response = $this->request()
      ->withHeaders([
        'Content-Type' => 'application/json'
      ])
      ->post($url, [
        'writes' => $writes
      ]);

    // 🔥 DEBUG (IMPORTANT)
    if (!$response->successful()) {
      return null;
    }

    return $response->json();
  }

  public function batchDelete(string $collection, array $docIds)
  {
    $url = "https://firestore.googleapis.com/v1/projects/{$this->projectId}/databases/{$this->dataSetId}/documents:commit";

    $writes = [];

    foreach ($docIds as $docId) {
      $writes[] = [
        'delete' => "projects/{$this->projectId}/databases/{$this->dataSetId}/documents/{$collection}/{$docId}"
      ];
    }

    $response = $this->request()
      ->withHeaders([
        'Content-Type' => 'application/json'
      ])
      ->post($url, [
        'writes' => $writes
      ]);

    if (!$response->successful()) {
      return null;
    }

    return $response->json();
  }


  public function deleteByField(string $collection, string $field, $value)
  {
    // Step 1: build query
    $query = $this->buildQuery($collection, [
      [
        'field' => $field,
        'op' => 'EQUAL',
        'value' => $value
      ]
    ]);

    // Step 2: fetch docs
    $docs = $this->runQuery($query);

    if (empty($docs)) {
      return true; // nothing to delete
    }

    // Step 3: extract IDs
    $docIds = array_column($docs, 'id');

    // Step 4: batch delete in chunks (Firestore limit = 500)
    foreach (array_chunk($docIds, 500) as $chunk) {
      $this->batchDelete($collection, $chunk);
    }

    return true;
  }
}

<?php

namespace App\Services\Firebase;

class FirestoreQueryBuilder
{
  protected $collection;
  protected $filters = [];
  protected $limitValue;

  public function __construct(string $collection)
  {
    $this->collection = $collection;
  }

  public function where(string $field, string $op, $value)
  {
    $this->filters[] = [
      'fieldFilter' => [
        'field' => ['fieldPath' => $field],
        'op'    => strtoupper($op),
        'value' => ['stringValue' => (string) $value],
      ],
    ];

    return $this;
  }

  public function limit(int $limit)
  {
    $this->limitValue = $limit;
    return $this;
  }

  public function build(): array
  {
    return [
      'from' => [['collectionId' => $this->collection]],
      'where' => [
        'compositeFilter' => [
          'op' => 'AND',
          'filters' => $this->filters,
        ],
      ],
      'limit' => $this->limitValue ?? null,
    ];
  }
}

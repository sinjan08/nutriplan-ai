<?php

namespace App\Repositories\Firestore;

use App\Services\Firebase\FirestoreClient;
use App\Services\Firebase\FirestoreQueryBuilder;

abstract class BaseRepository
{
  protected $client;
  protected $collection;

  public function __construct(FirestoreClient $client)
  {
    $this->client = $client;
  }

  protected function builder()
  {
    return new FirestoreQueryBuilder(
      $this->client->collection($this->collection)
    );
  }

  public function create(array $data, ?string $id = null)
  {
    if ($id) {
      $this->client->collection($this->collection)
        ->document($id)
        ->set($data);

      return $id;
    }

    return $this->client
      ->collection($this->collection)
      ->add($data)
      ->id();
  }

  public function find(string $id)
  {
    $snapshot = $this->client
      ->collection($this->collection)
      ->document($id)
      ->snapshot();

    return $snapshot->exists()
      ? ['id' => $snapshot->id(), ...$snapshot->data()]
      : null;
  }

  public function update(string $id, array $data)
  {
    $this->client
      ->collection($this->collection)
      ->document($id)
      ->set($data, ['merge' => true]);

    return true;
  }

  public function delete(string $id)
  {
    $this->client
      ->collection($this->collection)
      ->document($id)
      ->delete();

    return true;
  }

  public function query()
  {
    return $this->builder();
  }
}

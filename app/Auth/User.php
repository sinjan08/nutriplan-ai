<?php

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User implements Authenticatable, JWTSubject
{
  protected $attributes;

  public function __construct(array $attributes)
  {
    $this->attributes = $attributes;
  }

  public function __get($key)
  {
    return $this->attributes[$key] ?? null;
  }

  public function getJWTIdentifier()
  {
    return $this->attributes['id'];
  }

  public function getJWTCustomClaims()
  {
    return [];
  }

  public function getAuthIdentifierName()
  {
    return 'id';
  }

  public function getAuthIdentifier()
  {
    return $this->attributes['id'];
  }

  public function getAuthPassword()
  {
    return $this->attributes['password'] ?? null;
  }

  public function getAuthPasswordName()
  {
    return 'password';
  }

  public function getRememberToken() {}
  public function setRememberToken($value) {}
  public function getRememberTokenName() {}
}

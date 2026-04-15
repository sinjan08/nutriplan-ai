<?php

namespace App\Auth;

use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use App\Repositories\Firestore\UserRepository;
use App\Auth\User as JwtUser;

class FirestoreUserProvider implements UserProvider
{
  protected $userRepo;

  public function __construct(UserRepository $userRepo)
  {
    $this->userRepo = $userRepo;
  }

  public function retrieveById($identifier)
  {
    $user = $this->userRepo->find($identifier);

    if (!$user) {
      return null;
    }

    return new JwtUser($user);
  }

  public function retrieveByToken($identifier, $token)
  {
    return null;
  }

  public function updateRememberToken(Authenticatable $user, $token)
  {
    //
  }

  public function retrieveByCredentials(array $credentials)
  {
    $user = $this->userRepo->findByEmail($credentials['email'] ?? null);

    if (!$user) {
      return null;
    }

    return new JwtUser($user);
  }

  public function validateCredentials(Authenticatable $user, array $credentials)
  {
    return password_verify(
      $credentials['password'],
      $user->getAuthPassword()
    );
  }

  public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false)
  {
    //
  }
}

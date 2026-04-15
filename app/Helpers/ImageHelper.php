<?php

namespace App\Helpers;

use Exception;
use App\Repositories\Firestore\AvatarRepository;
use App\Services\AppLogger;

class ImageHelper
{
  protected AvatarRepository $avatarRepo;

  public function __construct(AvatarRepository $avatarRepo)
  {
    $this->avatarRepo = $avatarRepo;
  }

  /**
   * Fetch profile image.
   * If isAvatar is true then returning avatr url.
   * Else returning profileimage.
   * if nothing is set then returning null.
   *
   * @param array $user
   * @return string|null
   * @throws Exception
   */
  public function getProfileImage(array $user): ?string
  {
    try {
      $profileImagePath = null;

      // If profile image exists return it immediately
      if (isset($user['isAvatar']) && !$user['isAvatar']) {
        return $user['profileImage'] ? publicStorageUrl($user['profileImage']) : $profileImagePath;
      } else {
        // Else check avatar
        if (!empty($user['avatar_id'])) {
          $avatar = $this->avatarRepo->findById($user['avatar_id']);

          if (!empty($avatar['path'])) {
            return publicStorageUrl($avatar['path']);
          }
        } else {
          return $user['profileImage'] ? publicStorageUrl($user['profileImage']) : $profileImagePath;
        }
      }
    } catch (Exception $e) {
      AppLogger::error("ImageHelper@getProfileImage: " . $e->getMessage());
      throw new Exception("Failed to fetch profile image.");
    }
  }
}

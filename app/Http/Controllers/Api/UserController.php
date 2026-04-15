<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Exception;
use App\Services\AppLogger;
use App\Helpers\ApiResponse;
use App\Helpers\ImageHelper;
use App\Repositories\Firestore\AvatarRepository;
use Illuminate\Http\Request;
use App\Repositories\Firestore\UserRepository;
use App\Helpers\FileUploadHelper;
use App\Http\Requests\UpdateProfileImageRequest;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use App\Auth\User as JwtUser;

class UserController extends Controller
{
  protected $userRepo;
  protected $avatarRepo;
  protected $imageHelper;

  public function __construct(
    UserRepository $userRepo,
    AvatarRepository $avatarRepo,
    ImageHelper $imageHelper
  ) {
    $this->userRepo = $userRepo;
    $this->avatarRepo = $avatarRepo;
    $this->imageHelper = $imageHelper;
  }

  /**
   * To fetch profile of logged in user
   * 
   * @param Request $request
   * @route GET /user/profile
   */
  public function getProfile(Request $request)
  {
    try {
      // getting logged in user id from request
      $user = $request->user();
      if (!$user) {
        AppLogger::warning("Unauthorized profile update attempt.");
        return ApiResponse::error('Unauthorized.', 401);
      }
      $userId = $user->getAuthIdentifier();
      AppLogger::debug("fetching profile for {$userId}");

      // fetching user profile
      $userProfile = $this->userRepo->find($userId);
      AppLogger::debug("user profile: " . json_encode($userProfile));

      // getting profile image
      $profileImage = $this->imageHelper->getProfileImage($userProfile);

      return ApiResponse::success("User profile fetched", [...$userProfile, 'profileImage' => $profileImage], 200);
    } catch (Exception $e) {
      $message = "An error occurred: " . $e->getMessage();
      AppLogger::error($message);
      return ApiResponse::error($message, 500);
    }
  }

  /**
   * To update user profile
   * @param Request $request
   * @route PUT /user/profile/update
   */
  public function update(Request $request)
  {
    try {

      AppLogger::info("Profile update request received.");

      // Get authenticated user
      $user = $request->user();
      if (!$user) {
        AppLogger::warning("Unauthorized profile update attempt.");
        return ApiResponse::error('Unauthorized.', 401);
      }
      $userId = $user->getAuthIdentifier();

      AppLogger::debug("Updating profile for user: {$userId}");

      $path = null;
      // Prepare update payload
      $updateData = [
        'address' => $request->address ?? null,
        'city' => $request->city ?? null,
        'zip' => $request->zip ?? null,
        'lat' => $request->lat ?? null,
        'long' => $request->long ?? null,
      ];

      // validating request has file or not
      if ($request->hasFile('profileImage')) {
        // validation for file format
        $request->validate([
          'profileImage' => 'image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);
        $file = $request->file('profileImage');
        // if file is not corrupted
        if ($file->isValid()) {
          // uploading
          $path = FileUploadHelper::upload($file, "profile/{$userId}");
          // checking path has value or not
          if ($path) {
            // getting user details
            $userProfile = $this->userRepo->find($userId);
            // deleting user uploaded profile image if user has profile image
            if (isset($userProfile['profileImage']) && $userProfile['profileImage']) {
              FileUploadHelper::delete($userProfile['profileImage']);
            }
          }
          // updating profile image path
          $updateData['profileImage'] = $path;
          $updateData['isAvatar'] = false;
        } else {
          return ApiResponse::error('Invalid image upload.', 422);
        }
      }

      if ($request->filled('name')) {
        $updateData['name'] = $request->name;
      }

      if ($request->filled('email')) {
        $updateData['email'] = strtolower($request->email);
      }

      AppLogger::debug("Update payload: " . json_encode($updateData));

      // Update user
      $this->userRepo->update($userId, $updateData);

      // Fetch updated user
      $updatedUser = $this->userRepo->find($userId);

      if (!$updatedUser) {
        AppLogger::error("Profile update failed for user: {$userId}");
        return ApiResponse::error('Failed to update profile', 500);
      }

      unset($updatedUser['password'], $updatedUser['isDeleted'], $updatedUser['isVerified']);

      // getting profile image
      $profileImage = $this->imageHelper->getProfileImage($updatedUser);

      AppLogger::info("Profile updated successfully for user: {$userId}");

      return ApiResponse::success(
        'Profile updated successfully.',
        [...$updatedUser, 'profileImage' => $profileImage],
        200
      );
    } catch (Exception $e) {

      $message = "Profile update exception: " . $e->getMessage();
      AppLogger::error($message);

      return ApiResponse::error($message, 500);
    }
  }

  /**
   * To uploade and update profile image 
   * @param Request $request
   * @route POST /user/profile/image
   */
  public function updateProfileImage(UpdateProfileImageRequest $request)
  {
    try {
      // validating request
      $data = $request->validated();

      $user = $request->user();
      if (!$user) {
        AppLogger::warning("Unauthorized profile update attempt.");
        return ApiResponse::error('Unauthorized.', 401);
      }
      $userId = $user->getAuthIdentifier();
      AppLogger::debug("Profile image will be updated for {$userId}");

      // uploading file
      $path = FileUploadHelper::upload(
        $request->file('profileImage'),
        "profile/{$userId}"
      );

      AppLogger::debug("Profile image uploaded in {$path}");

      // Update user
      $this->userRepo->update($userId, ['profileImage' => $path, 'isAvatar' => false]);

      // Fetch updated user
      $updatedUser = $this->userRepo->find($userId);

      if (!$updatedUser) {
        AppLogger::error("Profile image update failed for user: {$userId}");
        return ApiResponse::error('Failed to update profile image', 500);
      }

      unset($updatedUser['password'], $updatedUser['isDeleted'], $updatedUser['isVerified']);

      // getting profile image
      $profileImage = $this->imageHelper->getProfileImage($updatedUser);

      AppLogger::info("Profile image updated successfully for user: {$userId}");

      return ApiResponse::success(
        'Profile image uploaded successfully.',
        [...$updatedUser, 'profileImage' => $profileImage],
        200
      );
    } catch (Exception $e) {

      $message = "An exception: " . $e->getMessage();
      AppLogger::error($message);

      return ApiResponse::error($message, 500);
    }
  }

  /**
   * To update avatar
   * @param Request $request
   * @route PUT /user/profile/avatar
   */
  public function updateAvatar(Request $request)
  {
    try {
      // validating request 
      if (!$request->avatarId) {
        AppLogger::warning("No avatar choosen");
        return ApiResponse::error('No avatar choosen. Please select a avatar', 200);
      }
      // getting authenticated user
      $user = $request->user();
      if (!$user) {
        AppLogger::warning("Unauthorized profile update attempt.");
        return ApiResponse::error('Unauthorized.', 401);
      }
      $userId = $user->getAuthIdentifier();

      // Update user
      $this->userRepo->update($userId, ['avatar_id' => $request->avatarId, 'isAvatar' => true]);

      // Fetch updated user
      $updatedUser = $this->userRepo->find($userId);

      if (!$updatedUser) {
        AppLogger::error("Profile image update failed for user: {$userId}");
        return ApiResponse::error('Failed to update profile image', 500);
      }

      unset($updatedUser['password'], $updatedUser['isDeleted'], $updatedUser['isVerified']);

      // getting profile image
      $profileImage = $this->imageHelper->getProfileImage($updatedUser);

      AppLogger::info("Profile image updated successfully for user: {$userId}");

      return ApiResponse::success(
        'Avatar uploaded successfully.',
        [...$updatedUser, 'profileImage' => $profileImage],
        200
      );
    } catch (Exception $e) {
      $message = "An exception: " . $e->getMessage();
      AppLogger::error($message);

      return ApiResponse::error($message, 500);
    }
  }

  // [!!!!!! CURRENTLY NOT REQUIRED THIS ENDPOINT - BECAUSE HERE FCM TOKEN WILL BE USED !!!!!!]
  /**
   * To set notification status
   * @param Request $request
   * @route PUT /user/profile/notification/save
   */
  public function setNotification(Request $request)
  {
    try {
      // validating request
      if (!$request->has('notificationStatus')) {
        AppLogger::error("No notification status is found");
        return ApiResponse::error('No notification status is found', 200);
      }

      // getting authenticated user
      $user = $request->user();
      if (!$user) {
        AppLogger::warning("Unauthorized profile update attempt.");
        return ApiResponse::error('Unauthorized.', 401);
      }
      $userId = $user->getAuthIdentifier();

      // parsing to boolean
      $notificationStatus = filter_var($request->notificationStatus, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

      // Update user
      $this->userRepo->update($userId, ['notificationStatus' => $notificationStatus]);

      // Fetch updated user
      $updatedUser = $this->userRepo->find($userId);

      if (!$updatedUser) {
        AppLogger::error("Profile image update failed for user: {$userId}");
        return ApiResponse::error('Failed to update profile image', 500);
      }

      unset($updatedUser['password'], $updatedUser['isDeleted'], $updatedUser['isVerified']);

      // getting profile image
      $profileImage = $this->imageHelper->getProfileImage($updatedUser);

      AppLogger::info("Profile image updated successfully for user: {$userId}");

      return ApiResponse::success(
        'Avatar uploaded successfully.',
        [...$updatedUser, 'profileImage' => $profileImage],
        200
      );
    } catch (Exception $e) {
      $message = "An exception: " . $e->getMessage();
      AppLogger::error($message);

      return ApiResponse::error($message, 500);
    }
  }

  /**
   * To soft delete account by user
   * @param Request $request
   * @route DELETE /user/profile/delete
   */
  public function deleteAccount(Request $request)
  {
    try {
      // validating user
      $user = $request->user();
      if (!$user) {
        return ApiResponse::error('Unauthorized.', 401);
      }

      $userId = $user->getAuthIdentifier();

      AppLogger::debug("Account yet to be deleted for {$userId}");
      // getting user details
      $userProfile = $this->userRepo->find($userId);

      // checking user found or not
      if (!$userProfile) {
        $message = "User not found";
        AppLogger::error($message);
        return ApiResponse::error($message, 422);
      }

      // soft deleting user by update isDeleted flag
      $deleteUser = $this->userRepo->update($userId, [
        'isDeleted' => true,
        'email' => null,
        'password' => null,
        'fcm' => null,
        'deletedAt' => now()->toDateTimeString(),
      ]);

      if (!$deleteUser) {
        $message = "Failed to delete user";
        AppLogger::error($message);
        return ApiResponse::error($message, 422);
      }

      // deleting user uploaded profile image if user has profile image
      if (isset($userProfile['profileImage']) && $userProfile['profileImage']) {
        FileUploadHelper::delete($userProfile['profileImage']);
      }

      // invalidate JWT token (logout user)
      JWTAuth::invalidate(JWTAuth::getToken());
      JWTAuth::parseToken()->invalidate(true);

      return ApiResponse::success("Account has deleted successfully");
    } catch (Exception $e) {
      $message = "An exception: " . $e->getMessage();
      AppLogger::error($message);

      return ApiResponse::error($message, 500);
    }
  }


  public function removeProfileImage(Request $request)
  {
    try {
      // validating user
      $user = $request->user();
      if (!$user) {
        return ApiResponse::error('Unauthorized.', 401);
      }

      $userId = $user->getAuthIdentifier();
      // fetching user details

      $userProfile = $this->userRepo->find($userId);

      if ($userProfile['profileImage']) {
        // deleteing image
        FileUploadHelper::delete($userProfile['profileImage']);
      }

      $updateData = [
        'profileImage' => null,
        'isAvatar' => $userProfile['avatar_id'] ? true : false
      ];

      // updating the user
      $this->userRepo->update($userId, $updateData);

      return ApiResponse::success("Profile Image removed successfully");
    } catch (Exception $e) {
      $message = "An exception: " . $e->getMessage();
      AppLogger::error($message);

      return ApiResponse::error($message, 500);
    }
  }
}

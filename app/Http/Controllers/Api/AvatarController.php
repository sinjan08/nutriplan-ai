<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AvatarCreateRequest;
use App\Repositories\Firestore\UserRepository;
use App\Repositories\Firestore\AvatarRepository;
use App\Helpers\ApiResponse;
use App\Services\AppLogger;
use Exception;
use Illuminate\Http\Request;
use App\Helpers\FileUploadHelper;


class AvatarController extends Controller
{
  protected $userRepo;
  protected $avatarRepo;


  public function __construct(
    UserRepository $userRepo,
    AvatarRepository $avatarRepo

  ) {
    $this->userRepo = $userRepo;
    $this->avatarRepo = $avatarRepo;
  }

  /**
   * To fetch all avatars
   * @param Request $request
   * @return JSON
   */
  public function getAllAvatars()
  {
    try {
      // fetching all avatars
      $avatars = $this->avatarRepo->getAll();

      // checking any avatar is in db or not
      if (empty($avatars) || !$avatars) {
        AppLogger::debug("No avatar found");
        return ApiResponse::success("No avatar found", [], 200);
      }

      AppLogger::debug("Avatars fetched successfully");
      // finally sending success response
      return ApiResponse::success("Avatars fetched successfully", $avatars);
    } catch (Exception $e) {

      AppLogger::error("Failed to fetch avatar " . $e->getMessage());

      return ApiResponse::error(
        'An error occurred: ' . $e->getMessage(),
        500
      );
    }
  }

  /**
   * To upload new avatar
   * @param AvatarCreateRequest $request
   */
  public function create(AvatarCreateRequest $request)
  {
    try {

      $data = $request->validated();

      // Upload file
      $path = FileUploadHelper::upload(
        $request->file('avatarImage'),
        'avatars'
      );

      // Prepare DB payload
      $avatarData = [
        'name'      => $data['name'],
        'path'      => $path,
        'is_active' => true,
      ];

      // Store in Firestore
      $avatar = $this->avatarRepo->create($avatarData);

      // Transform response
      $avatar['path'] = publicStorageUrl($avatar['path']);

      return ApiResponse::success(
        'Avatar created successfully.',
        $avatar,
        201
      );
    } catch (Exception $e) {

      AppLogger::error("AvatarController@create failed: " . $e->getMessage());

      return ApiResponse::error(
        'An error occurred: ' . $e->getMessage(),
        500
      );
    }
  }
}

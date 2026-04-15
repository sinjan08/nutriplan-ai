<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Exception;
use App\Services\AppLogger;
use App\Helpers\ApiResponse;
use App\Http\Requests\PreferenceRequest;
use App\Repositories\Firestore\PreferenceRepository;
use Illuminate\Http\Request;

class PreferenceController extends Controller
{
  protected $preferenceRepo;

  public function __construct(
    PreferenceRepository $preferenceRepository
  ) {
    $this->preferenceRepo = $preferenceRepository;
  }

  public function savePrefernce(PreferenceRequest $request)
  {
    try {
      // validating input request
      $data = $request->validated();

      // getting logged in user id from request
      $user = $request->user();
      if (!$user) {
        AppLogger::warning("Unauthorized profile update attempt.");
        return ApiResponse::error('Unauthorized.', 401);
      }
      $userId = $user->getAuthIdentifier();


      // preparing preferece data
      $preferenceData = $request->only([
        'personalInfo',
        'priority',
        'calories',
        'dailySpent',
        'mealBudget',
        'targetSaving',
        'preferredCuisin',
        'dietaryPreference',
        'cookingSkil',
        'appliance',
      ]);

      $preferenceData['user_id'] = $userId;

      // checking preference exists or not
      $savePreference = $this->upsertPreference($preferenceData, $userId);

      if (!$savePreference || !$savePreference['success']) {
        return ApiResponse::error("Failed to save preference", 422);
      }
      $preferenceId = $savePreference['data']['id'];
      $preference = $this->preferenceRepo->findById($preferenceId);

      return ApiResponse::success(
        "Preference saved successfully.",
        $preference,
        200
      );
    } catch (Exception $e) {
      AppLogger::error("An error occurred: ", $e->getmessage());
      return ApiResponse::error(
        "Something went wrong: " . $e->getMessage(),
        500
      );
    }
  }

  /**
   * To insert or update preference
   * @param mixed $preferenceData
   * @param mixed $userId
   * @return array{data: mixed, success: bool|array{data: null, success: bool}}
   */
  public function upsertPreference($preferenceData, $userId)
  {
    try {
      // checking preference exists or not
      $existingPreference = $this->preferenceRepo->finPreferenceByUser($userId);
      if (!$existingPreference || empty($existingPreference)) {
        // Create preference
        $preference = $this->preferenceRepo->create($preferenceData);

        if (!$preference) {
          AppLogger::error("Failed to save preference for {$userId}");
          return ['success' => false, 'data' => null];
        }

        return ['success' => true, 'data' => $preference];
      } else {
        AppLogger::debug("Preference is found for this use. So updating prefrence");
        // updating preference
        $this->preferenceRepo->update(
          $existingPreference['id'],
          $preferenceData
        );

        $preference = $this->preferenceRepo->findById($existingPreference['id']);

        return ['success' => true, 'data' => $preference];
      }
    } catch (Exception $e) {
      AppLogger::error("An error occurred: ", $e->getmessage());
      return ['success' => false, 'data' => null];
    }
  }

  public function getPreference(Request $request)
  {
    try {
      // getting logged in user id from request
      $user = $request->user();
      if (!$user) {
        AppLogger::warning("Unauthorized profile update attempt.");
        return ApiResponse::error('Unauthorized.', 401);
      }
      $userId = $user->getAuthIdentifier();
      AppLogger::debug("Preference will be fetched for {$userId}");

      // fetching preferences by user id
      $preference = $this->preferenceRepo->finPreferenceByUser($userId);
      // checking empty preference
      if (empty($preference) || !$preference) {
        AppLogger::debug("No preference found");
        return ApiResponse::success("You did not save your preference yet");
      }

      return ApiResponse::success("Your preference fetched successfully", $preference);
    } catch (Exception $e) {
      AppLogger::error("An error occurred: ", $e->getmessage());
      return ApiResponse::error(
        "Something went wrong: " . $e->getMessage(),
        500
      );
    }
  }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Services\AppLogger;
use App\Repositories\Firestore\CmsRepository;
use Illuminate\Http\Request;
use Exception;

class CmsController extends Controller
{
  protected $cmsRepo;

  public function __construct(CmsRepository $cmsRepo)
  {
    $this->cmsRepo = $cmsRepo;
  }

  /**
   * Fetch CMS content by key
   * Example keys: privacy, about, terms
   */
  public function fetch(Request $request)
  {
    try {
      $key = $request->key;
      if (!$key) {
        return ApiResponse::error("Required parameter is missing", 422);
      }
      AppLogger::info("CMS fetch request for key: {$key}");

      $cms = $this->cmsRepo->findById($key);

      if (!$cms) {
        AppLogger::warning("CMS not found for key: {$key}");
        return ApiResponse::error('Content not found.', 200);
      }

      AppLogger::info("CMS fetched successfully for key: {$key}");

      return ApiResponse::success(
        'CMS fetched successfully.',
        $cms,
        200
      );
    } catch (Exception $e) {

      AppLogger::error("CMS fetch failed: " . $e->getMessage());
      return ApiResponse::error('Something went wrong.', 500);
    }
  }

  /**
   * Create CMS content
   */
  public function create(Request $request)
  {
    try {

      if (!$request->key || !$request->content) {
        AppLogger::warning("CMS create validation failed.");
        return ApiResponse::error('Key and content are required.', 200);
      }

      $key = strtolower(trim($request->key));

      AppLogger::info("CMS create request for key: {$key}");

      if ($this->cmsRepo->findById($key)) {
        AppLogger::warning("CMS key already exists: {$key}");
        return ApiResponse::error('CMS key already exists.', 200);
      }

      $data = [
        'key'       => $key,
        'title'     => $request->title ?? null,
        'content'   => $request->content,
        'createdAt' => now()->toDateTimeString(),
        'updatedAt' => null,
      ];

      $cms = $this->cmsRepo->createWithId($key, $data);

      AppLogger::info("CMS created successfully for key: {$key}");

      return ApiResponse::success(
        'CMS created successfully.',
        $cms,
        201
      );
    } catch (Exception $e) {

      AppLogger::error("CMS create failed: " . $e->getMessage());
      return ApiResponse::error('Something went wrong.', 500);
    }
  }

  /**
   * Update CMS content
   */
  public function update(Request $request, string $key)
  {
    try {

      AppLogger::info("CMS update request for key: {$key}");

      $existing = $this->cmsRepo->findById($key);

      if (!$existing) {
        AppLogger::warning("CMS not found for update: {$key}");
        return ApiResponse::error('Content not found.', 200);
      }

      $updateData = [];

      if ($request->has('title')) {
        $updateData['title'] = $request->title;
      }

      if ($request->has('content')) {
        $updateData['content'] = $request->content;
      }

      if ($request->has('is_active')) {
        $updateData['is_active'] = filter_var(
          $request->is_active,
          FILTER_VALIDATE_BOOLEAN
        );
      }

      if (empty($updateData)) {
        AppLogger::warning("CMS update payload empty for key: {$key}");
        return ApiResponse::error('No fields provided for update.', 200);
      }

      $updateData['updatedAt'] = now()->toDateTimeString();

      $cms = $this->cmsRepo->update($key, $updateData);

      AppLogger::info("CMS updated successfully for key: {$key}");

      return ApiResponse::success(
        'CMS updated successfully.',
        $cms,
        200
      );
    } catch (Exception $e) {

      AppLogger::error("CMS update failed: " . $e->getMessage());
      return ApiResponse::error('Something went wrong.', 500);
    }
  }

  /**
   * Hard delete CMS document
   */
  public function delete(string $key)
  {
    try {

      AppLogger::info("CMS delete request for key: {$key}");

      $existing = $this->cmsRepo->findById($key);

      if (!$existing) {
        AppLogger::warning("CMS not found for delete: {$key}");
        return ApiResponse::error('Content not found.', 200);
      }

      $this->cmsRepo->deleteById($key);

      AppLogger::info("CMS deleted successfully for key: {$key}");

      return ApiResponse::success(
        'CMS deleted successfully.',
        [],
        200
      );
    } catch (Exception $e) {

      AppLogger::error("CMS delete failed: " . $e->getMessage());
      return ApiResponse::error('Something went wrong.', 500);
    }
  }
}

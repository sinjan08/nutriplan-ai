<?php

namespace App\Helpers;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileUploadHelper
{
  /**
   * To upload files 
   * @param UploadedFile $file
   * @param string $directory
   * @param string $disk
   * @return string
   */
  public static function upload(
    UploadedFile $file,
    string $directory,
    string $disk = 'public'
  ): string {

    $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();

    $path = $file->storeAs(
      $directory,
      $filename,
      $disk
    );

    return $path; // returns avatars/uuid.png
  }

  /**
   * To delete or unset files
   * @param mixed $path
   * @param string $disk
   * @return bool
   */
  public static function delete(
    ?string $path,
    string $disk = 'public'
  ): bool {

    if (!$path) {
      return false;
    }

    if (Storage::disk($disk)->exists($path)) {
      return Storage::disk($disk)->delete($path);
    }

    return false;
  }
}

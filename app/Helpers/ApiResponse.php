<?php

namespace App\Helpers;

class ApiResponse
{
  public static function success($message = 'Success', $data = [], $code = 200)
  {
    $response = [
      'success' => true,
      'message' => $message,
    ];

    if (!empty($data)) {
      $response['data'] = $data;
    }

    return response()->json($response, $code);
  }

  public static function error($message = 'Something went wrong', $code = 400)
  {
    $response = [
      'success' => false,
      'message' => $message,
    ];

    if (!empty($data)) {
      $response['data'] = $data;
    }

    return response()->json($response, $code);
  }

  public static function validation($validator, $code = 422)
  {
    // Get first validation error message only
    $firstError = $validator->errors()->first();

    $response = [
      'success' => false,
      'message' => $firstError,
    ];

    return response()->json($response, $code);
  }
}

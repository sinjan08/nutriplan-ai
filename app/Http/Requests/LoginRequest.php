<?php

namespace App\Http\Requests;

class LoginRequest extends BaseRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      'email'     => 'required|string|max:100',
      'password' => 'required|min:6',
      'fcm' => 'nullable|string',
    ];
  }
}

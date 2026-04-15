<?php

namespace App\Http\Requests;

class RegisterRequest extends BaseRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      'name'     => 'required|string|max:100',
      'email'    => 'required|email',
      'password' => 'required|min:6',
      'avatar_id' => 'nullable|string',
      'deviceType' => 'required|string',
      'fcm' => 'string|nullable',
    ];
  }
}

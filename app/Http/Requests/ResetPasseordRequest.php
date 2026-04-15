<?php

namespace App\Http\Requests;

class ResetPasseordRequest extends BaseRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      'email'     => 'required|string|max:100',
      'newPassword'    => 'required|string|min:6|max:30',
      'confirmPassword' => 'required|string|min:6|max:30|same:newPassword',
      'reset_token' => 'required|string',
    ];
  }
}

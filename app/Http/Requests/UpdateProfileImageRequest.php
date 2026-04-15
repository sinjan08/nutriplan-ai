<?php

namespace App\Http\Requests;

class UpdateProfileImageRequest extends BaseRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      'profileImage' => [
        'required',
        'file',
        'image',
        'mimes:webp,jpg,jpeg,png',
        'max:2048', // 2MB
      ],
    ];
  }
}

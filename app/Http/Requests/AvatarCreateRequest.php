<?php

namespace App\Http\Requests;

class AvatarCreateRequest extends BaseRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      'name' => 'required|string|max:255',

      'avatarImage' => [
        'required',
        'file',
        'image',
        'mimes:webp,jpg,jpeg,png',
        'max:2048', // 2MB
      ],
    ];
  }
}

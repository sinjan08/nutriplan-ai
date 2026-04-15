<?php

namespace App\Http\Requests;

class PantryInsertRequest extends BaseRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      'name' => 'required|string|max:255',
      'itemImage' => [
        'nullable',
        'file',
        'image',
        'mimes:webp,jpg,jpeg,png',
        'max:2048', // 2MB
      ],
      'qty' => 'required|integer',
      'unit' => 'required|string'
    ];
  }
}

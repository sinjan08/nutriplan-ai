<?php

namespace App\Http\Requests;

class AddToCartRequest extends BaseRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      'ingredients' => ['required', 'array', 'min:1'],
      'isOrder' => ['required', 'boolean'],

      'ingredients.*.name' => ['required', 'string', 'max:255'],
      'ingredients.*.qty' => ['required', 'numeric', 'min:0.01'],
      'ingredients.*.unit' => ['required', 'string', 'max:50'],
    ];
  }
}

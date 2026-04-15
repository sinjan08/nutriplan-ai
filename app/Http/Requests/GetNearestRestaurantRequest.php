<?php

namespace App\Http\Requests;

class GetNearestRestaurantRequest extends BaseRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      'lat'       => 'required|numeric|between:-90,90',
      'long'      => 'required|numeric|between:-180,180',
      'search'    => 'string|nullable',
      'page'      => 'nullable|integer|min:1',
      'per_page'  => 'nullable|integer|min:1|max:50',
      'type'      =>  'nullable|string',
    ];
  }
}

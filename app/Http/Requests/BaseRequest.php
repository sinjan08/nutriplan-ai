<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Helpers\ApiResponse;

abstract class BaseRequest extends FormRequest
{
  protected function failedValidation(Validator $validator)
  {
    throw new HttpResponseException(
      ApiResponse::error(
        $validator->errors()->first(),
        422
      )
    );
  }
}

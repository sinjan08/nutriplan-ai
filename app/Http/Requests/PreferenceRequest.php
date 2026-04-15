<?php

namespace App\Http\Requests;

class PreferenceRequest extends BaseRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      // OBJECT
      'personalInfo' => 'nullable|array',

      'personalInfo.gender' => 'nullable|string',
      'personalInfo.dob' => 'nullable|string',
      'personalInfo.height' => 'nullable|string',
      'personalInfo.weight' => 'nullable|string',
      'personalInfo.targetWeight' => 'nullable|string',
      'personalInfo.activityLevel' => 'nullable|string',

      // SIMPLE FIELDS
      'priority' => 'nullable|string',
      'calories' => 'nullable|string',
      'dailySpent' => 'nullable|string',
      'mealBudget' => 'nullable|string',
      'targetSaving' => 'nullable|string',

      // ARRAYS
      'preferredCuisin' => 'nullable|array',
      'preferredCuisin.*' => 'string',

      'dietaryPreference' => 'nullable|array',
      'dietaryPreference.*' => 'string',

      'appliance' => 'nullable|array',
      'appliance.*' => 'string',

      // STRING
      'cookingSkil' => 'nullable|string',
    ];
  }
}

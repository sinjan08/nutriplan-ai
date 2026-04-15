<?php

namespace App\Services\Notification;

use Carbon\Carbon;
use App\Services\AppLogger;

class TriggerResolver
{
  public function resolve(array $user): ?array
  {
    // PRIORITY ORDER
    $isLunch = $this->isLunchTime();
    AppLogger::debug("Is Lunch Time:" . $isLunch);
    $isDinner = $this->isDinnerTime();
    AppLogger::debug("Is dinner Time:" . $isDinner);
    if ($isDinner) {
      return $this->dinner();
    }

    if ($isLunch) {
      return $this->lunch();
    }

    if ($this->inactiveDays($user) >= 7) {
      return $this->inactive();
    }

    return null;
  }

  protected function isLunchTime()
  {
    $h = now()->hour;
    AppLogger::info("Current hour: " . $h);
    //return $h >= 11 && $h <= 12;
    return true;
  }

  protected function isDinnerTime()
  {
    $h = now()->hour;
    AppLogger::info("Current hour: " . $h);
    return $h >= 16 && $h <= 18;
  }

  protected function inactiveDays($user)
  {
    if (empty($user['lastActiveOn'])) return 0;
    return now()->diffInDays(Carbon::parse($user['lastActiveOn']));
  }

  protected function lunch()
  {
    return [
      'id' => 'LUNCH_PROMPT',
      'title' => 'Lunch Time',
      'body' => 'Lunch idea: make something you’d usually order, for less.',
      'deep_link' => '/lunch'
    ];
  }

  protected function dinner()
  {
    return [
      'id' => 'DINNER_PROMPT',
      'title' => 'Dinner Tonight',
      'body' => 'Dinner tonight: a cheaper version of something you’d normally order is ready.',
      'deep_link' => '/dinner'
    ];
  }

  protected function inactive()
  {
    return [
      'id' => 'INACTIVE_7_DAYS',
      'title' => 'Ready to Cook?',
      'body' => 'A cheaper, easier food decision is waiting for you.',
      'deep_link' => '/dashboard'
    ];
  }
}

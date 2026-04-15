<?php

use Illuminate\Support\Facades\Storage;

/**
To generate random 5 digit string in purpose register and forgot password OTP verification
 */
if (!function_exists('generateOtp')) {
  function generateOtp(int $length = 5): string
  {
    return str_pad(
      random_int(0, pow(10, $length) - 1),
      $length,
      '0',
      STR_PAD_LEFT
    );
  }
}

/**
To generate public storage url with domain
 */
if (!function_exists('publicStorageUrl')) {
  function publicStorageUrl(?string $path): ?string
  {
    if (!$path) {
      return null;
    }

    return url('public/storage/' . ltrim($path, '/'));
  }
}

/**
To return placeholder image path
 */
if (!function_exists('getPlaceholderImage')) {
  function getPlaceholderImage($isMenu = false): ?string
  {
    $path = !$isMenu ? config('app.placeholder_image') : config('app.food_placeholder_image');

    if (!$path) {
      return null;
    }

    return publicStorageUrl($path);
  }
}


/**
 * Calculate distance between two coordinates using Haversine formula
 * Returns distance in kilometers
 */
if (!function_exists('calculateDistance')) {
  function calculateDistance($lat1, $lon1, $lat2, $lon2)
  {
    $earthRadius = 6371; // Radius of Earth in KM

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) * sin($dLat / 2) +
      cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
      sin($dLon / 2) * sin($dLon / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
  }
}

if (!function_exists('getPaginatedData')) {
  function getPaginatedData($finalResponse, $currentPage, $perPage, $shuffle = false)
  {
    $totalRecords = count($finalResponse);
    $totalPages = (int) ceil($totalRecords / $perPage);
    if ($shuffle === true) {
      shuffle($finalResponse);
    }
    $offset = ($currentPage - 1) * $perPage;

    $paginatedData = array_slice($finalResponse, $offset, $perPage);

    $paginatedResponse = [
      "current_page"   => $currentPage,
      "per_page"       => $perPage,
      "total_records"  => $totalRecords,
      "total_pages"    => $totalPages,
      "has_more"       => $currentPage < $totalPages,
      "data"           => $paginatedData
    ];

    return $paginatedResponse;
  }
}


if (!function_exists('filterByTimePeriod')) {
  function filterByTimePeriod($data, $timePeriod)
  {
    // Get current date and time
    $now = new DateTime();

    // Define filter range based on requested time period
    switch ($timePeriod) {
      case 'week':
        // Current week: Monday to Sunday
        $start = (clone $now)->modify('monday this week')->setTime(0, 0, 0);
        $end   = (clone $now)->modify('sunday this week')->setTime(23, 59, 59);
        break;

      case 'lastweek':
        // Previous week: Monday to Sunday
        $start = (clone $now)->modify('monday last week')->setTime(0, 0, 0);
        $end   = (clone $now)->modify('sunday last week')->setTime(23, 59, 59);
        break;

      case 'month':
        // Current month: first day to last day
        $start = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
        $end   = (clone $now)->modify('last day of this month')->setTime(23, 59, 59);
        break;

      case 'last6months':
        // Last 6 months from now until current time
        $start = (clone $now)->modify('-6 months')->setTime(0, 0, 0);
        $end   = clone $now;
        break;

      case 'yearly':
        // Current year: January 1 to December 31
        $start = (clone $now)->modify('first day of january this year')->setTime(0, 0, 0);
        $end   = (clone $now)->modify('last day of december this year')->setTime(23, 59, 59);
        break;

      default:
        // Return original data if time period is invalid
        return $data;
    }

    $filtered = [];

    // Loop through all records and keep only matching items
    foreach ($data as $item) {
      // Skip if createdAt does not exist
      if (!isset($item['createdAt']) || empty($item['createdAt'])) {
        continue;
      }

      // Convert createdAt string into DateTime object
      $createdAt = new DateTime($item['createdAt']);

      // Include item if createdAt is within selected range
      if ($createdAt >= $start && $createdAt <= $end) {
        $filtered[] = $item;
      }
    }

    return $filtered;
  }
}
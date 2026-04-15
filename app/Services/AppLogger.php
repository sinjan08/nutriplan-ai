<?php

namespace App\Services;

use Illuminate\Support\Facades\Request;

class AppLogger
{
  protected static function shouldLog($level)
  {
    $envLevel = env('LOG_LEVEL');

    if ($envLevel === 'null' || $envLevel === null) {
      return false;
    }

    if ($envLevel === 'dev' || $envLevel === 'debug') {
      return true;
    }

    if ($envLevel === 'production') {
      return in_array($level, ['info', 'warning', 'error']);
    }

    return false;
  }

  protected static function write($level, $message)
  {
    if (!self::shouldLog($level)) {
      return;
    }

    $date = now()->format('Y-m-d H:i:s');
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

    $class = $trace[2]['class'] ?? 'UnknownClass';
    $function = $trace[2]['function'] ?? 'UnknownFunction';

    $formatted = sprintf(
      "[%s] [%s] %s::%s | %s%s",
      $date,
      strtoupper($level),
      $class,
      $function,
      $message,
      PHP_EOL
    );

    $filePath = storage_path('logs/custom');

    if (!file_exists($filePath)) {
      mkdir($filePath, 0755, true);
    }

    $fileName = $filePath . '/' . now()->format('Y-m-d') . '.log';

    file_put_contents($fileName, $formatted, FILE_APPEND);
  }

  public static function debug($message)
  {
    self::write('debug', $message);
  }

  public static function info($message)
  {
    self::write('info', $message);
  }

  public static function warning($message)
  {
    self::write('warning', $message);
  }

  public static function error($message)
  {
    self::write('error', $message);
  }
}

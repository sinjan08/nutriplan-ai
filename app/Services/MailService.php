<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;

class MailService
{
  public function sendOtp(
    string $email,
    string $otp,
    string $type = 'register'
  ): void {

    $subject = match ($type) {
      'register' => 'Verify Your Email - OTP',
      'forgot'   => 'Reset Password - OTP',
      default    => 'Your OTP Code'
    };

    Mail::send(
      'emails.otp-template',
      [
        'otp'  => $otp,
        'type' => $type
      ],
      function ($message) use ($email, $subject) {
        $message->to($email)
          ->subject($subject);
      }
    );
  }
}

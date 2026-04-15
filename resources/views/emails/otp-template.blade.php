<!DOCTYPE html>
<html>

<head>
  <meta charset="UTF-8">
  <title>Wisefork - OTP Verification</title>
</head>

<body style="font-family: Arial, sans-serif; background-color:#f5f5f5; padding:20px;">

  <div style="max-width:600px; margin:auto; background:#ffffff; padding:30px; border-radius:8px;">

    <h2 style="text-align:center; color:#333;">
      {{ $type === 'register' ? 'Email Verification' : 'Password Reset' }}
    </h2>

    <p style="font-size:16px; color:#555;">
      Your One Time Password (OTP) is:
    </p>

    <div style="text-align:center; margin:30px 0;">
      <span style="font-size:28px; font-weight:bold; letter-spacing:5px; color:#000;">
        {{ $otp }}
      </span>
    </div>

    <p style="color:#777;">
      This OTP is valid for 10 minutes.
    </p>

    <p style="font-size:14px; color:#999;">
      If you did not request this, please ignore this email.
    </p>

  </div>

</body>

</html>
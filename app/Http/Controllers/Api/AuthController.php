<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\OtpVerificationRequest;
use App\Http\Requests\ResetPasseordRequest;
use App\Repositories\Firestore\UserRepository;
use App\Repositories\Firestore\OtpVerificationRepository;
use App\Repositories\Firestore\TempUserRepositary;
use App\Repositories\Firestore\PreferenceRepository;
use App\Repositories\Firestore\AvatarRepository;
use App\Services\MailService;
use App\Helpers\ApiResponse;
use App\Services\AppLogger;
use Exception;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Illuminate\Http\Request;
use App\Auth\User as JwtUser;
use App\Helpers\ImageHelper;

class AuthController extends Controller
{
  protected $userRepo;
  protected $tempUserRepo;
  protected $otpRepo;
  protected $avatarRepo;
  protected $preferenceRepo;
  protected $mailService;
  protected $imageHelper;
  protected $preferenceController;


  public function __construct(
    UserRepository $userRepo,
    TempUserRepositary $tempUserRepo,
    OtpVerificationRepository $otpRepo,
    PreferenceRepository $preferenceRepo,
    MailService $mailService,
    AvatarRepository $avatarRepo,
    ImageHelper $imageHelper,
    PreferenceController $preferenceController,
  ) {
    $this->userRepo = $userRepo;
    $this->tempUserRepo = $tempUserRepo;
    $this->otpRepo = $otpRepo;
    $this->preferenceRepo = $preferenceRepo;
    $this->avatarRepo = $avatarRepo;
    $this->mailService = $mailService;
    $this->imageHelper = $imageHelper;
    $this->preferenceController = $preferenceController;
  }

  /**
   * To ergister user. Primarily saving user into a temp table
   * after OTP verification will save into user collection
   * 
   * @param RegisterRequest $request
   * @route POST /auth/register
   */
  public function register(RegisterRequest $request)
  {
    try {
      // validating request
      $data = $request->validated();

      // Normalize email
      $data['email'] = strtolower($data['email']);
      // hashing password
      $data['password'] = Hash::make($data['password']);
      // Check existing user
      $existing = $this->userRepo->findByEmail($data['email']);

      if ($existing) {
        AppLogger::info("Register attempt with existing email: " . $data['email']);
        return ApiResponse::error("Email already registered.", 200);
      }

      // Create user
      $user = $this->tempUserRepo->create($data);

      if (!$user) {
        AppLogger::error("User creation failed for email: " . $data['email']);
        return ApiResponse::error("Failed to create user.", 500);
      }

      // getting profile image
      $profileImage = $this->imageHelper->getProfileImage($user);

      // Generate OTP
      $otp = generateOtp();
      if (!$otp) {
        AppLogger::error("OTP generation failed for email: " . $data['email']);
        return ApiResponse::error("Failed to generate OTP.", 500);
      }

      // Remove previous OTPs
      $this->otpRepo->deleteByEmail($data['email']);

      // Store OTP
      $this->otpRepo->create([
        'user_id' => $user['id'],
        'email'   => $data['email'],
        'otp'     => $otp,
        'type'    => 'register',
      ]);

      // Send OTP email
      $this->mailService->sendOtp(
        $data['email'],
        $otp,
        'register'
      );

      AppLogger::debug("User registered successfully: " . $user['id']);

      return ApiResponse::success(
        "User registered successfully. OTP sent to email.",
        [...$user, 'profileImage' => $profileImage],
        201
      );
    } catch (Exception $e) {

      AppLogger::error("Register failed: " . $e->getMessage());

      return ApiResponse::error(
        "Something went wrong: " . $e->getMessage(),
        500
      );
    }
  }

  /**
   * To verify otp 
   * common endpoint to veryfy otp at the time of forgot and register both
   * at the time of register finally adding user into user collection if successfully otp verified
   * 
   * @param OtpVerificationRequest $request
   * @route POST /auth/verify
   */
  public function verifyOtp(OtpVerificationRequest $request)
  {
    try {
      // validating request
      $data = $request->validated();

      // Find valid OTP by email + type
      $otpRecord = $this->otpRepo->findValidOtp(
        $data['email'],
        $data['type']
      );

      if (!$otpRecord) {
        return ApiResponse::error("Invalid or expired OTP.", 200);
      }

      // Check OTP match
      if ($otpRecord['otp'] !== $data['otp']) {
        return ApiResponse::error("Incorrect OTP.", 200);
      }

      // If register verification → mark user verified (update only specific field)
      if ($data['type'] === 'register') {

        // fetching data from temp table
        $user = $this->tempUserRepo->findByEmail($data['email']);

        if (!$user) {
          AppLogger::error("No user found in temp table");
          return ApiResponse::error("Email not found. Please register first", 200);
        }
        //preparing user data
        $userData = [
          'name' => $user['name'],
          'email' => strtolower($user['email']),
          'password' => $user['password'],
          'avatar_id' => $user['avatar_id'] ?? null,
          'deviceType' => $user['deviceType'] ?? null,
          'fcm' => $user['fcm'] ?? null,
          'isAvatar' => true,
        ];
        // register user
        $newUser = $this->userRepo->create($userData);

        if (!$newUser) {
          AppLogger::error("User creation failed for email: " . $data['email']);
          return ApiResponse::error("Failed to register user.", 500);
        }
        $userId = $newUser['id'];
        $this->tempUserRepo->hardDelete($user['id']);

        // Mark OTP as used
        $this->otpRepo->markAsUsed($otpRecord['id']);


        // preparing preferece data
        /*$preferenceData = $request->only([
          'personalInfo',
          'priority',
          'calories',
          'dailySpent',
          'mealBudget',
          'targetSaving',
          'preferredCuisin',
          'dietaryPreference',
          'cookingSkil',
          'appliance',
        ]);

        $preferenceData['user_id'] = $userId;
        // saving preference
        $savePreference = $this->preferenceController->upsertPreference($preferenceData, $userId);
        if (!$savePreference || !$savePreference['success']) {
          return ApiResponse::error("Failed to save preference", 422);
        }
      
        $preferenceId = $savePreference['data']['id'];
        // fetching preference
        $preference = $this->preferenceRepo->findById($preferenceId);*/

        // getting profile image
        $profileImage = $this->imageHelper->getProfileImage($newUser);

        // genereating token
        $userModel = new JwtUser([
          'id' => $userId,
          'email' => $newUser['email'],
        ]);

        $token = JWTAuth::fromUser($userModel);



        return ApiResponse::success(
          "OTP verified successfully",
          [
            'token' => $token,
            'type'  => 'Bearer',
            'user'  => [...$newUser, 'profileImage' => $profileImage],
            // 'preference' => $preference,
          ],
          200,
        );
      }

      if ($data['type'] === 'forgot') {

        // Mark OTP as used
        $this->otpRepo->markAsUsed($otpRecord['id']);

        $resetToken = bin2hex(random_bytes(32));

        $this->otpRepo->update($otpRecord['id'], [
          'reset_token' => $resetToken,
          'reset_token_expires_at' => now()->addMinutes(10)->toDateTimeString(),
        ]);
        AppLogger::info("OTP verified successfully for user: " . $otpRecord['user_id']);

        return ApiResponse::success(
          "OTP verified successfully.",
          [
            'email' => $data['email'],
            'reset_token' => $resetToken
          ],
          200
        );
      }
    } catch (Exception $e) {

      AppLogger::error("Verify OTP failed: " . $e->getMessage());

      return ApiResponse::error(
        "Something went wrong: " . $e->getMessage(),
        500
      );
    }
  }

  /**
   * for user login
   * 
   * @param LoginRequest $request
   * @route POST /auth/login
   */
  public function login(LoginRequest $request)
  {
    try {
      // validating creedentials
      $credentials = $request->validated();
      $credentials['email'] = strtolower($credentials['email']);

      // Fetch full user record for login
      $user = $this->userRepo->findByEmailForLogin($credentials['email']);
      // if email not found
      if (!$user) {
        return ApiResponse::error(
          'Email not found, please register first.',
          200
        );
      }

      // Validate password
      if (!Hash::check($credentials['password'], $user['password'])) {
        return ApiResponse::error(
          'Invalid credentials.',
          200
        );
      }

      //  Check verification
      if (empty($user['isVerified']) || $user['isVerified'] === false) {
        return ApiResponse::error(
          'Email not verified.',
          200
        );
      }

      $userModel = new JwtUser([
        'id' => $user['id'],
        'email' => $user['email'],
      ]);

      // generating jwt token
      $token = JWTAuth::fromUser($userModel);

      $hasPreference = false;
      $preference = $this->preferenceRepo->finPreferenceByUser($user['id']);
      if ($preference && !empty($preference))  $hasPreference = true;

      //  Remove sensitive fields
      unset($user['password'], $user['isDeleted'], $user['isVerified']);



      // getting profile image
      $profileImage = $this->imageHelper->getProfileImage($user);

      return ApiResponse::success(
        'Login successful.',
        [
          'token' => $token,
          'type'  => 'Bearer',
          'user'  => [...$user, 'hasPreference' => $hasPreference, 'profileImage' => $profileImage],
        ],
        200,
      );
    } catch (Exception $e) {

      AppLogger::error("Login failed: " . $e->getMessage());

      return ApiResponse::error(
        'Login failed. Please try again.',
        500
      );
    }
  }


  /**
   * social login using google or apple
   * @route POST /auth/social-login
   */
  public function socialLogin(Request $request)
  {
    try {
      // validating request
      if (!$request->social_id) {
        return ApiResponse::error("Required parameter is missing");
      }
      // get payload
      $socialId   = $request->input('social_id');
      $name   = $request->input('name');
      $email      = strtolower($request->input('email'));
      $isFirstLogin = false;
      // check user by email
      $user = $this->userRepo->findByEmailForSocialLogin($email);

      // create user if not exists
      if (!$user) {
        $isFirstLogin = true;
        $newUser = $this->userRepo->create([
          'name' => $name,
          'email' => $email,
          'password' => null,
          'social_id' => $socialId,
          'deviceType' => $request->input('deviceType'),
          'fcm' => $request->input('fcm'),
          'isAvatar' => false
        ]);

        $user = $newUser;
      } else {
        $updateData = [
          'social_id' => $socialId,
          'deviceType' => $request->input('deviceType'),
          'fcm' => $request->input('fcm'),
        ];

        // Update user
        $this->userRepo->update($user['id'], $updateData);
      }
      
      // getting profile image
      $profileImage = $this->imageHelper->getProfileImage($user);

      // generate jwt
      $userModel = new JwtUser([
        'id' => $user['id'],
        'email' => $user['email'],
      ]);

      $token = JWTAuth::fromUser($userModel);

      $hasPreference = false;
      $preference = $this->preferenceRepo->finPreferenceByUser($user['id']);
      if ($preference && !empty($preference))  $hasPreference = true;


      unset($user['password'], $user['isDeleted'], $user['isVerified']);

      AppLogger::info("Social login success: " . $user['id']);

      return ApiResponse::success(
        "Login successful",
        [
          'token' => $token,
          'type'  => 'Bearer',
          'user'  => [...$user, 'hasPreference' => $hasPreference, 'profileImage' => $profileImage, 'isFirstLogin' => $isFirstLogin],
        ],
        200
      );
    } catch (Exception $e) {

      AppLogger::error("Social login failed: " . $e->getMessage());

      return ApiResponse::error(
        "Social login failed: " . $e->getMessage(),
        500
      );
    }
  }


  /**
   * to send otp for forgot password in input email
   * 
   * @param Request $request
   * @route POST /auth/forgot-password
   */
  public function forgot(Request $request)
  {
    try {
      // getting all data
      $data = $request->all();
      // validating request
      if (!isset($data['email']) || empty($data['email'])) {
        return ApiResponse::error(
          'Email is required.',
          200
        );
      }
      // finding email is exist or not
      $user = $this->userRepo->findByEmail($data['email']);
      // checking email existance
      if (!$user) {
        return ApiResponse::error(
          'Email not found. Please Register first',
          200
        );
      }
      // generating otp
      $otp = generateOtp();
      if (!$otp) {
        AppLogger::error("OTP generation failed for email: " . $data['email']);
        return ApiResponse::error("Failed to generate OTP.", 500);
      }

      // Remove previous OTPs
      $this->otpRepo->deleteByEmail($data['email']);

      // Store OTP
      $this->otpRepo->create([
        'user_id' => $user['id'],
        'email'   => $data['email'],
        'otp'     => $otp,
        'type'    => 'forgot',
      ]);

      // Send OTP email
      $this->mailService->sendOtp(
        $data['email'],
        $otp,
        'forgot'
      );

      return ApiResponse::success(
        'OTP sent successfully.',
        ['email' => $data['email']],
        200
      );
    } catch (Exception $e) {

      AppLogger::error("Login failed: " . $e->getMessage());

      return ApiResponse::error(
        'An error occurred: ' . $e->getMessage(),
        500
      );
    }
  }

  /**
   * To change password after otp verification
   * 
   * @param ResetPasseordRequest $request
   * @route POST /auth/change-password
   */
  public function reset(ResetPasseordRequest $request)
  {
    try {
      // validating request
      $data = $request->validated();

      // Normalize email
      $email = strtolower($data['email']);

      // Check if user exists
      $user = $this->userRepo->findByEmail($email);
      //checking user existance
      if (!$user) {
        return ApiResponse::error(
          'Email not found. Please Register first',
          200
        );
      }
      // checking user is verified or not. because if user tried to forgot password just after signup
      if ($user['isVerified'] == false) {
        return ApiResponse::error(
          'Email is not verified. Please verify your email first',
          200
        );
      }
      //checking otp using reset token
      $otpRecord = $this->otpRepo->findByResetToken($data['reset_token']);

      if (!$otpRecord) {
        return ApiResponse::error('Invalid or expired reset token.', 200);
      }

      if ($otpRecord['email'] !== $email) {
        return ApiResponse::error('Invalid reset attempt.', 200);
      }

      // Update password (Hashing handled inside repository)
      $this->userRepo->update($user['id'], [
        'password' => $data['newPassword'],
      ]);

      $this->otpRepo->deleteByEmail($email); // remove used token

      AppLogger::info("Password reset successfully for user: " . $user['id']);

      return ApiResponse::success(
        'Password updated successfully.',
        [],
        200
      );
    } catch (Exception $e) {

      AppLogger::error("Reset password failed: " . $e->getMessage());

      return ApiResponse::error(
        'An error occurred: ' . $e->getMessage(),
        500
      );
    }
  }

  /**
   * To resend otp for register and forgot both
   * @param Request $request
   * @route POST /auth/resend
   */
  public function resend(Request $request)
  {
    try {
      // getting all data
      $data = $request->all();
      // validating request
      if (!isset($data['email']) || empty($data['email'])) {
        return ApiResponse::error(
          'Email is required.',
          200
        );
      }

      if (!isset($data['type']) || empty($data['type'])) {
        return ApiResponse::error(
          'type is required.',
          200
        );
      }

      // fetching valid otp
      $validOtp = $this->otpRepo->findValidOtp($data['email'], $data['type']);

      if ($validOtp) {
        // Send OTP email
        $this->mailService->sendOtp(
          $data['email'],
          $validOtp['otp'],
          $data['type']
        );

        return ApiResponse::success("OTP resent successfully.");
      } else {
        AppLogger::debug("no valid otp found");
        return ApiResponse::error("No valid otp found", 200);
      }
    } catch (Exception $e) {

      AppLogger::error("Resend failed: " . $e->getMessage());

      return ApiResponse::error(
        'An error occurred: ' . $e->getMessage(),
        500
      );
    }
  }

  /**
   * To logout and destroying current jwt for logged in user
   * @param Request $request
   * @route POST /auth/logout
   */
  public function logout(Request $request)
  {
    try {
      // validating user
      $user = $request->user();
      if (!$user) {
        return ApiResponse::error('Unauthorized.', 401);
      }

      // invalidate JWT token (logout user)
      JWTAuth::invalidate(JWTAuth::getToken());
      JWTAuth::parseToken()->invalidate(true);

      return ApiResponse::success("User logged out successfully");
    } catch (Exception $e) {
      $message = "An exception: " . $e->getMessage();
      AppLogger::error($message);

      return ApiResponse::error($message, 500);
    }
  }
}

<?php

namespace Fleetbase\Support;

use Fleetbase\Models\VerificationCode;
use Aloha\Twilio\Support\Laravel\Facade as Twilio;
use Fleetbase\Models\Setting;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Class TwoFactorAuth
 *
 * @package Fleetbase\Support
 */
class TwoFactorAuth
{
    /**
     * Save Two-Factor Authentication settings.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Exception
     */
    public static function saveSettings($request)
    {
        $twoFaSettings = $request->input('twoFaSettings');
        if (!is_array($twoFaSettings)) {
            throw new \Exception('Invalid 2FA settings data.');
        }
        Setting::configure('2fa', $twoFaSettings);

        return response()->json([
            'status'  => 'ok',
            'message' => '2Fa settings successfully saved.',
        ]);
    }

    /**
     * Get Two-Factor Authentication settings.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public static function getSettings()
    {
        $twoFaSettings = Setting::lookup('2fa', ['enabled' => false, 'method' => 'authenticator_app']);

        return response()->json($twoFaSettings);
    }

    /**
     * Verify Two-Factor Authentication code.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public static function verifyTwoFactor($request)
    {
        if (!RateLimiter::attempt(self::throttleKey($request), self::throttleMaxAttempts(), self::throttleDecayMinutes())) {
            throw ValidationException::withMessages([
                'code' => ['Too many verification attempts. Please try again later.'],
            ])->status(429);
        }

        $user = auth()->user();
        $codeToVerify = $request->input('code');

        $latestCode = VerificationCode::where('subject_uuid', $user->uuid)
            ->where('subject_type', get_class($user))
            ->where('for', 'phone_verification')
            ->latest()
            ->first();

        if (!$latestCode || $latestCode->code !== $codeToVerify || $latestCode->isExpired()) {
            RateLimiter::hit(self::throttleKey($request));

            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid or expired verification code.',
            ], 401);
        }

        self::sendVerificationSuccessSms($user);

        return response()->json([
            'status'  => 'success',
            'message' => 'Verification Successful',
        ]);
    }

    /**
     * Get the throttle key based on the request's IP.
     *
     * @param \Illuminate\Http\Request $request
     * @return string
     */
    protected static function throttleKey($request)
    {
        return 'verify_two_factor_' . $request->ip();
    }

    /**
     * Get the maximum number of attempts allowed in the throttle.
     *
     * @return int
     */
    protected static function throttleMaxAttempts()
    {
        return 5;
    }

    /**
     * Get the decay time in minutes for the throttle.
     *
     * @return int
     */
    protected static function throttleDecayMinutes()
    {
        return 2;
    }

    /**
     * Send a success SMS after successful verification.
     *
     * @param mixed $user
     */
    private static function sendVerificationSuccessSms($user)
    {
        Twilio::message($user->phone, 'Your Fleetbase verification was successful. Welcome!');
    }

    public static function isEnabled()
    {
        return Setting::lookup('2fa', ['enabled']);
    }

    public static function start()
    {
        return true;
    }
}

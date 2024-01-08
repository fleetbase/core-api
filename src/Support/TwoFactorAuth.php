<?php

namespace Fleetbase\Support;

use Carbon\Carbon as CarbonDateTime;
use Fleetbase\Models\VerificationCode;
use Fleetbase\Http\Requests\TwoFaValidationRequest;
use Fleetbase\Models\Setting;
use Fleetbase\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
     * @param \Fleetbase\Http\Requests\TwoFaValidationRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public static function validateSession(TwoFaValidationRequest $request)
    {
        if (!self::isEnabled()) {
            return response()->error('Two Factor Authentication is not enabled.', 400);
        }

        $token = $request->input('token');
        $identity = $request->input('identity');

        $user = User::where(function ($query) use ($identity) {
            $query->where('email', $identity)->orWhere('phone', $identity);
        })->first();

        if ($user) {
            $twoFaSessionKey = 'two_fa_session:' . $user->uuid . ':' . $token;

            // create token creating info about the session
            $expireAfter = Carbon::now()->addSeconds(61);
            $clientSessionToken = base64_encode($expireAfter . '|' . Str::random());

            if (Cache::has($twoFaSessionKey)) {
                // send two factor code
                static::sendVerificationCode($user, $expireAfter);
                return response()->json(['status' => 'ok', 'client_token' => $clientSessionToken]);
            }
        }

        return response()->json([
            'errors' => ['Two factor authentication session is invalid']
        ], 400);
    }

    /**
     * Send Two-Factor Authentication verification code.
     *
     * @param \Fleetbase\Models\User $user
     * @throws \Exception
     */
    public static function sendVerificationCode(User $user, ?CarbonDateTime $expireAfter): void
    {
        $twoFaSettings = Setting::lookup('2fa');
        $method = data_get($twoFaSettings, 'method');

        // if no expiration provided default to 1 min
        if (!$expireAfter) {
            $expireAfter = Carbon::now()->addSeconds(61);
        }

        if ($method === 'sms') {
            // if user has no phone number throw error
            if (!$user->phone) {
                throw new \Exception('No phone number to send 2FA code to.');
            }

            // create verification code
            VerificationCode::generateSmsVerificationFor($user, '2fa', null, [], $expireAfter);
        }

        if ($method === 'email') {
            // if user has no phone number throw error
            if (!$user->email) {
                throw new \Exception('No email to send 2FA code to.');
            }

            // create verification code
            VerificationCode::generateEmailVerificationFor($user, '2fa', null, [], $expireAfter);
        }
    }

    /**
     * Check Two-Factor Authentication status for a given user identity.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public static function checkTwoFactorStatus(Request $request)
    {
        $identity = $request->input('identity');
        $isTwoFaEnabled = self::isEnabled();
        $twoFaSession = null;
        $isTwoFaValidated = false;
        $error = null;

        if ($isTwoFaEnabled) {
            $twoFaSession = self::start($identity);

            if ($twoFaSession === null) {
                $error = 'No user found using the provided identity';
            } else {
                $isTwoFaValidated = self::isTwoFactorSessionValidated($twoFaSession);
            }
        }

        return [
            'isTwoFaEnabled' => $isTwoFaEnabled,
            'isTwoFaValidated' => $isTwoFaValidated,
            'twoFaSession' => $twoFaSession,
            'error' => $error
        ];
    }

    /**
     * Check if Two-Factor Authentication is enabled.
     *
     * @return bool
     */
    public static function isEnabled()
    {
        $twoFaSettings = Setting::lookup('2fa');

        return isset($twoFaSettings['enabled']) ? (bool)$twoFaSettings['enabled'] : false;
    }

    /**
     * Start the Two-Factor Authentication process and return the session key.
     *
     * @return string
     */
    public static function start(string $identity): ?string
    {
        $twoFaSession = Str::random(40);

        $user = User::where(function ($query) use ($identity) {
            $query->where('email', $identity)->orWhere('phone', $identity);
        })->first();

        if ($user) {
            $twoFaSessionKey = 'two_fa_session:' . $user->uuid . ':' . $twoFaSession;
            Cache::put($twoFaSessionKey, $user->uuid, now()->addMinutes(10));
            return $twoFaSession;
        }

        return null;
    }

    /**
     * Check if the Two-Factor Authentication session is validated.
     *
     * @param string|null $twoFaSession - The Two-Factor Authentication session key
     * @return bool - True if the session is validated, false otherwise
     */
    public static function isTwoFactorSessionValidated(?string $twoFaSession = null): bool
    {
        if ($twoFaSession === null) {
            return false;
        }
        // do check here
        return false;
    }

    /**
     * Verify the Two-Factor Authentication code received via SMS.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public static function verifyCode(Request $request)
    {
        $token = $request->input('token');
        $identity = $request->input('identity');

        $user = User::where(function ($query) use ($identity) {
            $query->where('email', $identity)->orWhere('phone', $identity);
        })->first();

        if (!$user) {
            return response()->json([
                'errors' => ['No user found using the provided identity']
            ], 400);
        }

        $twoFaSessionKey = 'two_fa_session:' . $user->uuid . ':' . $token;

        if (Cache::has($twoFaSessionKey)) {
            $verificationCode = $request->input('verificationCode');

            $verifyCode = VerificationCode::where('code', $verificationCode)
                ->where('subject_uuid', $user->uuid)
                ->where('status', 'active')
                ->where('expires_at', '>', now())
                ->first();

            if ($verifyCode) {
                Cache::forget($twoFaSessionKey);

                // authenticate the user
                $ip       = $request->ip();
                $token = $user->createToken($ip);

                return response()->json(['auth_token' => $token->plainTextToken]);
            }
        }
        return response()->json([
            'errors' => ['Invalid verification code']
        ], 400);
    }
}

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
     * @throws \Exception
     * @return array
     */
    public static function saveSettings($request)
    {
        $twoFaSettings = $request->input('twoFaSettings');
        if (!is_array($twoFaSettings)) {
            throw new \Exception('Invalid 2FA settings data.');
        }
        Setting::configure('2fa', $twoFaSettings);

        return [
            'status'  => 'ok',
            'message' => '2Fa settings successfully saved.',
        ];
    }

    /**
     * Get Two-Factor Authentication settings.
     *
     * @return array
     */
    public static function getSettings()
    {
        $twoFaSettings = Setting::lookup('2fa', ['enabled' => false, 'method' => 'authenticator_app']);

        return $twoFaSettings;
    }

    /**
     * Verify Two-Factor Authentication code.
     *
     * @param \Fleetbase\Http\Requests\TwoFaValidationRequest $request
     * @throws \Exception
     * @return array
     */
    public static function validateSession(TwoFaValidationRequest $request): string
    {
        if (!self::isEnabled()) {
            throw new \Exception('Two Factor Authentication is not enabled.');
        }

        $token = $request->input('token');
        $identity = $request->input('identity');
        $clientToken = $request->input('clientToken');

        // IF ALREADY AN ACTIVE CLIENT TOKEN SESSION CHECK FOR VERIFICATION CODE SENT AND JUST RETURN CLIENT TOKEN
        if ($clientToken) {
            $clientTokenDecoded = base64_decode($clientToken);
            $clientTokenParts = explode('|', $clientTokenDecoded);
            $verificationCodeId = $clientTokenParts[1];

            if ($verificationCodeId) {
                $verificationCode = VerificationCode::where('uuid', $verificationCodeId)->exists();

                if ($verificationCode) {
                    return $clientToken;
                }
            }
        }

        $user = User::where(function ($query) use ($identity) {
            $query->where('email', $identity)->orWhere('phone', $identity);
        })->first();

        if ($user) {
            $twoFaSessionKey = 'two_fa_session:' . $user->uuid . ':' . $token;

            if (Cache::has($twoFaSessionKey)) {
                // create token creating info about the session and send verification code
                $expireAfter = Carbon::now()->addSeconds(61);
                $verificationCode = static::sendVerificationCode($user, $expireAfter);
                $clientSessionToken = base64_encode($expireAfter . '|' . $verificationCode->uuid . '|' . Str::random());

                return $clientSessionToken;
            }
        }

        throw new \Exception('Two factor authentication session is invalid');
    }

    /**
     * Send Two-Factor Authentication verification code.
     *
     * @param \Fleetbase\Models\User $user
     * @param \Carbon\Carbon|null $expireAfter
     * @throws \Exception
     */
    public static function sendVerificationCode(User $user, ?CarbonDateTime $expireAfter): VerificationCode
    {
        $twoFaSettings = Setting::lookup('2fa');
        $method = data_get($twoFaSettings, 'method', 'email');

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
            return VerificationCode::generateSmsVerificationFor($user, '2fa', null, [], $expireAfter);
        }

        if ($method === 'email') {
            // if user has no phone number throw error
            if (!$user->email) {
                throw new \Exception('No email to send 2FA code to.');
            }

            // create verification code
            return VerificationCode::generateEmailVerificationFor($user, '2fa', null, [], $expireAfter);
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
     * @param string $identity
     * @return string|null
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
     * @throws \Exception
     * @return array
     */
    public static function verifyCode(Request $request): ?string
    {
        try {
            $token = $request->input('token');
            $identity = $request->input('identity');
            $clientToken = $request->input('clientToken');
            $verificationCode = $request->input('verificationCode');

            $user = User::where(function ($query) use ($identity) {
                $query->where('email', $identity)->orWhere('phone', $identity);
            })->first();

            if (!$user) {
                throw new \Exception('No user found using the provided identity');
            }

            $twoFaSessionKey = 'two_fa_session:' . $user->uuid . ':' . $token;


            if (Cache::has($twoFaSessionKey)) {
                $userInputCode = $request->input('verificationCode');

                if ($clientToken) {
                    $clientTokenDecoded = base64_decode($clientToken);
                    $clientTokenParts = explode('|', $clientTokenDecoded);
                    $verificationCodeId = $clientTokenParts[1];

                    if ($verificationCodeId) {
                        $verificationCode = VerificationCode::where('uuid', $verificationCodeId)->first();

                        if ($verificationCode) {
                            if ($verificationCode->expires_at < now()) {
                                throw new \Exception('Verification code has expired. Please request a new one.');
                            }

                            $verificationCodeMatches = $verificationCode->code === $userInputCode;

                            if ($verificationCodeMatches) {
                                Cache::forget($twoFaSessionKey);

                                // authenticate the user
                                $ip     = $request->ip();
                                $token = $user->createToken($ip);

                                return $token->plainTextToken;
                            } else {
                                throw new \Exception('Verification code does not match. User entered: ' . $userInputCode . ', Expected: ' . $verificationCode->code);
                            }
                        }
                    }
                }
            }

            throw new \Exception('Invalid verification code');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error during verification: ' . $e->getMessage());
            throw $e;
        }
    }

    public static function resendCode(Request $request)
    {
        try {
            $identity = $request->input('identity');
            $twoFaSession = self::start($identity);

            if ($twoFaSession === null) {
                throw new \Exception('No user found using the provided identity');
            }

            $user = User::where(function ($query) use ($identity) {
                $query->where('email', $identity)->orWhere('phone', $identity);
            })->first();

            if (!$user) {
                throw new \Exception('No user found using the provided identity');
            }

            $expireAfter = Carbon::now()->addSeconds(61);
            static::sendVerificationCode($user, $expireAfter);

            return [
                'status'  => 'ok',
                'message' => 'Verification code resent successfully.',
            ];
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error during resendCode: ' . $e->getMessage());
            throw $e;
        }
    }
}

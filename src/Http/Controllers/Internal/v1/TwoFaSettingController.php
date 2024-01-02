<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\VerificationCode;
use Illuminate\Http\Request;
use Aloha\Twilio\Support\Laravel\Facade as Twilio;
use Fleetbase\Models\Setting;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class TwoFaSettingController extends Controller
{
    /**
     * Save user 2FA settings.
     *
     * @param Request $request the HTTP request object containing the 2FA settings data
     *
     * @return \Illuminate\Http\JsonResponse a JSON response
     *
     * @throws \Exception if the provided notification settings data is not an array
     */
    public function saveSettings(Request $request)
    {
        $twoFaSettings = $request->input('twoFaSettings');
        if (!is_array($twoFaSettings)) {
            throw new \Exception('Invalid 2FA settings data.');
        }
        Setting::configure('2fa', $twoFaSettings);

        return response()->json([
            'status'  => 'ok',
            'message' => '2Fa settings succesfully saved.',
        ]);
    }

    public function verifyTwoFactor(Request $request)
    {
        if (!RateLimiter::attempt($this->throttleKey($request),$this->throttleMaxAttempts(),$this->throttleDecayMinutes()))
        {
            throw ValidationException::withMessages([
                'code' => ['Too many verification attempts.Please try again later.'],
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
            RateLimiter::hit($this->throttleKey($request));

            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or expired verification code.',
            ], 401);
        }

        $this->sendVerificationSuccessSms($user);

        return response()->json([
            'status' => 'success',
            'message' => 'Verification Successful',
        ]);
    }

    protected function throttleKey(Request $request)
    {
        return 'verify_two_factor_'.$request->ip();
    }

    protected function throttleMaxAttempts() {
        return 5;
    }

    protected function throttleDecayMinutes()
    {
        return 2;
    }

    private function sendVerificationSuccessSms($user)
    {
        Twilio::message($user->phone, 'Your Fleetbase verification was succesfull. Welcome!');
    }
}

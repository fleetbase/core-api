<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\VerificationCode;
use Illuminate\Http\Request;
use Aloha\Twilio\Support\Laravel\Facade as Twilio;
use Fleetbase\Models\Setting;

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

    /**
     * Generate and send SMS code for 2FA.
     *
     * @param Request $request the HTTP request object
     *
     * @return \Illuminate\Http\JsonResponse a JSON response
     */
    public function generateAndSendSmsCode(Request $request)
    {
        $user = auth()->user();

        // Check if 2FA is enabled for the organization
        $enabledValue = Setting::lookup('Enabled', false); 

        if (!$enabledValue) {
            return response()->json(['status' => 'error', 'message' => '2FA is not enabled for the organization'], 400);
        }

        // Generate a random 6-digit code
        $smsCode = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);

        // Store the SMS code in the VerificationCode table
        $verificationCode = VerificationCode::create([
            'subject_uuid' => $user->uuid,
            'subject_type' => get_class($user),
            'code' => $smsCode,
            'for' => 'phone_verification',
            'expires_at' => now()->addMinutes(5),
            'status' => 'active',
        ]);

        // Send the SMS code to the user's phone number
        try {
            Twilio::message($user->phone, "Your Fleetbase verification code is {$smsCode}");
        } catch (\Exception | \Twilio\Exceptions\RestException $e) {
            $verificationCode->update(['status' => 'failed']);
            return response()->json(['error' => $e->getMessage()], 400);
        }

        return response()->json(['status' => 'ok', 'message' => 'SMS code sent successfully']);
    }

    /**
     * Verify SMS code for 2FA.
     *
     * @param Request $request the HTTP request object containing the entered code
     *
     * @return \Illuminate\Http\JsonResponse a JSON response
     */
    public function verifySmsCode(Request $request)
    {
        $user = auth()->user();
        $enteredCode = $request->input('code');

        // Retrieve the stored SMS code from the VerificationCode table
        $verificationCode = VerificationCode::where([
            'subject_uuid' => $user->uuid,
            'subject_type' => get_class($user),
            'code' => $enteredCode,
            'for' => 'phone_verification',
            'status' => 'active',
        ])->first();

        // Verify the entered code
        if ($verificationCode && !$verificationCode->isExpired()) {
            // Mark the verification code as used
            $verificationCode->update(['status' => 'used']);

            return response()->json(['status' => 'ok', 'message' => 'SMS code is valid']);
        } else {
            // Code is invalid or expired
            return response()->json(['status' => 'error', 'message' => 'Invalid or expired SMS code'], 401);
        }
    }
}

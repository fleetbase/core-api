<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Setting;
use Illuminate\Http\Request;

/**
 * Controller for managing two factor settings.
 */
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
        Setting::configure('two_fa_settings', $twoFaSettings);

        return response()->json([
            'status'  => 'ok',
            'message' => '2Fa settings succesfully saved.',
        ]);
    }
}

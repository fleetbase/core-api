<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class _TempController extends Controller
{
    /**
     * Check to see if company is subscribed to stanrdard subscription.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function checkSubscription()
    {
        return response()->json(
            [
                'is_subscribed' => true,
                'is_trialing' => false,
                'trial_expires_at' => Carbon::today()->addMonth(),
            ]
        );
    }
}

<?php

namespace Fleetbase\Observers;

use Fleetbase\Models\Dashboard;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Exceptions\HttpResponseException;

class DashboardObserver
{
    public function deleting(Dashboard $dashboard)
    {
        if ($dashboard->owner_uuid === 'system') {
            $errors = ['Cannot delete a system dashboard.'];

            throw new ValidationException(
                validator()->make([], []), 
                response()->json(['errors' => $errors], 400)
            );
        }
    }
}

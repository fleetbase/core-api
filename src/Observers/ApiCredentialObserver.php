<?php

namespace Fleetbase\Observers;

use Fleetbase\Models\ApiCredential;

class ApiCredentialObserver
{
    /**
     * Handle the ApiCredential "created" event.
     *
     * @return void
     */
    public function created(ApiCredential $apiCredential)
    {
        // generate the api credentials (random; see ApiCredential::generateKeys)
        $credentials = ApiCredential::generateKeys(null, $apiCredential->test_mode);

        // set the credentials
        $apiCredential->key    = data_get($credentials, 'key');
        $apiCredential->secret = data_get($credentials, 'secret');

        // save credentials
        $apiCredential->save();
    }
}

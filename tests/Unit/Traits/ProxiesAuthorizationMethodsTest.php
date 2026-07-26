<?php

use Fleetbase\Traits\ProxiesAuthorizationMethods;
use Illuminate\Database\Eloquent\Model;

class ProxiesAuthorizationMethodsFallbackModel extends Model
{
    use ProxiesAuthorizationMethods;
}

test('authorization proxy falls back to the model dynamic call handler for unrelated missing methods', function () {
    $model = new ProxiesAuthorizationMethodsFallbackModel();

    expect(fn () => $model->totallyUnknownOperation())->toThrow(BadMethodCallException::class);
});

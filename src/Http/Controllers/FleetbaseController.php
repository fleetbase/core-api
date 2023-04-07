<?php

namespace Fleetbase\Http\Controllers;

use Fleetbase\Traits\HasApiControllerBehavior;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Database\Eloquent\Model;

abstract class FleetbaseController extends BaseController
{
    use AuthorizesRequests,
        DispatchesJobs,
        ValidatesRequests,
        HasApiControllerBehavior;

    public string $namespace = '\\Fleetbase';
        
    public function __construct(?Model $model = null, String $resource = null)
    {
        $this->setApiModel($model, $this->namespace);
        $this->setApiResource($resource, $this->namespace);
    }
}

<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Http\Requests\Internal\CreateCustomFieldRequest;

class CustomFieldController extends FleetbaseController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'custom_field';

    /**
     * The validation request to use.
     *
     * @var CreateCustomFieldRequest
     */
    public $request = CreateCustomFieldRequest::class;
}

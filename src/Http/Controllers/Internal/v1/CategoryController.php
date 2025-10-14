<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Http\Requests\Internal\CreateCategoryRequest;

class CategoryController extends FleetbaseController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'category';

    /**
     * The validation request to use.
     *
     * @var CreateCategoryRequest
     */
    public $request = CreateCategoryRequest::class;
}

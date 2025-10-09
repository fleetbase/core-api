<?php

namespace Fleetbase\Traits;

use Closure;
use Fleetbase\Exceptions\FleetbaseRequestValidationException;
use Fleetbase\Http\Requests\FleetbaseRequest;
use Fleetbase\Http\Requests\Internal\BulkDeleteRequest;
use Fleetbase\Support\Http;
use Fleetbase\Support\Resolve;
use Fleetbase\Support\Utils;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

trait HasApiControllerBehavior
{
    /**
     * The target eloquent data model.
     *
     * @var Model
     */
    public $model;

    /**
     * The target eloquent data model class name.
     *
     * @var string
     */
    public $modelClassName;

    /**
     * The target resource name pluralized.
     *
     * @var string
     */
    public $resourcePluralName;

    /**
     * The target resource name singularized.
     *
     * @var string
     */
    public $resourceSingularlName;

    /**
     * The target API Resource.
     *
     * @var \Fleetbase\Http\Resources\FleetbaseResource
     */
    public $resource;

    /**
     * The target Service the controller belongs to.
     *
     * @var string
     */
    public $service;

    /**
     * The target API Filter.
     *
     * @var \Fleetbase\Http\Filter\Filter
     */
    public $filter;

    /**
     * The current request.
     *
     * @var Request
     */
    public $request;

    /**
     * Determine if the JSON should be compressed.
     *
     * @var Request
     */
    public $compressJson = false;

    /**
     * The request class to be used for create operations on this resource.
     *
     * This can be set to a specific FleetbaseRequest instance or remain null
     * if no create request validation is required.
     */
    protected ?FleetbaseRequest $createRequest = null;

    /**
     * The request class to be used for update operations on this resource.
     *
     * This can be set to a specific FleetbaseRequest instance or remain null
     * if no update request validation is required.
     */
    protected ?FleetbaseRequest $updateRequest = null;

    /**
     * Determines the action to perform based on the HTTP verb.
     *
     * @param string|null $verb The HTTP verb to check. Defaults to the request method if not provided.
     *
     * @return string the action to perform based on the HTTP verb
     */
    private function actionFromHttpVerb(?string $verb = null)
    {
        $verb   = $verb ?? $_SERVER['REQUEST_METHOD'];
        $action = Str::lower($verb);

        switch ($verb) {
            case 'POST':
                $action = 'create';
                break;

            case 'GET':
                $action = 'query';
                break;

            case 'PUT':
            case 'PATCH':
                $action = 'update';
                break;

            case 'DELETE':
                $action = 'delete';
                break;
        }

        return $action;
    }

    /**
     * Set the model instance to use.
     *
     * @param Model $model - The Model Instance
     */
    public function setApiModel(?Model $model = null, string $namespace = '\\Fleetbase')
    {
        $this->modelClassName        = $modelName = Utils::getModelClassName($model ?? $this->resource, $namespace);
        $this->model                 = $model = Resolve::instance($modelName);
        $this->resource              = $this->getApiResourceForModel($model, $namespace);
        $this->service               = $this->getApiServiceFromNamespace($namespace);
        $this->request               = $this->getApiRequestForModel($model, $namespace);
        $this->resourcePluralName    = $model->getPluralName();
        $this->resourceSingularlName = $model->getSingularName();

        if ($this->filter) {
            $this->model->filter = $this->filter;
        }
    }

    /**
     * Set the Resource object to use.
     */
    public function setApiResource($resource, ?string $namespace)
    {
        if (!$this->resource) {
            $this->resource = (is_object($resource) ? get_class($resource) : $resource) ?? $this->getApiResourceForModel($this->model, $namespace);
        }
    }

    /**
     * Set the FormRequest object to use.
     *
     * @param FormRequest|string $request
     */
    public function setApiFormRequest($request)
    {
        $this->request = is_object($request) ? get_class($request) : $request;
    }

    /**
     * Returns the API service name associated with the given namespace or the current class.
     *
     * If no namespace is provided, it defaults to the current class namespace.
     * If the service is not already set, it is generated from the namespace using the getServiceNameFromNamespace method.
     *
     * @param string|null $namespace The namespace to generate the service name from (optional)
     *
     * @return string The API service name
     */
    public function getApiServiceFromNamespace(?string $namespace = null)
    {
        $namespace = $namespace ?? get_class($this);
        $service   = $this->service;

        if (!$service) {
            $service = static::getServiceNameFromNamespace($namespace);
        }

        return $service;
    }

    /**
     * Generates a slugified service name from a given namespace.
     *
     * The service name is generated by taking the first or second segment of the namespace (depending on the number of segments),
     * slugifying it by inserting dashes before uppercase letters, and converting it to lowercase.
     *
     * @param string $namespace The namespace to generate the service name from
     *
     * @return string The generated service name
     */
    private function getServiceNameFromNamespace(string $namespace)
    {
        $segments         = array_values(array_filter(explode('\\', $namespace)));
        $targetSegment    = count($segments) === 1 ? $segments[0] : $segments[1];
        $slugifiedSegment = preg_replace('/(?<=[a-z])(?=[A-Z])/', '-', $targetSegment);
        $slugifiedSegment = strtolower($slugifiedSegment);

        return $slugifiedSegment;
    }

    /**
     * Resolves the api resource for this model.
     *
     * @param \Fleetbase\Models\Model $model
     *
     * @return \Fleetbase\Http\Resources\FleetbaseResource
     */
    public function getApiResourceForModel(Model $model, ?string $namespace = null)
    {
        $resource = $this->resource;

        if (!$resource || !Str::startsWith($resource, '\\')) {
            $resource = Resolve::httpResourceForModel($model, $namespace);
        }

        return $resource;
    }

    /**
     * Resolves the form request for this model.
     *
     * @param \Fleetbase\Models\Model $model
     *
     * @return FleetbaseRequest
     */
    public function getApiRequestForModel(Model $model, ?string $namespace = null)
    {
        $request = $this->request;

        if (!$request) {
            $request = Resolve::httpRequestForModel($this->model, $namespace);
        }

        return $request;
    }

    /**
     * Gets the singular name of the resource.
     *
     * Returns the singular name of the resource, e.g. "user" for a UserController.
     *
     * @return string The singular name of the resource
     */
    public function getResourceSingularName(): string
    {
        return $this->resourceSingularlName;
    }

    /**
     * Gets the service associated with the controller.
     *
     * Returns the fully qualified name of the service namespace that is used by
     * the controller to perform business logic operations.
     *
     * @return string The fully qualified name of the service
     */
    public function getService(): string
    {
        return $this->service;
    }

    /**
     * Resolves the resource form request and validates.
     *
     * @return void
     *
     * @throws FleetbaseRequestValidationException
     */
    public function validateRequest(Request $request)
    {
        $input = $this->model?->getApiPayloadFromRequest($request) ?? [];

        if (Utils::classExists($this->request)) {
            $formRequest = new $this->request($request->all());
            $validator   = Validator::make($input, $formRequest->rules(), $formRequest->messages());

            if ($validator->fails()) {
                throw new FleetbaseRequestValidationException($validator->errors());
            }
        }

        // Specific validators
        if ($this->hasCreateRequest() && $request->isMethod('POST')) {
            $createRequestClass = $this->getCreateRequest();
            $createRequest      = $createRequestClass::createFrom($request);
            $rules              = $createRequest->rules();

            // Run validator
            $validator = Validator::make($input, $rules);

            if ($validator->fails()) {
                throw new FleetbaseRequestValidationException($validator->errors());
            }
        }

        // Specific validators
        if ($this->hasUpdateRequest() && $request->isMethod('PUT')) {
            $updateRequestClass = $this->getUpdateRequest();
            $updateRequest      = $updateRequestClass::createFrom($request);
            $rules              = $updateRequest->rules();

            // Run validator
            $validator = Validator::make($input, $rules);

            if ($validator->fails()) {
                throw new FleetbaseRequestValidationException($validator->errors());
            }
        }
    }

    /**
     * Get All.
     *
     * Returns a list of items in this resource and allows filtering the data based on fields in the database
     *
     * Options for searching / filtering
     * - By field name: e.g. `?name=John` - Specific search
     * - By field name with `LIKE` operator: e.g. `?name_like=John` - Fuzzy search
     * - By field name with `!=` operator: e.g. `?age_not=5`
     * - By field name with `>` or `<` operator: e.g. `?age_gt=5` or `?age_lt=10`
     * - By field name with `>=` or `<=` operator: e.g. `?age_gte=5` or `?age_lte=10`
     * - By field name with `IN` or `NOT IN` operator: e.g. `?id_in=1,3,5` or `?id_notIn=2,4`
     * - By field name with `NULL` or `NOT NULL` operator: e.g. `?email_isNull` or `?email_isNotNull`
     *
     * @queryParam limit Total items to return e.g. `?limit=15`. Example: 3
     * @queryParam page Page of items to return e.g. `?page=1`. Example: 1
     * @queryParam sort Sorting options e.g. `?sort=field1:asc,field2:asc` OR `?sort=latest/oldest` OR `?sort=-created,created`. Example: latest
     * @queryParam count Count related models. Alternatively `with_count` e.g. `?count=relation1,relation2`. No-example
     * @queryParam contain Contain data from related model e.g. `?contain=relation1,relation2`. No-example
     * @queryParam fieldName Pass any field and value to filter results e.g. `name=John&email=any@aol.com`. No-example
     *
     * @authenticated
     *
     * @return \Illuminate\Http\Response
     */
    public function queryRecord(Request $request)
    {
        $single        = $request->boolean('single');
        $queryCallback = $this->getControllerCallback('onQueryRecord');
        $data          = $this->model->queryFromRequest($request, $queryCallback);

        if ($single) {
            $data = Arr::first($data);

            if (!$data) {
                return response()->error($this->getHumanReadableResourceName() . ' not found', 404);
            }

            if (Http::isInternalRequest($request)) {
                $this->resource::wrap($this->resourceSingularlName);

                return new $this->resource($data);
            }

            return new $this->resource($data);
        }

        if (Http::isInternalRequest($request)) {
            $this->resource::wrap($this->resourcePluralName);

            return $this->resource::collection($data);
        }

        return $this->resource::collection($data);
    }

    /**
     * View Resource.
     *
     * Returns information about a specific record in this resource. You can return related data or counts of related data
     * in the response using the `count` and `contain` query params
     *
     * @authenticated
     *
     * @queryParam count Count related models. Alternatively `with_count` e.g. `?count=relation1,relation2`. No-example
     * @queryParam contain Contain data from related model e.g. `?contain=relation1,relation2`. No-example
     *
     * @urlParam id integer required The id of the resource to view
     *
     * @response 404 {
     *  "status": "failed",
     *  "message": "Resource not found"
     * }
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function findRecord(Request $request, $id)
    {
        $record = $this->model->getById($id, $request);

        if ($record) {
            return [$this->resourceSingularlName => new $this->resource($record)];
        }

        return response()->error($this->getHumanReadableResourceName() . ' not found', 404);
    }

    /**
     * Create Resource.
     *
     * Create a new record of this resource in the database. You can return related data or counts of related data
     * in the response using the `count` and `contain` query params
     *
     * @authenticated
     *
     * @queryParam count Count related models. Alternatively `with_count` e.g. `?count=relation1,relation2`. No-example
     * @queryParam with|expand Contain data from related model e.g. `?with=relation1,relation2`. No-example
     *
     * @response 400 {
     *  "status": "error",
     *  "message": [
     *     "validation error message"
     *  ]
     * }
     * @response 500 {
     *  "status": "error",
     *  "message": "Details of error message"
     * }
     *
     * @return \Illuminate\Http\Response
     */
    public function createRecord(Request $request)
    {
        try {
            $onBeforeCallback = $this->getControllerCallback('onBeforeCreate');
            $onAfterCallback  = $this->getControllerCallback('onAfterCreate') ?? $this->getControllerCallback('afterSave');

            $this->validateRequest($request);
            $record = $this->model->createRecordFromRequest($request, $onBeforeCallback, $onAfterCallback);

            if (Http::isInternalRequest($request)) {
                $this->resource::wrap($this->resourceSingularlName);

                return new $this->resource($record);
            }

            return new $this->resource($record);
        } catch (QueryException $e) {
            dd($e);
            return response()->error(env('DEBUG') ? $e->getMessage() : 'Error occurred while trying to create a ' . $this->getHumanReadableResourceName());
        } catch (FleetbaseRequestValidationException $e) {
            return response()->error($e->getErrors());
        } catch (\Exception $e) {
            return response()->error(env('DEBUG') ? $e->getMessage() : 'Error occurred while trying to create a ' . $this->getHumanReadableResourceName());
        }
    }

    /**
     * Update Resource.
     *
     * Updates the data of the record with the specified `id`. You can return related data or counts of related data
     * in the response using the `count` and `contain` query params
     *
     * @authenticated
     *
     * @queryParam count Count related models. Alternatively `with_count` e.g. `?count=relation1,relation2`. No-example
     * @queryParam contain Contain data from related model e.g. `?contain=relation1,relation2`. No-example
     *
     * @response 400 {
     *  "status": "error",
     *  "message": [
     *     "validation error messages"
     *  ]
     * }
     * @response 404 {
     *  "status": "failed",
     *  "message": "Resource not found"
     * }
     * @response 500 {
     *  "status": "error",
     *  "message": "Details of error message"
     * }
     *
     * @return \Illuminate\Http\Response
     */
    public function updateRecord(Request $request, string $id)
    {
        try {
            $onBeforeCallback = $this->getControllerCallback('onBeforeUpdate');
            $onAfterCallback  = $this->getControllerCallback('onAfterUpdate') ?? $this->getControllerCallback('afterSave');

            $this->validateRequest($request);
            $record = $this->model->updateRecordFromRequest($request, $id, $onBeforeCallback, $onAfterCallback);

            if (Http::isInternalRequest($request)) {
                $this->resource::wrap($this->resourceSingularlName);

                return new $this->resource($record);
            }

            return new $this->resource($record);
        } catch (QueryException $e) {
            return response()->error(env('DEBUG') ? $e->getMessage() : 'Error occurred while trying to update a ' . $this->getHumanReadableResourceName());
        } catch (FleetbaseRequestValidationException $e) {
            return response()->error($e->getErrors());
        } catch (\Exception $e) {
            return response()->error(env('DEBUG') ? $e->getMessage() : 'Error occurred while trying to update a ' . $this->getHumanReadableResourceName());
        }
    }

    /**
     * Delete Resource.
     *
     * Deletes the record with the specified `id`
     *
     * @authenticated
     *
     * @response {
     *  "status": "success",
     *  "message": "Resource deleted",
     *  "data": {
     *     "id": 1
     *  }
     * }
     * @response 404 {
     *  "status": "failed",
     *  "message": "Resource not found"
     * }
     *
     * @param string $id
     *
     * @return \Illuminate\Http\Response
     */
    public function deleteRecord($id, Request $request)
    {
        if (Http::isInternalRequest($request)) {
            $key       = $this->model->getKeyName();
            $builder   = $this->model->where($key, $id);
        } else {
            $builder = $this->model->wherePublicId($id);
        }
        $builder   = $this->model->applyDirectivesToQuery($request, $builder);
        $dataModel = $builder->first();

        if ($dataModel) {
            $dataModel->delete();

            if (Http::isInternalRequest($request)) {
                $this->resource::wrap($this->resourceSingularlName);

                return new $this->resource($dataModel);
            }

            return response()->json(
                [
                    'status'  => 'success',
                    'message' => $this->getHumanReadableResourceName() . ' deleted',
                    'data'    => new $this->resource($dataModel),
                ]
            );
        }

        return response()->json(
            [
                'status'  => 'failed',
                'message' => $this->getHumanReadableResourceName() . ' not found',
            ],
            404
        );
    }

    /**
     * Delete Resource.
     *
     * Deletes the record with the specified `id`
     *
     * @authenticated
     *
     * @response {
     *  "status": "success",
     *  "message": "Resource deleted",
     *  "data": {
     *     "id": 1
     *  }
     * }
     * @response 404 {
     *  "status": "failed",
     *  "message": "Resource not found"
     * }
     *
     * @return \Illuminate\Http\Response
     */
    public function bulkDelete(BulkDeleteRequest $request)
    {
        $ids   = $request->input('ids', []);
        $count = 0;

        try {
            $count = $this->model->bulkRemove($ids);
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        } catch (QueryException $e) {
            return response()->error($e->getMessage());
        } catch (FleetbaseRequestValidationException $e) {
            return response()->error($e->getErrors());
        }

        return response()->json(
            [
                'status'  => 'success',
                'message' => 'Deleted ' . $count . ' ' . Str::plural($this->resourceSingularlName, $count),
                'count'   => $count,
            ]
        );
    }

    /**
     * Search Resources.
     *
     * Allows searching for data in this resource using multiple options.
     *
     * Options for searching
     * - By field name: e.g. `?name=John` - Specific search
     * - By field name with `LIKE` operator: e.g. `?name_like=John` - Fuzzy search
     * - By field name with `!=` operator: e.g. `?age_not=5`
     * - By field name with `>` or `<` operator: e.g. `?age_gt=5` or `?age_lt=10`
     * - By field name with `>=` or `<=` operator: e.g. `?age_gte=5` or `?age_lte=10`
     * - By field name with `IN` or `NOT IN` operator: e.g. `?id_in=1,3,5` or `?id_notIn=2,4`
     * - By field name with `NULL` or `NOT NULL` operator: e.g. `?email_isNull` or `?email_isNotNull`
     *
     * @queryParam limit Total items to return e.g. `?limit=15`. Example: 3
     * @queryParam page Page of items to return e.g. `?page=1`. Example: 1
     * @queryParam sort Sorting options e.g. `?sort=field1:asc,field2:asc` OR `?sort=latest/oldest`. Example: latest
     * @queryParam count Count related models. Alternatively `with_count` e.g. `?count=relation1,relation2`. No-example
     * @queryParam contain Contain data from related model e.g. `?contain=relation1,relation2`. No-example
     * @queryParam fieldName Pass any field and value to search by e.g. `name=John&email=any@aol.com`. Search logic may use LIKE or `=` depending on field
     *
     * @authenticated
     */
    public function search(Request $request)
    {
        $results = $this->model->search($request);

        return $this->resource::collection($results);
    }

    /**
     * Count Resources.
     *
     * Returns a simple count of data in this resource
     *
     * @queryParam fieldName Pass any field and value to search by e.g. `name=John&email=any@aol.com`. Search logic may use LIKE or `=` depending on field. No-example
     *
     * @authenticated
     */
    public function count(Request $request)
    {
        $results = $this->model->count($request);

        return response()->json(['count' => $results]);
    }

    /**
     * Retrieves a Closure for a specified method of the controller if it exists.
     *
     * This method checks if a method with the given name exists in the current controller instance.
     * If the method exists, it returns a Closure that, when invoked, will call the specified method
     * with any provided arguments. This allows for dynamic method invocation while ensuring the method's existence.
     *
     * @param string $name the name of the controller method to retrieve as a Closure
     *
     * @return \Closure|null a Closure that calls the specified method, or null if the method does not exist
     */
    private function getControllerCallback(string $name): ?\Closure
    {
        if (method_exists($this, $name)) {
            return function (...$args) use ($name) {
                return $this->{$name}(...$args);
            };
        }

        return null;
    }

    /**
     * Get the configured update request class for this resource, if any.
     */
    public function getUpdateRequest(): ?FleetbaseRequest
    {
        return $this->updateRequest;
    }

    /**
     * Get the configured create request class for this resource, if any.
     */
    public function getCreateRequest(): ?FleetbaseRequest
    {
        return $this->createRequest;
    }

    /**
     * Determine if this resource has an update request class defined.
     *
     * @return bool true if an update request is set and is a FleetbaseRequest instance
     */
    public function hasUpdateRequest(): bool
    {
        return $this->getUpdateRequest() instanceof FleetbaseRequest;
    }

    /**
     * Determine if this resource has a create request class defined.
     *
     * @return bool true if a create request is set and is a FleetbaseRequest instance
     */
    public function hasCreateRequest(): bool
    {
        return $this->getCreateRequest() instanceof FleetbaseRequest;
    }

    public function getHumanReadableResourceName():string
    {
        return Str::title(str_replace(['_', '-'], ' ', $this->resourceSingularlName));
    }
}

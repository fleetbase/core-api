<?php

namespace Fleetbase\Traits;

use Fleetbase\Exceptions\FleetbaseRequestValidationException;
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
     * @var \Illuminate\Database\Eloquent\Model
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
     * The target API Filter.
     *
     * @var \Fleetbase\Http\Filter\Filter
     */
    public $filter;

    /**
     * The current request.
     *
     * @var \Illuminate\Http\Request
     */
    public $request;

    /**
     * Determines the action to perform based on the HTTP verb.
     *
     * @param string|null $verb The HTTP verb to check. Defaults to the request method if not provided.
     *
     * @return string the action to perform based on the HTTP verb
     */
    private function actionFromHttpVerb(string $verb = null)
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
     * @param \Illuminate\Database\Eloquent\Model $model - The Model Instance
     */
    public function setApiModel(Model $model = null, string $namespace = '\\Fleetbase')
    {
        $this->modelClassName        = $modelName = Utils::getModelClassName($model ?? $this->resource, $namespace);
        $this->model                 = $model = Resolve::instance($modelName);
        $this->resource              = $this->getApiResourceForModel($model, $namespace);
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
     * Resolves the api resource for this model.
     *
     * @param \Fleetbase\Models\Model $model
     *
     * @return \Fleetbase\Http\Resources\FleetbaseResource
     */
    public function getApiResourceForModel(Model $model, string $namespace = null)
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
     * @return \Fleetbase\Http\Requests\FleetbaseRequest
     */
    public function getApiRequestForModel(Model $model, string $namespace = null)
    {
        $request = $this->request;

        if (!$request) {
            $request = Resolve::httpRequestForModel($this->model, $namespace);
        }

        return $request;
    }

    /**
     * Resolves the resource form request and validates.
     *
     * @return void
     *
     * @throws \Fleetbase\Exceptions\FleetbaseRequestValidationException
     */
    public function validateRequest(Request $request)
    {
        if (class_exists($this->request)) {
            $formRequest = new $this->request($request->all());
            $validator   = Validator::make($request->all(), $formRequest->rules(), $formRequest->messages());

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
        $single = $request->boolean('single');
        $data   = $this->model->queryFromRequest($request);

        if ($single) {
            $data = Arr::first($data);

            if (!$data) {
                return response()->error(Str::title($this->resourceSingularlName) . ' not found', 404);
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

        return response()->error(Str::title($this->resourceSingularlName) . ' not found', 404);
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
            $this->validateRequest($request);
            $record = $this->model->createRecordFromRequest($request);

            if (Http::isInternalRequest($request)) {
                $this->resource::wrap($this->resourceSingularlName);

                return new $this->resource($record);
            }

            return new $this->resource($record);
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        } catch (QueryException $e) {
            return response()->error($e->getMessage());
        } catch (FleetbaseRequestValidationException $e) {
            return response()->error($e->getErrors());
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
            $this->validateRequest($request);
            $record = $this->model->updateRecordFromRequest($request, $id);

            if (Http::isInternalRequest($request)) {
                $this->resource::wrap($this->resourceSingularlName);

                return new $this->resource($record);
            }

            return new $this->resource($record);
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        } catch (QueryException $e) {
            return response()->error($e->getMessage());
        } catch (FleetbaseRequestValidationException $e) {
            return response()->error($e->getErrors());
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
            $dataModel = $this->model->where($key, $id)->first();
        } else {
            $dataModel = $this->model->wherePublicId($id)->first();
        }

        if ($dataModel) {
            $dataModel->delete();

            if (Http::isInternalRequest($request)) {
                $this->resource::wrap($this->resourceSingularlName);

                return new $this->resource($dataModel);
            }

            return response()->json(
                [
                    'status'  => 'success',
                    'message' => Str::title($this->resourceSingularlName) . ' deleted',
                    'data'    => new $this->resource($dataModel),
                ]
            );
        }

        return response()->json(
            [
                'status'  => 'failed',
                'message' => Str::title($this->resourceSingularlName) . ' not found',
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
}

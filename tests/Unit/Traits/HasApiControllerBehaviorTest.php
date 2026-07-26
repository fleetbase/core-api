<?php

use Fleetbase\Http\Requests\Internal\BulkDeleteRequest;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Traits\HasApiControllerBehavior;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpException;

class HasApiControllerBehaviorModel extends Model
{
    protected $table      = 'widgets';
    protected $primaryKey = 'uuid';
    public $incrementing  = false;
    protected $keyType    = 'string';
    protected $guarded    = [];
    public array $calls   = [];
    public mixed $queryResults;
    public mixed $searchResults;
    public ?self $foundRecord     = null;
    public ?self $deletableRecord = null;
    public int $bulkRemoveCount   = 0;
    public bool $hasCompanyColumn = true;
    public ?object $lastBuilder   = null;

    public function getPluralName(): string
    {
        return 'widgets';
    }

    public function getSingularName(): string
    {
        return 'widget';
    }

    public function getApiPayloadFromRequest(Request $request): array
    {
        return $request->all();
    }

    public function queryFromRequest(Request $request, ?Closure $callback = null): Collection
    {
        $this->calls[] = ['queryFromRequest', $request->query()];
        if ($callback) {
            $callback($request);
        }

        return $this->queryResults ?? collect();
    }

    public function getById($id, ?Closure $callback = null, ?Request $request = null): ?self
    {
        $this->calls[] = ['getById', $id, $request?->query()];
        if ($callback) {
            $callback($id, $request);
        }

        return $this->foundRecord;
    }

    public function createRecordFromRequest(Request $request, ?callable $onBefore = null, ?callable $onAfter = null): self
    {
        $record = new self(['uuid' => 'created-widget', 'public_id' => 'widget_created', 'name' => $request->input('name')]);
        if ($onBefore) {
            $onBefore($request, $record);
        }
        if ($onAfter) {
            $onAfter($request, $record);
        }

        return $record;
    }

    public function updateRecordFromRequest(Request $request, $id, ?callable $onBefore = null, ?callable $onAfter = null): self
    {
        $record = new self(['uuid' => $id, 'public_id' => 'widget_updated', 'name' => $request->input('name')]);
        if ($onBefore) {
            $onBefore($request, $record);
        }
        if ($onAfter) {
            $onAfter($request, $record);
        }

        return $record;
    }

    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        return $this->lastBuilder = new HasApiControllerBehaviorBuilder($this, [[$column, $operator, $value, $boolean]]);
    }

    public function wherePublicId($id)
    {
        return $this->lastBuilder = new HasApiControllerBehaviorBuilder($this, [['public_id', '=', $id, 'and']]);
    }

    public function qualifyColumn($column)
    {
        return $this->getTable() . '.' . $column;
    }

    public function isColumn($column): bool
    {
        return $this->hasCompanyColumn && str_ends_with($column, 'company_uuid');
    }

    public function applyDirectivesToQuery(Request $request, $builder)
    {
        $this->calls[] = ['applyDirectivesToQuery', $request->query()];

        return $builder;
    }

    public function search(Request $request): Collection
    {
        $this->calls[] = ['search', $request->query()];

        return $this->searchResults ?? collect();
    }

    public function count($columns = '*'): int
    {
        $this->calls[] = ['count', $columns];

        return 42;
    }

    public function bulkRemove(array $ids): int
    {
        $this->calls[] = ['bulkRemove', $ids];

        return $this->bulkRemoveCount ?: count($ids);
    }
}

if (!class_exists('Fleetbase\\Models\\HasApiControllerBehaviorModel')) {
    class_alias(HasApiControllerBehaviorModel::class, 'Fleetbase\\Models\\HasApiControllerBehaviorModel');
}

class HasApiControllerBehaviorBuilder
{
    public array $wheres;

    public function __construct(private HasApiControllerBehaviorModel $model, array $wheres)
    {
        $this->wheres = $wheres;
    }

    public function where($column, $operator = null, $value = null, $boolean = 'and'): self
    {
        $this->wheres[] = [$column, $operator, $value, $boolean];

        return $this;
    }

    public function first(): ?HasApiControllerBehaviorModel
    {
        return $this->model->deletableRecord;
    }
}

class HasApiControllerBehaviorResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        return [
            'uuid'      => $this->resource->uuid,
            'public_id' => $this->resource->public_id,
            'name'      => $this->resource->name,
        ];
    }
}

class HasApiControllerBehaviorIndexResource extends HasApiControllerBehaviorResource
{
}

class HasApiControllerBehaviorController
{
    use HasApiControllerBehavior;

    public array $hookCalls = [];
    public array $rules     = [];

    public function __construct(?HasApiControllerBehaviorModel $model = null)
    {
        $this->model                 = $model ?? new HasApiControllerBehaviorModel();
        $this->resource              = HasApiControllerBehaviorResource::class;
        $this->resourcePluralName    = 'widgets';
        $this->resourceSingularlName = 'widget';
        $this->service               = 'testing';
    }

    public function exposeActionFromHttpVerb(?string $verb = null): string
    {
        return $this->actionFromHttpVerb($verb);
    }

    public function onQueryRecord(...$args): void
    {
        $this->hookCalls[] = ['onQueryRecord', $args];
    }

    public function onFindRecord(...$args): void
    {
        $this->hookCalls[] = ['onFindRecord', $args];
    }

    public function onBeforeCreate(...$args): void
    {
        $this->hookCalls[] = ['onBeforeCreate', $args];
    }

    public function onAfterCreate(...$args): void
    {
        $this->hookCalls[] = ['onAfterCreate', $args];
    }

    public function onBeforeUpdate(...$args): void
    {
        $this->hookCalls[] = ['onBeforeUpdate', $args];
    }

    public function onAfterUpdate(...$args): void
    {
        $this->hookCalls[] = ['onAfterUpdate', $args];
    }
}

class HasApiControllerBehaviorRouteStub
{
    public function __construct(private string $uri, public array $action = [])
    {
    }

    public function uri(): string
    {
        return $this->uri;
    }
}

class HasApiControllerBehaviorValidatorFactory
{
    public function make(array $input, array $rules = [], array $messages = [], array $attributes = []): object
    {
        return new class($input, $rules) {
            public function __construct(private array $input, private array $rules)
            {
            }

            public function fails(): bool
            {
                foreach ($this->rules as $field => $rules) {
                    $rules = is_array($rules) ? $rules : explode('|', (string) $rules);
                    if (in_array('required', $rules, true) && !array_key_exists($field, $this->input)) {
                        return true;
                    }
                }

                return false;
            }

            public function errors(): array
            {
                return ['validation' => ['The given data was invalid.']];
            }
        };
    }
}

class HasApiControllerBehaviorCreateRequest extends FormRequest
{
    public static array $withValidatorCalls = [];

    public function rules(): array
    {
        return ['name' => ['required']];
    }

    public function messages(): array
    {
        return ['name.required' => 'Widget name is required.'];
    }

    public function attributes(): array
    {
        return ['name' => 'widget name'];
    }

    public function withValidator($validator): void
    {
        self::$withValidatorCalls[] = $validator;
    }

    public function authorize(): bool
    {
        return true;
    }
}

class HasApiControllerBehaviorUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return ['sku' => ['required']];
    }
}

class HasApiControllerBehaviorConfiguredRequest extends FormRequest
{
    public function rules(): array
    {
        return ['token' => ['required']];
    }
}

class HasApiControllerBehaviorDeniedRequest extends FormRequest
{
    public function rules(): array
    {
        return [];
    }

    public function authorize(): bool
    {
        return false;
    }
}

if (!function_exists('abort')) {
    function abort(int $code, string $message = ''): never
    {
        throw new HttpException($code, $message);
    }
}

function has_api_controller_behavior_request(string $uri, string $method = 'GET', array $parameters = []): Request
{
    $container = bind_test_container();
    $container->instance('validator', new HasApiControllerBehaviorValidatorFactory());
    $container->instance(Redirector::class, new stdClass());
    Facade::clearResolvedInstances();
    session()->flush();

    $request = Request::create($uri, $method, $parameters);
    $request->setRouteResolver(fn () => new HasApiControllerBehaviorRouteStub($uri));
    app()->instance('request', $request);

    return $request;
}

test('api controller behavior maps http verbs and exposes configured names', function () {
    $controller                = new HasApiControllerBehaviorController();
    $_SERVER['REQUEST_METHOD'] = 'DELETE';

    expect($controller->exposeActionFromHttpVerb('POST'))->toBe('create')
        ->and($controller->exposeActionFromHttpVerb('GET'))->toBe('query')
        ->and($controller->exposeActionFromHttpVerb('PUT'))->toBe('update')
        ->and($controller->exposeActionFromHttpVerb('PATCH'))->toBe('update')
        ->and($controller->exposeActionFromHttpVerb('DELETE'))->toBe('delete')
        ->and($controller->exposeActionFromHttpVerb('TRACE'))->toBe('trace')
        ->and($controller->exposeActionFromHttpVerb())->toBe('delete')
        ->and($controller->getResourceSingularName())->toBe('widget')
        ->and($controller->getService())->toBe('testing');

    $controller->service = null;

    expect($controller->getApiServiceFromNamespace('\\Fleetbase\\FleetOps\\Http\\Controllers'))->toBe('fleet-ops')
        ->and($controller->getApiServiceFromNamespace('\\Standalone'))->toBe('standalone')
        ->and($controller->getHumanReadableResourceName())->toBe('Widget');

    $controller->resource = null;
    $controller->setApiResource(new HasApiControllerBehaviorResource(new HasApiControllerBehaviorModel()), '\\Fleetbase');
    $controller->setApiFormRequest(new HasApiControllerBehaviorConfiguredRequest());

    expect($controller->resource)->toBe(HasApiControllerBehaviorResource::class)
        ->and($controller->request)->toBe(HasApiControllerBehaviorConfiguredRequest::class);

    $controller->resource = '\\' . HasApiControllerBehaviorResource::class;
    $controller->request  = HasApiControllerBehaviorConfiguredRequest::class;
    $controller->filter   = (object) ['scope' => 'active'];
    $controller->setApiModel(new HasApiControllerBehaviorModel(), '\\Fleetbase');

    expect($controller->model->filter)->toEqual((object) ['scope' => 'active']);
});

test('api controller behavior returns single list find search count and bulk delete contracts', function () {
    $model               = new HasApiControllerBehaviorModel();
    $model->queryResults = collect([
        new HasApiControllerBehaviorModel(['uuid' => 'widget-1', 'public_id' => 'widget_public_1', 'name' => 'Primary']),
        new HasApiControllerBehaviorModel(['uuid' => 'widget-2', 'public_id' => 'widget_public_2', 'name' => 'Secondary']),
    ]);
    $model->foundRecord   = new HasApiControllerBehaviorModel(['uuid' => 'widget-found', 'public_id' => 'widget_found', 'name' => 'Found']);
    $model->searchResults = collect([
        new HasApiControllerBehaviorModel(['uuid' => 'widget-search', 'public_id' => 'widget_search', 'name' => 'Search']),
    ]);
    $model->bulkRemoveCount = 3;

    $controller                = new HasApiControllerBehaviorController($model);
    $controller->indexResource = HasApiControllerBehaviorIndexResource::class;

    $single                     = $controller->queryRecord(has_api_controller_behavior_request('/v1/widgets', 'GET', ['single' => true]));
    $internalSingle             = $controller->queryRecord(has_api_controller_behavior_request('/int/v1/widgets', 'GET', ['single' => true]));
    $missingModel               = new HasApiControllerBehaviorModel();
    $missingModel->queryResults = collect();
    $missing                    = (new HasApiControllerBehaviorController($missingModel))->queryRecord(has_api_controller_behavior_request('/v1/widgets', 'GET', ['single' => true]));
    $list                       = $controller->queryRecord(has_api_controller_behavior_request('/v1/widgets', 'GET'));
    $internalList               = $controller->queryRecord(has_api_controller_behavior_request('/int/v1/widgets', 'GET'));
    $found                      = $controller->findRecord(has_api_controller_behavior_request('/v1/widgets/widget_found'), 'widget_found');
    $notFound                   = (new HasApiControllerBehaviorController(new HasApiControllerBehaviorModel()))->findRecord(has_api_controller_behavior_request('/v1/widgets/missing'), 'missing');
    $search                     = $controller->search(has_api_controller_behavior_request('/v1/widgets/search', 'GET', ['query' => 'Search']));
    $count                      = $controller->count(has_api_controller_behavior_request('/v1/widgets/count'));
    $bulkDeleteRequest          = BulkDeleteRequest::create('/v1/widgets/bulk-delete', 'DELETE', ['ids' => ['a', 'b', 'c']]);
    $bulkDelete                 = $controller->bulkDelete($bulkDeleteRequest);
    $bulkDeleteFailureModel     = new class extends HasApiControllerBehaviorModel {
        public function bulkRemove(array $ids): int
        {
            throw new RuntimeException('bulk delete failed');
        }
    };
    $bulkDeleteFailure = (new HasApiControllerBehaviorController($bulkDeleteFailureModel))->bulkDelete(
        BulkDeleteRequest::create('/v1/widgets/bulk-delete', 'DELETE', ['ids' => ['a']])
    );

    expect($single->resolve())->toMatchArray(['uuid' => 'widget-1', 'name' => 'Primary'])
        ->and($internalSingle->resolve())->toMatchArray(['uuid' => 'widget-1', 'name' => 'Primary'])
        ->and($missing->getStatusCode())->toBe(404)
        ->and($missing->getData(true))->toBe(['errors' => ['Widget not found']])
        ->and($list->collects)->toBe(HasApiControllerBehaviorIndexResource::class)
        ->and($internalList->collects)->toBe(HasApiControllerBehaviorIndexResource::class)
        ->and($found['widget']->resolve())->toMatchArray(['uuid' => 'widget-found', 'name' => 'Found'])
        ->and($notFound->getStatusCode())->toBe(404)
        ->and($notFound->getData(true))->toBe(['errors' => ['Widget not found']])
        ->and($search->collects)->toBe(HasApiControllerBehaviorResource::class)
        ->and($count->getData(true))->toBe(['count' => 42])
        ->and($bulkDelete->getData(true))->toBe([
            'status'  => 'success',
            'message' => 'Deleted 3 widgets',
            'count'   => 3,
        ])
        ->and($bulkDeleteFailure->getData(true))->toBe(['errors' => ['bulk delete failed']])
        ->and(array_column($controller->hookCalls, 0))->toContain('onQueryRecord')
        ->and(array_column($controller->hookCalls, 0))->toContain('onFindRecord');
});

test('api controller behavior creates updates and formats exception responses', function () {
    $controller = new HasApiControllerBehaviorController();

    $created         = $controller->createRecord(has_api_controller_behavior_request('/v1/widgets', 'POST', ['name' => 'Created']));
    $updated         = $controller->updateRecord(has_api_controller_behavior_request('/v1/widgets/widget-1', 'PATCH', ['name' => 'Updated']), 'widget-1');
    $internalCreated = $controller->createRecord(has_api_controller_behavior_request('/int/v1/widgets', 'POST', ['name' => 'Internal Created']));
    $internalUpdated = $controller->updateRecord(has_api_controller_behavior_request('/int/v1/widgets/widget-1', 'PATCH', ['name' => 'Internal Updated']), 'widget-1');

    $failingModel = new class extends HasApiControllerBehaviorModel {
        public string $failureType = 'runtime';

        public function createRecordFromRequest(Request $request, ?callable $onBefore = null, ?callable $onAfter = null): self
        {
            if ($this->failureType === 'query') {
                throw new QueryException('mysql', 'insert into widgets', [], new RuntimeException('database unavailable'));
            }

            throw new RuntimeException('write failed');
        }

        public function updateRecordFromRequest(Request $request, $id, ?callable $onBefore = null, ?callable $onAfter = null): self
        {
            if ($this->failureType === 'query') {
                throw new QueryException('mysql', 'update widgets', [], new RuntimeException('database unavailable'));
            }

            throw new RuntimeException('update failed');
        }
    };
    $failingController = new HasApiControllerBehaviorController($failingModel);

    $createFailure = $failingController->createRecord(has_api_controller_behavior_request('/v1/widgets', 'POST', ['name' => 'Nope']));
    $updateFailure = $failingController->updateRecord(has_api_controller_behavior_request('/v1/widgets/widget-1', 'PATCH', ['name' => 'Nope']), 'widget-1');

    $failingModel->failureType = 'query';
    $createQueryFailure        = $failingController->createRecord(has_api_controller_behavior_request('/v1/widgets', 'POST', ['name' => 'Nope']));
    $updateQueryFailure        = $failingController->updateRecord(has_api_controller_behavior_request('/v1/widgets/widget-1', 'PATCH', ['name' => 'Nope']), 'widget-1');

    expect($created->resolve())->toMatchArray(['uuid' => 'created-widget', 'name' => 'Created'])
        ->and($updated->resolve())->toMatchArray(['uuid' => 'widget-1', 'name' => 'Updated'])
        ->and($internalCreated->resolve())->toMatchArray(['uuid' => 'created-widget', 'name' => 'Internal Created'])
        ->and($internalUpdated->resolve())->toMatchArray(['uuid' => 'widget-1', 'name' => 'Internal Updated'])
        ->and(array_column($controller->hookCalls, 0))->toBe([
            'onBeforeCreate',
            'onAfterCreate',
            'onBeforeUpdate',
            'onAfterUpdate',
            'onBeforeCreate',
            'onAfterCreate',
            'onBeforeUpdate',
            'onAfterUpdate',
        ])
        ->and($createFailure->getData(true))->toBe(['errors' => ['Error occurred while trying to create a Widget']])
        ->and($updateFailure->getData(true))->toBe(['errors' => ['Error occurred while trying to update a Widget']])
        ->and($createQueryFailure->getData(true))->toBe(['errors' => ['Error occurred while trying to create a Widget']])
        ->and($updateQueryFailure->getData(true))->toBe(['errors' => ['Error occurred while trying to update a Widget']]);
});

test('api controller behavior validates fallback rule contracts before writing', function () {
    $controller        = new HasApiControllerBehaviorController();
    $controller->rules = ['name' => ['required']];

    $createFailure = $controller->createRecord(has_api_controller_behavior_request('/v1/widgets', 'POST'));
    $updateFailure = $controller->updateRecord(has_api_controller_behavior_request('/v1/widgets/widget-1', 'PATCH'), 'widget-1');

    expect($createFailure->getData(true))->toBe(['errors' => ['validation' => ['The given data was invalid.']]])
        ->and($updateFailure->getData(true))->toBe(['errors' => ['validation' => ['The given data was invalid.']]]);
});

test('api controller behavior validates form request classes for create update and configured requests', function () {
    HasApiControllerBehaviorCreateRequest::$withValidatorCalls = [];

    $controller                = new HasApiControllerBehaviorController();
    $controller->createRequest = HasApiControllerBehaviorCreateRequest::class;
    $controller->updateRequest = HasApiControllerBehaviorUpdateRequest::class;

    $controller->validateRequest(has_api_controller_behavior_request('/v1/widgets', 'POST', ['name' => 'Created']));

    $createFailure = $controller->createRecord(has_api_controller_behavior_request('/v1/widgets', 'POST'));
    $updateFailure = $controller->updateRecord(has_api_controller_behavior_request('/v1/widgets/widget-1', 'PATCH'), 'widget-1');

    $configuredController          = new HasApiControllerBehaviorController();
    $configuredController->request = HasApiControllerBehaviorConfiguredRequest::class;
    $configuredController->validateRequest(has_api_controller_behavior_request('/v1/widgets/validate', 'GET', ['token' => 'abc']));

    $deniedController                = new HasApiControllerBehaviorController();
    $deniedController->createRequest = HasApiControllerBehaviorDeniedRequest::class;

    expect(HasApiControllerBehaviorCreateRequest::$withValidatorCalls)->toHaveCount(2)
        ->and($createFailure->getData(true))->toBe(['errors' => ['validation' => ['The given data was invalid.']]])
        ->and($updateFailure->getData(true))->toBe(['errors' => ['validation' => ['The given data was invalid.']]])
        ->and(fn () => $deniedController->validateRequest(has_api_controller_behavior_request('/v1/widgets', 'POST', ['name' => 'Denied'])))
        ->toThrow(HttpException::class);
});

test('api controller behavior scopes public deletes by public id and session company', function () {
    $model                  = new HasApiControllerBehaviorModel();
    $model->deletableRecord = new HasApiControllerBehaviorModel(['uuid' => 'widget-1', 'public_id' => 'widget_public_1', 'name' => 'Delete Me']);
    $controller             = new HasApiControllerBehaviorController($model);

    $request = has_api_controller_behavior_request('/v1/widgets/widget_public_1', 'DELETE');
    session(['company' => 'company-1']);
    $response = $controller->deleteRecord('widget_public_1', $request);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['status'])->toBe('success')
        ->and($response->getData(true)['message'])->toBe('Widget deleted')
        ->and($model->lastBuilder->wheres)->toBe([
            ['public_id', '=', 'widget_public_1', 'and'],
            ['widgets.company_uuid', 'company-1', null, 'and'],
        ])
        ->and($model->calls)->toContain(['applyDirectivesToQuery', []]);

    $missingModel      = new HasApiControllerBehaviorModel();
    $missingController = new HasApiControllerBehaviorController($missingModel);
    $missing           = $missingController->deleteRecord('missing', has_api_controller_behavior_request('/v1/widgets/missing', 'DELETE'));

    expect($missing->getStatusCode())->toBe(404)
        ->and($missing->getData(true))->toBe([
            'status'  => 'failed',
            'message' => 'Widget not found',
        ]);
});

test('api controller behavior returns internal delete resources and skips unavailable company scopes', function () {
    $model                   = new HasApiControllerBehaviorModel();
    $model->hasCompanyColumn = false;
    $model->deletableRecord  = new HasApiControllerBehaviorModel(['uuid' => 'widget-1', 'public_id' => 'widget_public_1', 'name' => 'Delete Me']);
    $controller              = new HasApiControllerBehaviorController($model);

    $request = has_api_controller_behavior_request('/int/v1/widgets/widget-1', 'DELETE');
    session(['company' => 'company-1']);

    $response = $controller->deleteRecord('widget-1', $request);

    expect($response->resolve())->toMatchArray(['uuid' => 'widget-1', 'name' => 'Delete Me'])
        ->and($model->lastBuilder->wheres)->toBe([
            ['uuid', 'widget-1', null, 'and'],
        ]);
});

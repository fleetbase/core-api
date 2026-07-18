<?php

use Fleetbase\Http\Controllers\Internal\v1\ExtensionController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class ExtensionControllerQueryFake
{
    public array $wheres = [];

    public function where(string $column, mixed $value): self
    {
        $this->wheres[] = [$column, $value];

        return $this;
    }
}

class ExtensionControllerModelFake extends Model
{
    public ?ExtensionControllerQueryFake $query = null;

    public function queryFromRequest(Request $request, ?Closure $queryCallback = null): array
    {
        $this->query = new ExtensionControllerQueryFake();

        if ($queryCallback) {
            $queryCallback($this->query);
        }

        return [
            'path' => $request->path(),
            'wheres' => $this->query->wheres,
        ];
    }
}

test('extension controller scopes authored extensions to the current company', function () {
    bind_test_container();
    session()->flush();
    session(['company' => 'company-123']);

    $model = new ExtensionControllerModelFake();
    $controller = (new ReflectionClass(ExtensionController::class))->newInstanceWithoutConstructor();
    $controller->model = $model;
    $request = Request::create('/int/v1/extensions/authored', 'GET');

    $result = $controller->getAuthored($request);

    expect($result)->toBe([
        'path' => 'int/v1/extensions/authored',
        'wheres' => [
            ['author_uuid', 'company-123'],
        ],
    ])->and($model->query)->toBeInstanceOf(ExtensionControllerQueryFake::class);
});

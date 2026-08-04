<?php

use Fleetbase\Support\ActionMapper;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

class ActionMapperTestController
{
    public function findRecord()
    {
    }

    public function updateRecord()
    {
    }

    public function customAction()
    {
    }
}

test('action mapper maps known controller actions before falling back to request method', function () {
    $mapper = new ActionMapper();

    expect($mapper->mapAction('createRecord', 'GET'))->toBe('create')
        ->and($mapper->mapAction('updateRecord', 'GET'))->toBe('update')
        ->and($mapper->mapAction('deleteRecord', 'GET'))->toBe('delete')
        ->and($mapper->mapAction('findRecord', 'POST'))->toBe('view')
        ->and($mapper->mapAction('queryRecord', 'DELETE'))->toBe('list')
        ->and($mapper->mapAction('searchRecords', 'PUT'))->toBe('list')
        ->and($mapper->mapAction('search', 'PATCH'))->toBe('list');
});

test('action mapper falls back to http methods for unlisted controller actions', function () {
    $mapper = new ActionMapper();

    expect($mapper->mapAction('customAction', 'POST'))->toBe('create')
        ->and($mapper->mapAction('customAction', 'PUT'))->toBe('update')
        ->and($mapper->mapAction('customAction', 'PATCH'))->toBe('update')
        ->and($mapper->mapAction('customAction', 'DELETE'))->toBe('delete')
        ->and($mapper->mapAction('customAction', 'GET'))->toBe('view');
});

test('action mapper static helper resolves through the container', function () {
    bind_test_container();

    expect(ActionMapper::getAction('findRecord', 'POST'))->toBe('view')
        ->and(ActionMapper::getAction('customAction', 'DELETE'))->toBe('delete');
});

test('action mapper resolves controller method from request route action', function () {
    bind_test_container();

    $request = Request::create('/int/v1/users/user_123', 'PATCH');
    $route   = new Route(['PATCH'], '/int/v1/users/{id}', [
        'controller' => ActionMapperTestController::class . '@updateRecord',
    ]);

    $request->setRouteResolver(fn () => $route);

    expect(ActionMapper::resolve($request))->toBe('update');
});

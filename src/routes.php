<?php

use Illuminate\Support\Facades\Route;
use Fleetbase\Support\InternalConfig;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::prefix(InternalConfig::get('api.routing.prefix', 'flb'))->namespace('Fleetbase\Http\Controllers')->group(function ($router) {
    $router->get('/', 'Controller@hello');

    /*
    |--------------------------------------------------------------------------
    | Internal Routes
    |--------------------------------------------------------------------------
    |
    | Primary internal routes for console.
    */
    $router->prefix(InternalConfig::get('api.routing.internal_prefix', 'int'))->namespace('Internal')->group(function ($router) {
        $router->prefix('v1')->namespace('v1')->group(function ($router) {
            $router->registerFleetbaseAuthRoutes();
            $router->registerFleetbaseRest('companies');
            $router->registerFleetbaseRest('users');
            $router->registerFleetbaseRest('user-devices');
            $router->registerFleetbaseRest('groups');
            $router->registerFleetbaseRest('roles');
            $router->registerFleetbaseRest('policies');
            $router->registerFleetbaseRest('permissions');
            $router->registerFleetbaseRest('extensions');
            $router->registerFleetbaseRest('files');
            $router->registerFleetbaseRest('transactions');
        });
    });
});

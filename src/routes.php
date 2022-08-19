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
            $router->registerFleetbaseREST('companies');
            $router->registerFleetbaseREST('users');
            $router->registerFleetbaseREST('user-devices');
            $router->registerFleetbaseREST('groups');
            $router->registerFleetbaseREST('roles');
            $router->registerFleetbaseREST('policies');
            $router->registerFleetbaseREST('permissions');
            $router->registerFleetbaseREST('extensions');
            $router->registerFleetbaseREST('files');
            $router->registerFleetbaseREST('transactions');
        });
    });
});

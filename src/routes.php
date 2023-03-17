<?php

use Fleetbase\Http\Middleware\SetupFleetbaseSession;
use Fleetbase\Support\InternalConfig;
use Illuminate\Support\Facades\Route;

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

Route::prefix(InternalConfig::get('api.routing.prefix', '/'))->namespace('Fleetbase\Http\Controllers')->group(
    function ($router) {
        $router->get('/', 'Controller@hello');

        /*
        |--------------------------------------------------------------------------
        | Internal Routes
        |--------------------------------------------------------------------------
        |
        | Primary internal routes for console.
        */
        $router->prefix(InternalConfig::get('api.routing.internal_prefix', 'int'))->namespace('Internal')->group(
            function ($router) {
                $router->prefix('v1')->namespace('v1')->group(
                    function ($router) {
                        $router->fleetbaseAuthRoutes();
                        $router->group(
                            ['middleware' => ['fleetbase.protected']],
                            function ($router) {
                                $router->fleetbaseRoutes('companies');
                                $router->fleetbaseRoutes(
                                    'users',
                                    function ($router, $controller) {
                                        $router->get('me', $controller('current'));
                                    }
                                );
                                $router->fleetbaseRoutes('user-devices');
                                $router->fleetbaseRoutes('groups');
                                $router->fleetbaseRoutes('roles');
                                $router->fleetbaseRoutes('policies');
                                $router->fleetbaseRoutes('permissions');
                                $router->fleetbaseRoutes('extensions');
                                $router->fleetbaseRoutes(
                                    'files',
                                    function ($router, $controller) {
                                        $router->post('upload', $controller('upload'));
                                        $router->post('uploadBase64', $controller('upload-base64'));
                                        $router->get('download/{id}', $controller('download'));
                                    }
                                );
                                $router->fleetbaseRoutes('transactions');
                                $router->group(
                                    ['prefix' => 'lookup'],
                                    function ($router) {
                                        $router->get('whois', 'LookupController@whois');
                                        $router->get('currencies', 'LookupController@currencies');
                                        $router->get('countries', 'LookupController@countries');
                                        $router->get('country/{code}', 'LookupController@country');
                                        $router->get('font-awesome-icons', 'LookupController@fontAwesomeIcons');
                                    }
                                );
                                $router->group(
                                    ['prefix' => 'billing'],
                                    function ($router) {
                                        $router->get('check-subscription', '_TempController@checkSubscription');
                                    }
                                );
                            }
                        );
                    }
                );
            }
        );
    }
);

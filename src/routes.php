<?php

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

if (env('APP_DEBUG') === true) {
    Route::get('test', 'Fleetbase\Http\Controllers\Controller@test');
}

Route::prefix(config('fleetbase.api.routing.prefix', '/'))->namespace('Fleetbase\Http\Controllers')->group(
    function ($router) {
        $router->get('/', 'Controller@hello');

        /*
        |--------------------------------------------------------------------------
        | Public/Consumable Routes
        |--------------------------------------------------------------------------
        |
        | Routes for users and public applications to consume.
        */
        $router->prefix('v1')
            ->namespace('Api\v1')
            ->group(function ($router) {
                $router->group(
                    ['prefix' => 'organizations'],
                    function ($router) {
                        $router->get('current', 'OrganizationController@getCurrent');
                    }
                );
            });

        /*
        |--------------------------------------------------------------------------
        | Internal Routes
        |--------------------------------------------------------------------------
        |
        | Primary internal routes for console.
        */
        $router->prefix(config('fleetbase.api.routing.internal_prefix', 'int'))->namespace('Internal')->group(
            function ($router) {
                $router->prefix('v1')->namespace('v1')->group(
                    function ($router) {
                        $router->fleetbaseAuthRoutes();
                        $router->group(
                            ['prefix' => 'installer'],
                            function ($router) {
                                $router->get('initialize', 'InstallerController@initialize');
                                $router->post('createdb', 'InstallerController@createDatabase');
                                $router->post('migrate', 'InstallerController@migrate');
                                $router->post('seed', 'InstallerController@seed');
                            }
                        );
                        $router->group(
                            ['prefix' => 'onboard'],
                            function ($router) {
                                $router->get('should-onboard', 'OnboardController@shouldOnboard');
                                $router->post('create-account', 'OnboardController@createAccount');
                                $router->post('verify-email', 'OnboardController@verifyEmail');
                                $router->post('send-verification-sms', 'OnboardController@sendVerificationSms');
                                $router->post('send-verification-email', 'OnboardController@sendVerificationEmail');
                            }
                        );
                        $router->group(
                            ['prefix' => 'lookup'],
                            function ($router) {
                                $router->get('whois', 'LookupController@whois');
                                $router->get('currencies', 'LookupController@currencies');
                                $router->get('countries', 'LookupController@countries');
                                $router->get('country/{code}', 'LookupController@country');
                                $router->get('fleetbase-blog', 'LookupController@fleetbaseBlog');
                                $router->get('font-awesome-icons', 'LookupController@fontAwesomeIcons');
                            }
                        );
                        $router->group(
                            ['prefix' => 'users'],
                            function ($router) {
                                $router->post('accept-company-invite', 'UserController@acceptCompanyInvite');
                            }
                        );
                        $router->group(
                            ['prefix' => 'companies'],
                            function ($router) {
                                $router->get('find/{id}', 'CompanyController@findCompany');
                            }
                        );
                        $router->group(
                            ['prefix' => 'settings'],
                            function ($router) {
                                $router->get('branding', 'SettingController@getBrandingSettings');
                            }
                        );
                        $router->group(
                            ['prefix' => 'two-fa'],
                            function ($router) {
                                $router->get('check', 'TwoFaController@checkTwoFactor');
                                $router->post('validate', 'TwoFaController@validateSession');
                                $router->post('verify', 'TwoFaController@verifyCode');
                                $router->post('resend', 'TwoFaController@resendCode');
                                $router->post('invalidate', 'TwoFaController@invalidateSession');
                            }
                        );
                        $router->group(
                            ['middleware' => ['fleetbase.protected']],
                            function ($router) {
                                $router->fleetbaseRoutes(
                                    'api-credentials',
                                    function ($router, $controller) {
                                        $router->delete('bulk-delete', $controller('bulkDelete'));
                                        $router->patch('roll/{id}', $controller('roll'));
                                        $router->get('export', $controller('export'));
                                    }
                                );
                                $router->fleetbaseRoutes(
                                    'metrics',
                                    function ($router, $controller) {
                                        $router->get('iam', $controller('iam'));
                                        $router->get('iam-dashboard', $controller('iamDashboard'));
                                    }
                                );
                                $router->fleetbaseRoutes(
                                    'settings',
                                    function ($router, $controller) {
                                        $router->get('overview', $controller('adminOverview'));
                                        $router->get('filesystem-config', $controller('getFilesystemConfig'));
                                        $router->post('filesystem-config', $controller('saveFilesystemConfig'));
                                        $router->post('test-filesystem-config', $controller('testFilesystemConfig'));
                                        $router->get('mail-config', $controller('getMailConfig'));
                                        $router->post('mail-config', $controller('saveMailConfig'));
                                        $router->post('test-mail-config', $controller('testMailConfig'));
                                        $router->get('queue-config', $controller('getQueueConfig'));
                                        $router->post('queue-config', $controller('saveQueueConfig'));
                                        $router->post('test-queue-config', $controller('testQueueConfig'));
                                        $router->get('services-config', $controller('getServicesConfig'));
                                        $router->post('services-config', $controller('saveServicesConfig'));
                                        $router->post('test-twilio-config', $controller('testTwilioConfig'));
                                        $router->post('test-sentry-config', $controller('testSentryConfig'));
                                        $router->post('branding', $controller('saveBrandingSettings'));
                                        $router->put('branding', $controller('saveBrandingSettings'));
                                        $router->post('test-socket', $controller('testSocketcluster'));
                                        $router->get('notification-channels-config', $controller('getNotificationChannelsConfig'));
                                        $router->post('notification-channels-config', $controller('saveNotificationChannelsConfig'));
                                        $router->post('test-notification-channels-config', $controller('testNotificationChannelsConfig'));
                                    }
                                );
                                $router->fleetbaseRoutes(
                                    'two-fa',
                                    function ($router, $controller) {
                                        $router->post('config', $controller('saveSystemConfig'));
                                        $router->get('config', $controller('getSystemConfig'));
                                        $router->get('enforce', $controller('shouldEnforce'));
                                    }
                                );
                                $router->fleetbaseRoutes('api-events');
                                $router->fleetbaseRoutes('api-request-logs');
                                $router->fleetbaseRoutes(
                                    'webhook-endpoints',
                                    function ($router, $controller) {
                                        $router->get('events', $controller('events'));
                                        $router->get('versions', $controller('versions'));
                                    }
                                );
                                $router->fleetbaseRoutes('webhook-request-logs');
                                $router->fleetbaseRoutes('companies', function ($router, $controller) {
                                    $router->get('two-fa', $controller('getTwoFactorSettings'));
                                    $router->post('two-fa', $controller('saveTwoFactorSettings'));
                                    $router->get('{id}/users', $controller('users'));
                                });
                                $router->fleetbaseRoutes(
                                    'users',
                                    function ($router, $controller) {
                                        $router->get('me', $controller('current'));
                                        $router->get('export', $controller('export'));
                                        $router->patch('deactivate/{id}', $controller('deactivate'));
                                        $router->patch('activate/{id}', $controller('activate'));
                                        $router->delete('remove-from-company/{id}', $controller('removeFromCompany'));
                                        $router->delete('bulk-delete', $controller('bulkDelete'));
                                        $router->post('resend-invite', $controller('resendInvitation'));
                                        $router->post('set-password', $controller('setCurrentUserPassword'));
                                        $router->post('validate-password', $controller('validatePassword'));
                                        $router->post('change-password', $controller('changeUserPassword'));
                                        $router->post('two-fa', $controller('saveTwoFactorSettings'));
                                        $router->get('two-fa', $controller('getTwoFactorSettings'));
                                        $router->post('locale', $controller('setUserLocale'));
                                        $router->get('locale', $controller('getUserLocale'));
                                    }
                                );
                                $router->fleetbaseRoutes('user-devices');
                                $router->fleetbaseRoutes('groups');
                                $router->fleetbaseRoutes('roles');
                                $router->fleetbaseRoutes('policies');
                                $router->fleetbaseRoutes('permissions');
                                $router->fleetbaseRoutes('extensions');
                                $router->fleetbaseRoutes('categories');
                                $router->fleetbaseRoutes('comments');
                                $router->fleetbaseRoutes('custom-fields');
                                $router->fleetbaseRoutes('custom-field-values');
                                $router->fleetbaseRoutes(
                                    'files',
                                    function ($router, $controller) {
                                        $router->post('upload', $controller('upload'));
                                        $router->post('uploadBase64', $controller('upload-base64'));
                                        $router->get('download/{id}', $controller('download'));
                                    }
                                );
                                $router->fleetbaseRoutes('transactions');
                                $router->fleetbaseRoutes('notifications', function ($router, $controller) {
                                    $router->get('registry', $controller('registry'));
                                    $router->get('notifiables', $controller('notifiables'));
                                    $router->get('get-settings', $controller('getSettings'));
                                    $router->put('mark-as-read', $controller('markAsRead'));
                                    $router->put('mark-all-read', $controller('markAllAsRead'));
                                    $router->delete('bulk-delete', $controller('bulkDelete'));
                                    $router->post('save-settings', $controller('saveSettings'));
                                });
                                $router->fleetbaseRoutes('dashboards', function ($router, $controller) {
                                    $router->post('switch', $controller('switchDashboard'));
                                    $router->post('reset-default', $controller('resetDefaultDashboard'));
                                });
                                $router->fleetbaseRoutes('dashboard-widgets');
                            }
                        );
                    }
                );
            }
        );
    }
);

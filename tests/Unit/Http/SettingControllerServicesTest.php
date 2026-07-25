<?php

namespace PhpOption {
    if (!class_exists(Option::class)) {
        class Option
        {
            public function __construct(private mixed $value)
            {
            }

            public static function fromValue(mixed $value): self
            {
                return new self($value);
            }

            public function map(callable $callback): self
            {
                if ($this->value === null) {
                    return $this;
                }

                return new self($callback($this->value));
            }

            public function getOrCall(callable $callback): mixed
            {
                return $this->value ?? $callback();
            }

            public function getOrThrow(Throwable $throwable): mixed
            {
                if ($this->value === null) {
                    throw $throwable;
                }

                return $this->value;
            }
        }
    }
}

namespace Dotenv\Repository {
    if (!class_exists(RepositoryBuilder::class)) {
        class RepositoryBuilder
        {
            public static function createWithDefaultAdapters(): self
            {
                return new self();
            }

            public function addAdapter(string $adapter): self
            {
                return $this;
            }

            public function immutable(): self
            {
                return $this;
            }

            public function make(): object
            {
                return new class {
                    public function get(string $key): mixed
                    {
                        return null;
                    }
                };
            }
        }
    }
}

namespace Dotenv\Repository\Adapter {
    if (!class_exists(PutenvAdapter::class)) {
        class PutenvAdapter
        {
        }
    }
}

namespace {
    use Fleetbase\Http\Controllers\Internal\v1\SettingController;
    use Fleetbase\Http\Requests\AdminRequest;
    use Fleetbase\Services\SmsService;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Support\Facades\Http;
    use Psr\Log\NullLogger;

    function setting_controller_services_fixtures(): void
    {
        $container = bind_test_container([
            'services.aws'            => [
                'key'    => 'aws-key',
                'secret' => 'aws-secret',
                'region' => 'ap-southeast-1',
            ],
            'services.ipinfo.api_key' => 'ipinfo-key',
            'services.google_maps'    => [
                'api_key' => 'google-maps-key',
                'locale'  => 'mn',
            ],
            'services.twilio'         => [
                'sid'   => 'twilio-sid',
                'token' => 'twilio-token',
                'from'  => '+15555550100',
            ],
            'sentry.dsn'              => 'https://sentry.example.test/1',
            'sms.default_provider'    => SmsService::PROVIDER_TWILIO,
            'sms.routing_rules'       => [
                '+976' => SmsService::PROVIDER_CALLPRO,
            ],
            'sms.providers'           => [
                SmsService::PROVIDER_CALLPRO => [
                    'api_key' => 'callpro-config-key',
                    'from'    => '72001234',
                ],
            ],
            'services.sms.providers'  => [
                SmsService::PROVIDER_CUSTOM_HTTP => [
                    'url'    => 'https://sms.example.test/send',
                    'method' => 'POST',
                ],
            ],
        ]);

        $container->instance('log', new NullLogger());
        Facade::clearResolvedInstances();
    }

    function setting_controller_services_request(array $input = []): AdminRequest
    {
        return AdminRequest::create('/int/v1/settings/services', 'POST', $input);
    }

    afterEach(function () {
        Facade::clearResolvedInstances();
    });

    test('services config response exposes service credentials and merged sms provider settings', function () {
        setting_controller_services_fixtures();

        $response = (new SettingController())->getServicesConfig(setting_controller_services_request());
        $payload  = $response->getData(true);

        expect($response->getStatusCode())->toBe(200)
            ->and($payload)->toMatchArray([
                'awsKey'           => 'aws-key',
                'awsSecret'        => 'aws-secret',
                'awsRegion'        => 'ap-southeast-1',
                'ipinfoApiKey'     => 'ipinfo-key',
                'googleMapsApiKey' => 'google-maps-key',
                'googleMapsLocale' => 'mn',
                'twilioSid'        => 'twilio-sid',
                'twilioToken'      => 'twilio-token',
                'twilioFrom'       => '+15555550100',
                'sentryDsn'        => 'https://sentry.example.test/1',
            ])
            ->and($payload['sms']['defaultProvider'])->toBe(SmsService::PROVIDER_TWILIO)
            ->and($payload['sms']['routingRules'])->toBe([
                '+976' => SmsService::PROVIDER_CALLPRO,
            ])
            ->and($payload['sms']['providers'])->toMatchArray([
                SmsService::PROVIDER_CALLPRO     => [
                    'api_key' => 'callpro-config-key',
                    'from'    => '72001234',
                ],
                SmsService::PROVIDER_CUSTOM_HTTP => [
                    'url'    => 'https://sms.example.test/send',
                    'method' => 'POST',
                ],
                SmsService::PROVIDER_TWILIO      => [
                    'sid'   => 'twilio-sid',
                    'token' => 'twilio-token',
                    'from'  => '+15555550100',
                ],
            ])
            ->and($payload['sms']['available'][SmsService::PROVIDER_TWILIO])->toBe([
                'name'      => 'Twilio',
                'available' => true,
            ])
            ->and($payload['sms']['available'][SmsService::PROVIDER_CALLPRO]['name'])->toBe('CallPro/MessagePro.mn')
            ->and($payload['sms']['available'][SmsService::PROVIDER_CUSTOM_HTTP]['name'])->toBe('Custom HTTP Gateway');
    });

    test('test sms provider config requires a phone number before mutating provider config', function () {
        setting_controller_services_fixtures();

        $response = (new SettingController())->testSmsProviderConfig(setting_controller_services_request([
            'provider' => SmsService::PROVIDER_TWILIO,
            'config'   => [
                'sid' => 'override-sid',
            ],
        ]));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true))->toBe([
                'status'  => 'error',
                'message' => 'No test phone number provided!',
            ])
            ->and(config('services.twilio.sid'))->toBe('twilio-sid')
            ->and(config('sms.providers.' . SmsService::PROVIDER_TWILIO))->toBeNull();
    });

    test('test sms provider config applies temporary provider config and returns service exceptions', function () {
        setting_controller_services_fixtures();

        $response = (new SettingController())->testSmsProviderConfig(setting_controller_services_request([
            'provider' => 'unsupported_gateway',
            'phone'    => '+15555550123',
            'message'  => 'Hello from Fleetbase',
            'config'   => [
                'api_key' => 'unsupported-key',
                'from'    => 'Fleetbase',
            ],
        ]));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true))->toBe([
                'status'   => 'error',
                'message'  => 'Unsupported SMS provider: unsupported_gateway',
                'provider' => 'unsupported_gateway',
                'result'   => null,
            ])
            ->and(config('services.sms.providers.unsupported_gateway'))->toBe([
                'api_key' => 'unsupported-key',
                'from'    => 'Fleetbase',
            ])
            ->and(config('sms.providers.unsupported_gateway'))->toBe([
                'api_key' => 'unsupported-key',
                'from'    => 'Fleetbase',
            ]);
    });

    test('test sms provider config applies temporary twilio package configuration before provider errors', function () {
        setting_controller_services_fixtures();

        $response = (new SettingController())->testSmsProviderConfig(setting_controller_services_request([
            'provider' => SmsService::PROVIDER_TWILIO,
            'phone'    => '+15555550123',
            'message'  => 'Hello from Fleetbase',
            'config'   => [
                'sid'   => 'temporary-twilio-sid',
                'token' => 'temporary-twilio-token',
                'from'  => '+15555550999',
            ],
        ]));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true))->toMatchArray([
                'status'   => 'error',
                'message'  => 'Target class [twilio] does not exist.',
                'provider' => SmsService::PROVIDER_TWILIO,
                'result'   => null,
            ])
            ->and(config('services.twilio'))->toMatchArray([
                'sid'   => 'temporary-twilio-sid',
                'token' => 'temporary-twilio-token',
                'from'  => '+15555550999',
            ])
            ->and(config('twilio.twilio.connections.twilio'))->toMatchArray([
                'sid'   => 'temporary-twilio-sid',
                'token' => 'temporary-twilio-token',
                'from'  => '+15555550999',
            ]);
    });

    test('test sms provider config returns provider failure responses and applies callpro config aliases', function () {
        setting_controller_services_fixtures();

        Http::fake([
            'https://callpro.example.test/send' => Http::response([
                'error' => 'Gateway rejected sender',
            ], 400),
        ]);

        $response = (new SettingController())->testSmsProviderConfig(setting_controller_services_request([
            'provider' => SmsService::PROVIDER_CALLPRO,
            'phone'    => '99112233',
            'message'  => 'Hello from Fleetbase',
            'config'   => [
                'api_key'  => 'temporary-callpro-key',
                'from'     => '72001234',
                'base_url' => 'https://callpro.example.test',
            ],
        ]));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true))->toMatchArray([
                'status'   => 'error',
                'message'  => 'Gateway rejected sender',
                'provider' => SmsService::PROVIDER_CALLPRO,
                'result'   => [
                    'success'  => false,
                    'error'    => 'Gateway rejected sender',
                    'code'     => 400,
                    'provider' => SmsService::PROVIDER_CALLPRO,
                ],
            ])
            ->and(config('services.callpromn'))->toMatchArray([
                'api_key'  => 'temporary-callpro-key',
                'from'     => '72001234',
                'base_url' => 'https://callpro.example.test',
            ])
            ->and(config('services.sms.providers.callpro'))->toMatchArray([
                'api_key'  => 'temporary-callpro-key',
                'from'     => '72001234',
                'base_url' => 'https://callpro.example.test',
            ]);

        Http::assertSent(fn ($request) => $request->url() === 'https://callpro.example.test/send'
            && $request['from'] === '72001234'
            && $request['to'] === '99112233'
            && $request['text'] === 'Hello from Fleetbase');
    });
}

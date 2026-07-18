<?php

namespace Illuminate\Foundation\Bus {
    if (!trait_exists(Dispatchable::class)) {
        trait Dispatchable
        {
        }
    }
}

namespace Fleetbase\Webhook {
    if (!function_exists('Fleetbase\\Webhook\\event')) {
        function event(mixed $event = null): mixed
        {
            return \Fleetbase\Tests\WebhookFixtures\WebhookJobEventRecorder::record($event);
        }
    }
}

namespace Fleetbase\Tests\WebhookFixtures {
    use Fleetbase\Webhook\BackoffStrategy\BackoffStrategy;
    use Fleetbase\Webhook\CallWebhookJob;
    use Fleetbase\Webhook\Signer\Signer;
    use GuzzleHttp\Psr7\Response;

    class ConfiguredWebhookJob extends CallWebhookJob
    {
    }

    class WebhookJobEventRecorder
    {
        public static array $events = [];

        public static function reset(): void
        {
            static::$events = [];
        }

        public static function record(mixed $event = null): mixed
        {
            static::$events[] = $event;

            return $event;
        }
    }

    class InspectableWebhookJob extends CallWebhookJob
    {
        public array $createdRequests     = [];
        public array $releasedFor         = [];
        public int $attempt               = 1;
        public ?Response $nextResponse    = null;
        public ?\Throwable $nextException = null;
        public bool $removedFromQueue     = false;
        public bool $deleted              = false;
        public ?\Throwable $failedWith    = null;

        public function attempts()
        {
            return $this->attempt;
        }

        public function release($delay = 0)
        {
            $this->releasedFor[] = $delay;

            return true;
        }

        public function delete()
        {
            $this->deleted = true;

            return true;
        }

        public function fail($exception = null)
        {
            $this->failedWith = $exception;

            return true;
        }

        protected function createRequest(array $body): Response
        {
            $this->createdRequests[] = $body;

            if ($this->nextException) {
                throw $this->nextException;
            }

            return $this->nextResponse ?? new Response(204, ['X-Hook' => 'ok'], 'accepted');
        }

        protected function shouldBeRemovedFromQueue(): bool
        {
            return $this->removedFromQueue;
        }
    }

    class ConfiguredSigner implements Signer
    {
        public function signatureHeaderName(): string
        {
            return 'X-Test-Signature';
        }

        public function calculateSignature(string $webhookUrl, array $payload, string $secret): string
        {
            return implode('|', [$webhookUrl, json_encode($payload), $secret]);
        }
    }

    class ConfiguredBackoffStrategy implements BackoffStrategy
    {
        public function waitInSecondsAfterAttempt(int $attempt): int
        {
            return 42 + $attempt;
        }
    }
}

namespace {
    use Fleetbase\Tests\WebhookFixtures\ConfiguredBackoffStrategy;
    use Fleetbase\Tests\WebhookFixtures\ConfiguredSigner;
    use Fleetbase\Tests\WebhookFixtures\ConfiguredWebhookJob;
    use Fleetbase\Tests\WebhookFixtures\InspectableWebhookJob;
    use Fleetbase\Tests\WebhookFixtures\WebhookJobEventRecorder;
    use Fleetbase\Webhook\BackoffStrategy\ExponentialBackoffStrategy;
    use Fleetbase\Webhook\CallWebhookJob;
    use Fleetbase\Webhook\Events\FinalWebhookCallFailedEvent;
    use Fleetbase\Webhook\Events\WebhookCallFailedEvent;
    use Fleetbase\Webhook\Events\WebhookCallSucceededEvent;
    use Fleetbase\Webhook\Exceptions\CouldNotCallWebhook;
    use Fleetbase\Webhook\Exceptions\InvalidBackoffStrategy;
    use Fleetbase\Webhook\Exceptions\InvalidSigner;
    use Fleetbase\Webhook\Exceptions\InvalidWebhookJob;
    use Fleetbase\Webhook\Signer\DefaultSigner;
    use Fleetbase\Webhook\WebhookCall;
    use GuzzleHttp\Exception\ConnectException;
    use GuzzleHttp\Psr7\Request;
    use GuzzleHttp\Psr7\Response;
    use Illuminate\Support\Facades\Facade;

    function webhook_test_container(): void
    {
        bind_test_container([
            'webhook-server.webhook_job'        => ConfiguredWebhookJob::class,
            'webhook-server.queue'              => 'webhooks',
            'webhook-server.connection'         => 'redis',
            'webhook-server.http_verb'          => 'put',
            'webhook-server.tries'              => 5,
            'webhook-server.backoff_strategy'   => ConfiguredBackoffStrategy::class,
            'webhook-server.timeout_in_seconds' => 12,
            'webhook-server.signer'             => ConfiguredSigner::class,
            'webhook-server.headers'            => [
                'Content-Type' => 'application/json',
                'X-App'        => 'core-api',
            ],
            'webhook-server.tags'                       => ['core-api', 'tenant-event'],
            'webhook-server.verify_ssl'                 => true,
            'webhook-server.throw_exception_on_failure' => true,
            'webhook-server.proxy'                      => ['https' => 'http://proxy.test:8080'],
            'webhook-server.signature_header_name'      => 'X-Fleetbase-Signature',
        ]);
    }

    function webhook_test_job(WebhookCall $call): CallWebhookJob
    {
        $property = new ReflectionProperty($call, 'callWebhookJob');
        $property->setAccessible(true);

        return $property->getValue($call);
    }

    function prepare_webhook_test_call(WebhookCall $call): CallWebhookJob
    {
        $method = new ReflectionMethod($call, 'prepareForDispatch');
        $method->setAccessible(true);
        $method->invoke($call);

        return webhook_test_job($call);
    }

    afterEach(function () {
        Facade::clearResolvedInstances();
        WebhookJobEventRecorder::reset();
    });

    test('webhook call applies configured job transport signing headers and metadata before dispatch', function () {
        webhook_test_container();

        $call = WebhookCall::create()
            ->url('https://example.test/hooks/orders')
            ->payload(['event' => 'order.created', 'id' => 'order-1'])
            ->uuid('webhook-call-1')
            ->useSecret('shared-secret')
            ->withHeaders(['X-App' => 'override', 'X-Extra' => 'present'])
            ->meta(['company_uuid' => 'company-1']);

        $job = prepare_webhook_test_call($call);

        expect($call->getUuid())->toBe('webhook-call-1')
            ->and($job)->toBeInstanceOf(ConfiguredWebhookJob::class)
            ->and($job->webhookUrl)->toBe('https://example.test/hooks/orders')
            ->and($job->payload)->toBe(['event' => 'order.created', 'id' => 'order-1'])
            ->and($job->uuid)->toBe('webhook-call-1')
            ->and($job->queue)->toBe('webhooks')
            ->and($job->connection)->toBe('redis')
            ->and($job->httpVerb)->toBe('put')
            ->and($job->tries)->toBe(5)
            ->and($job->backoffStrategyClass)->toBe(ConfiguredBackoffStrategy::class)
            ->and($job->requestTimeout)->toBe(12)
            ->and($job->verifySsl)->toBeTrue()
            ->and($job->throwExceptionOnFailure)->toBeTrue()
            ->and($job->proxy)->toBe(['https' => 'http://proxy.test:8080'])
            ->and($job->meta)->toBe(['company_uuid' => 'company-1'])
            ->and($job->tags())->toBe(['core-api', 'tenant-event'])
            ->and($job->headers)->toBe([
                'Content-Type'     => 'application/json',
                'X-App'            => 'override',
                'X-Extra'          => 'present',
                'X-Test-Signature' => 'https://example.test/hooks/orders|{"event":"order.created","id":"order-1"}|shared-secret',
            ]);
    });

    test('webhook call can skip signing and conditional dispatch can avoid preparation entirely', function () {
        webhook_test_container();

        $unsigned = WebhookCall::create()
            ->url('https://example.test/hooks/unsigned')
            ->payload(['ok' => true])
            ->doNotSign();

        $job = prepare_webhook_test_call($unsigned);

        expect($job->headers)->toBe([
            'Content-Type' => 'application/json',
            'X-App'        => 'core-api',
        ]);

        expect(WebhookCall::create()->dispatchIf(false))->toBeNull()
            ->and(WebhookCall::create()->dispatchUnless(true))->toBeNull();
    });

    test('webhook call rejects missing dispatch requirements and invalid strategy classes', function () {
        webhook_test_container();

        expect(fn () => prepare_webhook_test_call(WebhookCall::create()->useSecret('secret')))
            ->toThrow(CouldNotCallWebhook::class, 'Could not call the webhook because the url has not been set.');

        expect(fn () => prepare_webhook_test_call(WebhookCall::create()->url('https://example.test/hooks')))
            ->toThrow(CouldNotCallWebhook::class, 'Could not call the webhook because no secret has been set.');

        expect(fn () => WebhookCall::create()->useBackoffStrategy(stdClass::class))
            ->toThrow(InvalidBackoffStrategy::class, 'is not a valid backoff strategy class');

        expect(fn () => WebhookCall::create()->signUsing(stdClass::class))
            ->toThrow(InvalidSigner::class, 'is not a valid signer class');

        expect(fn () => WebhookCall::create()->useJob(stdClass::class))
            ->toThrow(InvalidWebhookJob::class, 'is not a valid webhook job class');
    });

    test('default webhook signer and exponential backoff keep stable retry contracts', function () {
        webhook_test_container();

        $signer  = new DefaultSigner();
        $backoff = new ExponentialBackoffStrategy();

        expect($signer->signatureHeaderName())->toBe('X-Fleetbase-Signature')
            ->and($signer->calculateSignature('https://ignored.test', ['b' => 2, 'a' => 1], 'secret'))
            ->toBe(hash_hmac('sha256', '{"b":2,"a":1}', 'secret'))
            ->and($backoff->waitInSecondsAfterAttempt(0))->toBe(1)
            ->and($backoff->waitInSecondsAfterAttempt(1))->toBe(10)
            ->and($backoff->waitInSecondsAfterAttempt(4))->toBe(10000)
            ->and($backoff->waitInSecondsAfterAttempt(5))->toBe(100000);
    });

    test('webhook job sends get payload as query data and dispatches success event details', function () {
        webhook_test_container();
        WebhookJobEventRecorder::reset();

        $job                          = new InspectableWebhookJob();
        $job->httpVerb                = 'GET';
        $job->webhookUrl              = 'https://example.test/hooks/orders';
        $job->payload                 = ['order' => 'order-1'];
        $job->headers                 = ['X-App' => 'core-api'];
        $job->meta                    = ['company_uuid' => 'company-1'];
        $job->tags                    = ['orders'];
        $job->uuid                    = 'webhook-call-1';
        $job->tries                   = 3;
        $job->requestTimeout          = 8;
        $job->verifySsl               = true;
        $job->throwExceptionOnFailure = false;
        $job->backoffStrategyClass    = ConfiguredBackoffStrategy::class;
        $job->proxy                   = ['https' => 'http://proxy.test:8080'];
        $job->nextResponse            = new Response(202, ['X-Request-Id' => 'req-1'], 'queued');

        $job->handle();

        $event = WebhookJobEventRecorder::$events[0];

        expect($job->createdRequests)->toHaveCount(1)
            ->and($job->createdRequests[0])->toBe(['query' => ['order' => 'order-1']])
            ->and($job->getResponse()?->getStatusCode())->toBe(202)
            ->and($event)->toBeInstanceOf(WebhookCallSucceededEvent::class)
            ->and($event->httpVerb)->toBe('GET')
            ->and($event->webhookUrl)->toBe('https://example.test/hooks/orders')
            ->and($event->payload)->toBe(['order' => 'order-1'])
            ->and($event->headers)->toBe(['X-App' => 'core-api'])
            ->and($event->meta)->toBe(['company_uuid' => 'company-1'])
            ->and($event->tags)->toBe(['orders'])
            ->and($event->attempt)->toBe(1)
            ->and($event->response?->getStatusCode())->toBe(202)
            ->and($event->errorType)->toBeNull()
            ->and($event->errorMessage)->toBeNull()
            ->and($event->uuid)->toBe('webhook-call-1');
    });

    test('webhook job sends non get payload as json body and releases before final retry', function () {
        webhook_test_container();
        WebhookJobEventRecorder::reset();

        $job                          = new InspectableWebhookJob();
        $job->httpVerb                = 'post';
        $job->webhookUrl              = 'https://example.test/hooks/orders';
        $job->payload                 = ['event' => 'order.updated'];
        $job->headers                 = ['Content-Type' => 'application/json'];
        $job->meta                    = ['company_uuid' => 'company-1'];
        $job->tags                    = ['orders'];
        $job->uuid                    = 'webhook-call-2';
        $job->tries                   = 3;
        $job->attempt                 = 2;
        $job->requestTimeout          = 8;
        $job->verifySsl               = false;
        $job->throwExceptionOnFailure = false;
        $job->backoffStrategyClass    = ConfiguredBackoffStrategy::class;
        $job->nextResponse            = new Response(500, [], 'server error');

        $job->handle();

        $event = WebhookJobEventRecorder::$events[0];

        expect($job->createdRequests[0])->toBe(['body' => '{"event":"order.updated"}'])
            ->and($job->releasedFor)->toBe([44])
            ->and($job->deleted)->toBeFalse()
            ->and($job->failedWith)->toBeNull()
            ->and($event)->toBeInstanceOf(WebhookCallFailedEvent::class)
            ->and($event->response?->getStatusCode())->toBe(500)
            ->and(WebhookJobEventRecorder::$events)->toHaveCount(1);
    });

    test('webhook job dispatches final failure event and deletes failed calls when exceptions are swallowed', function () {
        webhook_test_container();
        WebhookJobEventRecorder::reset();

        $job                          = new InspectableWebhookJob();
        $job->httpVerb                = 'POST';
        $job->webhookUrl              = 'https://example.test/hooks/orders';
        $job->payload                 = ['event' => 'order.failed'];
        $job->headers                 = [];
        $job->meta                    = [];
        $job->tags                    = [];
        $job->uuid                    = 'webhook-call-3';
        $job->tries                   = 2;
        $job->attempt                 = 2;
        $job->requestTimeout          = 8;
        $job->verifySsl               = true;
        $job->throwExceptionOnFailure = false;
        $job->backoffStrategyClass    = ConfiguredBackoffStrategy::class;
        $job->nextException           = new ConnectException('connection refused', new Request('POST', 'https://example.test/hooks/orders'));

        $job->handle();

        [$failedEvent, $finalEvent] = WebhookJobEventRecorder::$events;

        expect($job->releasedFor)->toBe([])
            ->and($job->deleted)->toBeTrue()
            ->and($job->failedWith)->toBeNull()
            ->and($failedEvent)->toBeInstanceOf(WebhookCallFailedEvent::class)
            ->and($failedEvent->errorType)->toBe(ConnectException::class)
            ->and($failedEvent->errorMessage)->toContain('connection refused')
            ->and($finalEvent)->toBeInstanceOf(FinalWebhookCallFailedEvent::class)
            ->and($finalEvent->errorType)->toBe(ConnectException::class);
    });
}

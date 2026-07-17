<?php

namespace Illuminate\Foundation\Bus {
    if (!trait_exists(Dispatchable::class)) {
        trait Dispatchable
        {
        }
    }
}

namespace Fleetbase\Tests\WebhookFixtures {
    use Fleetbase\Webhook\BackoffStrategy\BackoffStrategy;
    use Fleetbase\Webhook\CallWebhookJob;
    use Fleetbase\Webhook\Signer\Signer;

    class ConfiguredWebhookJob extends CallWebhookJob
    {
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
    use Fleetbase\Webhook\BackoffStrategy\ExponentialBackoffStrategy;
    use Fleetbase\Webhook\CallWebhookJob;
    use Fleetbase\Webhook\Exceptions\CouldNotCallWebhook;
    use Fleetbase\Webhook\Exceptions\InvalidBackoffStrategy;
    use Fleetbase\Webhook\Exceptions\InvalidSigner;
    use Fleetbase\Webhook\Exceptions\InvalidWebhookJob;
    use Fleetbase\Webhook\Signer\DefaultSigner;
    use Fleetbase\Webhook\WebhookCall;
    use Illuminate\Support\Facades\Facade;

    function webhook_test_container(): void
    {
        bind_test_container([
            'webhook-server.webhook_job' => ConfiguredWebhookJob::class,
            'webhook-server.queue' => 'webhooks',
            'webhook-server.connection' => 'redis',
            'webhook-server.http_verb' => 'put',
            'webhook-server.tries' => 5,
            'webhook-server.backoff_strategy' => ConfiguredBackoffStrategy::class,
            'webhook-server.timeout_in_seconds' => 12,
            'webhook-server.signer' => ConfiguredSigner::class,
            'webhook-server.headers' => [
                'Content-Type' => 'application/json',
                'X-App' => 'core-api',
            ],
            'webhook-server.tags' => ['core-api', 'tenant-event'],
            'webhook-server.verify_ssl' => true,
            'webhook-server.throw_exception_on_failure' => true,
            'webhook-server.proxy' => ['https' => 'http://proxy.test:8080'],
            'webhook-server.signature_header_name' => 'X-Fleetbase-Signature',
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
                'Content-Type' => 'application/json',
                'X-App' => 'override',
                'X-Extra' => 'present',
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
            'X-App' => 'core-api',
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

        $signer = new DefaultSigner();
        $backoff = new ExponentialBackoffStrategy();

        expect($signer->signatureHeaderName())->toBe('X-Fleetbase-Signature')
            ->and($signer->calculateSignature('https://ignored.test', ['b' => 2, 'a' => 1], 'secret'))
            ->toBe(hash_hmac('sha256', '{"b":2,"a":1}', 'secret'))
            ->and($backoff->waitInSecondsAfterAttempt(0))->toBe(1)
            ->and($backoff->waitInSecondsAfterAttempt(1))->toBe(10)
            ->and($backoff->waitInSecondsAfterAttempt(4))->toBe(10000)
            ->and($backoff->waitInSecondsAfterAttempt(5))->toBe(100000);
    });
}

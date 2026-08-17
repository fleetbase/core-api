<?php

use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Models\WebhookEndpoint;
use Fleetbase\Observers\ApiCredentialObserver;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class ApiAndWebhookModelsTaggedCacheFake
{
    private array $values = [];

    public function tags(array|string $tags): self
    {
        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }
}

class ApiAndWebhookModelsHashFake
{
    public function make(string $value, array $options = []): string
    {
        return 'hashed:' . $value;
    }
}

class ApiCredentialObserverSaveSpy extends ApiCredential
{
    public int $saves = 0;

    public function save(array $options = []): bool
    {
        $this->saves++;
        $this->syncOriginal();

        return true;
    }
}

it('derives api credential sandbox mode expiration values and generated key prefixes', function () {
    $container = bind_test_container();
    $container->instance('hash', new ApiAndWebhookModelsHashFake());

    $sandboxRequest = Request::create('/int/v1/api-credentials', 'POST', [], [], [], [
        'HTTP_ACCESS_CONSOLE_SANDBOX' => 'true',
    ]);
    $container->instance('request', $sandboxRequest);

    $credential            = new ApiCredential();
    $credential->test_mode = false;

    expect($credential->getAttributes()['test_mode'])->toBeTrue();

    $credential->expires_at = null;
    expect($credential->getAttributes()['expires_at'])->toBeNull();

    $credential->expires_at = '';
    expect($credential->getAttributes()['expires_at'])->toBeNull();

    Carbon::setTestNow(Carbon::parse('2026-06-04 12:00:00', 'UTC'));
    $credential->expires_at = 'never';
    expect($credential->getAttributes()['expires_at'])->toBeNull();

    $credential->expires_at = 'immediately';
    expect($credential->getAttributes()['expires_at']->format('Y-m-d H:i:s'))->toBe('2026-06-04 12:00:00');

    Carbon::setTestNow();
    // The mutator resolves relative strings with strtotime(), which reads the wall clock and so
    // ignores Carbon::setTestNow(). Bracket the write with the same expression rather than
    // comparing against a value computed a line earlier: those two reads can straddle a second
    // boundary, which is what made this fail under the slower coverage run.
    $lowerBound             = strtotime('+ 3 days');
    $credential->expires_at = 'in 3 days';
    $upperBound             = strtotime('+ 3 days');

    expect($credential->getAttributes()['expires_at']->getTimestamp())->toBeGreaterThanOrEqual($lowerBound)
        ->and($credential->getAttributes()['expires_at']->getTimestamp())->toBeLessThanOrEqual($upperBound);
    Carbon::setTestNow();

    $liveKeys = ApiCredential::generateKeys([1, 2, 3], false);
    $testKeys = ApiCredential::generateKeys([1, 2, 3], true);

    expect($liveKeys['key'])->toStartWith('flb_live_')
        ->and($liveKeys['secret'])->toBe('hashed:' . substr($liveKeys['key'], strlen('flb_live_')))
        ->and($testKeys['key'])->toStartWith('flb_test_')
        ->and($testKeys['secret'])->toBe('hashed:' . substr($testKeys['key'], strlen('flb_test_')));
});

it('api credential observer writes generated keys and persists live and test credentials', function (bool $testMode, string $expectedPrefix) {
    $container = bind_test_container();
    $container->instance('hash', new ApiAndWebhookModelsHashFake());

    $credential = new ApiCredentialObserverSaveSpy();
    $credential->setDateFormat('Y-m-d H:i:s');
    $credential->setRawAttributes([
        'id'         => 42,
        'created_at' => '2026-07-17 12:34:56',
        'test_mode'  => $testMode,
    ], true);

    (new ApiCredentialObserver())->created($credential);

    expect($credential->key)->toStartWith($expectedPrefix)
        ->and($credential->secret)->toBe('hashed:' . substr($credential->key, strlen($expectedPrefix)))
        ->and($credential->saves)->toBe(1);
})->with([
    'live credential' => [false, 'flb_live_'],
    'test credential' => [true, 'flb_test_'],
]);

it('evaluates webhook endpoint event filters and api credential display labels', function () {
    $container = bind_test_container([
        'database.default'           => 'mysql',
        'database.connections.mysql' => [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ],
        'api.events'              => ['order.created', 'order.updated', 'order.deleted'],
        'fleetbase.connection.db' => 'mysql',
    ]);
    $capsule = new Capsule($container);
    $capsule->addConnection(config('database.connections.mysql'), 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    Cache::swap(new ApiAndWebhookModelsTaggedCacheFake());

    $namedCredential = new ApiCredential();
    $namedCredential->setRawAttributes([
        'uuid' => 'credential-1',
        'name' => 'Console Key',
        'key'  => 'flb_live_named',
    ], true);
    $credentialLogOptions = $namedCredential->getActivitylogOptions();

    $namedEndpoint = new WebhookEndpoint();
    $namedEndpoint->setRawAttributes([
        'uuid' => 'webhook-1',
    ], true);
    $namedEndpoint->events = ['order.created'];
    $namedEndpoint->setRelation('apiCredential', $namedCredential);
    $logOptions = $namedEndpoint->getActivitylogOptions();

    expect($namedEndpoint->is_listening_on_all_events)->toBeFalse()
        ->and($credentialLogOptions->logAttributes)->toBe(['*'])
        ->and($credentialLogOptions->logOnlyDirty)->toBeTrue()
        ->and($namedCredential->user()->getRelated())->toBeInstanceOf(User::class)
        ->and($logOptions->logAttributes)->toBe(['*'])
        ->and($logOptions->logOnlyDirty)->toBeTrue()
        ->and($namedEndpoint->company()->getRelated())->toBeInstanceOf(Company::class)
        ->and($namedEndpoint->apiCredential()->getRelated())->toBeInstanceOf(ApiCredential::class)
        ->and($namedEndpoint->canFireEvent('order.created'))->toBeTrue()
        ->and($namedEndpoint->cannotFireEvent('order.deleted'))->toBeTrue()
        ->and($namedEndpoint->api_credential_name)->toBe('Console Key (flb_live_named)');

    $keyOnlyCredential = new ApiCredential();
    $keyOnlyCredential->setRawAttributes([
        'uuid' => 'credential-2',
        'key'  => 'flb_live_key_only',
    ], true);

    $allEventsEndpoint = new WebhookEndpoint();
    $allEventsEndpoint->setRawAttributes([
        'uuid' => 'webhook-2',
    ], true);
    $allEventsEndpoint->events = [];
    $allEventsEndpoint->setRelation('apiCredential', $keyOnlyCredential);

    expect($allEventsEndpoint->is_listening_on_all_events)->toBeTrue()
        ->and($allEventsEndpoint->canFireEvent('order.deleted'))->toBeTrue()
        ->and($allEventsEndpoint->api_credential_name)->toBe('flb_live_key_only');
});

<?php

use Fleetbase\Contracts\SocketChannelResolver;
use Fleetbase\Models\File;
use Fleetbase\Support\SocketCluster\ChannelAuthorizer;
use Fleetbase\Support\SocketCluster\CoreChannelResolvers;
use Fleetbase\Support\SocketCluster\ModelChannelResolver;
use Fleetbase\Support\SocketCluster\SocketChannelRegistry;
use Fleetbase\Support\SocketCluster\SocketPrincipal;
use Fleetbase\Tests\Fixtures\Support\SocketAuthFixtures;
use Illuminate\Http\Request;

class SocketChannelAuthorizationRecordingResolver implements SocketChannelResolver
{
    public array $calls = [];

    public function __construct(private bool $answer)
    {
    }

    public function authorize(SocketPrincipal $principal, string $id, string $channel): bool
    {
        $this->calls[] = [$principal->sub, $id, $channel];

        return $this->answer;
    }
}

/**
 * A company A console user by default; overrides replace any constructor argument.
 */
function socket_channel_principal(array $overrides = []): SocketPrincipal
{
    return new SocketPrincipal(...array_merge([
        'kind' => 'user',
        'sub'  => 'user-a1',
        'cid'  => 'company-a',
        'cpid' => 'company_aaa',
        'ids'  => ['user-a1', 'user_a1'],
        'jti'  => null,
        'exp'  => SocketAuthFixtures::NOW + 900,
    ], $overrides));
}

function socket_channel_driver(array $overrides = []): SocketPrincipal
{
    return socket_channel_principal(array_merge([
        'kind' => 'driver',
        'sub'  => 'driver-1',
        'cpid' => null,
        'ids'  => ['driver-1', 'driver_1', 'user-a1'],
    ], $overrides));
}

function socket_channel_core_authorizer(): ChannelAuthorizer
{
    $registry = new SocketChannelRegistry();
    CoreChannelResolvers::register($registry);

    return new ChannelAuthorizer($registry);
}

afterEach(function () {
    SocketAuthFixtures::reset();
});

test('the authorizer denies malformed channel names before anything else', function (string $channel) {
    SocketAuthFixtures::container();

    $decision = (new ChannelAuthorizer(new SocketChannelRegistry()))->authorize(SocketPrincipal::system(), $channel);

    expect($decision->toArray())->toBe(['allow' => false, 'ttl' => 30, 'reason' => 'invalid_channel']);
})->with([
    'empty'      => [''],
    'whitespace' => ['order.order 1'],
    'too long'   => [str_repeat('a', 256)],
]);

test('anonymous connections may follow the install channel only until setup creates a user', function () {
    SocketAuthFixtures::database(SocketAuthFixtures::KEY, ['users']);
    $missingSchema = (new ChannelAuthorizer(new SocketChannelRegistry()))->authorize(null, 'fleetbase.install');

    SocketAuthFixtures::database(SocketAuthFixtures::KEY, [], false);
    $noUsers = (new ChannelAuthorizer(new SocketChannelRegistry()))->authorize(null, 'fleetbase.install');

    SocketAuthFixtures::database();
    $authorizer = new ChannelAuthorizer(new SocketChannelRegistry());

    expect($missingSchema->toArray())->toBe(['allow' => true, 'ttl' => 30, 'reason' => 'install_pending'])
        ->and($noUsers->toArray())->toBe(['allow' => true, 'ttl' => 30, 'reason' => 'install_pending'])
        ->and($authorizer->authorize(null, 'fleetbase.install')->toArray())->toBe(['allow' => false, 'ttl' => 30, 'reason' => 'no_token'])
        ->and($authorizer->authorize(null, 'company.company_aaa')->reason)->toBe('no_token');
});

test('expired principals are denied', function () {
    SocketAuthFixtures::container();

    $authorizer = new ChannelAuthorizer(new SocketChannelRegistry());

    expect($authorizer->authorize(socket_channel_principal(['exp' => SocketAuthFixtures::NOW - 1]), 'company.company-a')->reason)->toBe('expired')
        ->and($authorizer->authorize(socket_channel_principal(['exp' => SocketAuthFixtures::NOW]), 'company.company-a')->reason)->toBe('expired')
        ->and($authorizer->authorize(SocketPrincipal::system(), 'company.company-a')->toArray())->toBe(['allow' => true, 'ttl' => 300, 'reason' => 'system']);
});

test('scoped tokens may follow exactly their listed channels and nothing else', function () {
    SocketAuthFixtures::container();

    $registry = new SocketChannelRegistry();
    $resolver = new SocketChannelAuthorizationRecordingResolver(true);
    $registry->register('checkout', $resolver);
    $registry->register('company', $resolver);

    $authorizer = new ChannelAuthorizer($registry);
    $checkout   = new SocketPrincipal(kind: 'checkout', sub: 'checkout-1', cid: 'company-a', scp: ['checkout.checkout_1'], exp: SocketAuthFixtures::NOW + 900);
    $system     = SocketPrincipal::system()->with(['scp' => ['tracking.abc']]);

    expect($authorizer->authorize($checkout, 'checkout.checkout_1')->toArray())->toBe(['allow' => true, 'ttl' => 300, 'reason' => 'scope'])
        ->and($authorizer->authorize($checkout, 'checkout.checkout_2')->reason)->toBe('out_of_scope')
        ->and($authorizer->authorize($checkout, 'company.company-a')->reason)->toBe('out_of_scope')
        ->and($authorizer->authorize($system, 'order.order_1')->reason)->toBe('out_of_scope')
        ->and($resolver->calls)->toBe([]);
});

test('principals follow their own channels without a lookup', function () {
    SocketAuthFixtures::container();

    $user   = socket_channel_principal();
    $api    = socket_channel_principal(['kind' => 'api', 'sub' => 'cred-a', 'ids' => ['cred-a']]);
    $driver = socket_channel_driver();
    $noCpid = socket_channel_principal(['cpid' => null]);

    foreach (['company.company-a', 'company.company_aaa', 'user.user-a1', 'user.user_a1', 'driver.user_a1', 'install.company-a.fleetops', 'uninstall.company-a.fleetops'] as $channel) {
        expect(ChannelAuthorizer::isSelfChannel($user, $channel))->toBeTrue();
    }

    expect(ChannelAuthorizer::isSelfChannel($api, 'api.cred-a'))->toBeTrue()
        ->and(ChannelAuthorizer::isSelfChannel($api, 'company.company-a'))->toBeTrue()
        ->and(ChannelAuthorizer::isSelfChannel($api, 'install.company-a.fleetops'))->toBeFalse()
        ->and(ChannelAuthorizer::isSelfChannel($user, 'api.user-a1'))->toBeFalse()
        ->and(ChannelAuthorizer::isSelfChannel($user, 'install.company-b.fleetops'))->toBeFalse()
        ->and(ChannelAuthorizer::isSelfChannel($user, 'user.user-b1'))->toBeFalse()
        ->and(ChannelAuthorizer::isSelfChannel($noCpid, 'company.'))->toBeFalse()
        ->and(ChannelAuthorizer::isSelfChannel($driver, 'driver.driver_1'))->toBeTrue()
        ->and(ChannelAuthorizer::isSelfChannel($driver, 'user.user-a1'))->toBeTrue()
        ->and(ChannelAuthorizer::isSelfChannel($driver, 'company.company-a'))->toBeFalse()
        ->and(ChannelAuthorizer::isSelfChannel(socket_channel_principal(['cid' => null]), 'install..fleetops'))->toBeFalse()
        ->and((new ChannelAuthorizer(new SocketChannelRegistry()))->authorize($driver, 'driver.driver-1')->reason)->toBe('self');
});

test('other channels are decided by the resolver registered for their prefix', function () {
    SocketAuthFixtures::container();

    $registry = new SocketChannelRegistry();
    $orders   = new SocketChannelAuthorizationRecordingResolver(true);
    $registry->register('order', $orders);
    $registry->register('vehicle', function (SocketPrincipal $principal, string $id, string $channel) {
        return $id === 'vehicle_1';
    });
    $registry->register('broken', function () {
        throw new RuntimeException('lookup failed');
    });

    $authorizer = new ChannelAuthorizer($registry);
    $user       = socket_channel_principal();

    expect($authorizer->authorize($user, 'order.order_1.extra')->toArray())->toBe(['allow' => true, 'ttl' => 300, 'reason' => 'resolver'])
        ->and($orders->calls)->toBe([['user-a1', 'order_1.extra', 'order.order_1.extra']])
        ->and($authorizer->authorize($user, 'vehicle.vehicle_1')->reason)->toBe('resolver')
        ->and($authorizer->authorize($user, 'vehicle.vehicle_2')->toArray())->toBe(['allow' => false, 'ttl' => 30, 'reason' => 'forbidden'])
        ->and($authorizer->authorize($user, 'broken.anything')->reason)->toBe('resolver_error')
        ->and(app('log')->entries)->toBe([
            ['warning', 'Socket channel resolver failed.', ['prefix' => 'broken', 'error' => 'lookup failed']],
        ])
        ->and($authorizer->authorize($user, 'unknown.anything')->reason)->toBe('unknown_prefix')
        ->and($authorizer->authorize($user, 'order')->reason)->toBe('unknown_prefix')
        ->and($authorizer->authorize($user, 'order.')->reason)->toBe('unknown_prefix');
});

test('decisions are cached per token and channel for their capped lifetime', function () {
    SocketAuthFixtures::container();

    $registry = new SocketChannelRegistry();
    $orders   = new SocketChannelAuthorizationRecordingResolver(true);
    $vehicles = new SocketChannelAuthorizationRecordingResolver(false);
    $registry->register('order', $orders);
    $registry->register('vehicle', $vehicles);

    $authorizer = new ChannelAuthorizer($registry);
    $principal  = socket_channel_principal(['jti' => 'jti-cached', 'exp' => SocketAuthFixtures::NOW + 100]);
    $first      = $authorizer->authorize($principal, 'order.order_1');
    $second     = $authorizer->authorize($principal, 'order.order_1');

    $authorizer->authorize($principal, 'vehicle.vehicle_1');
    $denied = $authorizer->authorize($principal, 'vehicle.vehicle_1');

    $uncached = socket_channel_principal();
    $authorizer->authorize($uncached, 'order.order_2');
    $authorizer->authorize($uncached, 'order.order_2');

    expect($first->toArray())->toBe(['allow' => true, 'ttl' => 100, 'reason' => 'resolver'])
        ->and($second->toArray())->toBe($first->toArray())
        ->and(app('cache')->get('socket-auth:' . sha1('jti-cached|order.order_1')))->toBe($first->toArray())
        ->and($denied->toArray())->toBe(['allow' => false, 'ttl' => 30, 'reason' => 'forbidden'])
        ->and($vehicles->calls)->toHaveCount(1)
        ->and($orders->calls)->toBe([
            ['user-a1', 'order_1', 'order.order_1'],
            ['user-a1', 'order_2', 'order.order_2'],
            ['user-a1', 'order_2', 'order.order_2'],
        ]);
});

test('the registry keeps one resolver per prefix and the first principal a resolver claims', function () {
    $registry = new SocketChannelRegistry();
    $request  = Request::create('/v1/socket/token', 'POST');
    $first    = new SocketChannelAuthorizationRecordingResolver(true);
    $second   = new SocketChannelAuthorizationRecordingResolver(false);

    $registry->register('order', $first);
    $registry->register('order', $second);
    $registry->registerModel('file', File::class);

    expect($registry->resolve('order'))->toBe($second)
        ->and($registry->resolve('file'))->toBeInstanceOf(ModelChannelResolver::class)
        ->and($registry->resolve('missing'))->toBeNull()
        ->and($registry->resolvePrincipal($request, 'user-a1'))->toBeNull();

    $registry->registerPrincipalResolver(function (Request $request, $user) {
        return null;
    });
    $registry->registerPrincipalResolver(function (Request $request, $user) {
        return 'not a principal';
    });
    $registry->registerPrincipalResolver(function (Request $request, $user) {
        return new SocketPrincipal(kind: 'driver', sub: 'driver-1', cid: 'company-a', ids: ['driver-1', $user]);
    });
    $registry->registerPrincipalResolver(function () {
        throw new RuntimeException('a later resolver is never asked');
    });

    expect($registry->resolvePrincipal($request, 'user-a1')->ids)->toBe(['driver-1', 'user-a1']);
});

test('model channels belong to their company and are narrowed for drivers and customers', function () {
    SocketAuthFixtures::database();

    $resolver = new ModelChannelResolver(File::class);
    $narrowed = new ModelChannelResolver(File::class, function (SocketPrincipal $principal, File $file) {
        return $file->uuid === 'file-a';
    });
    $user     = socket_channel_principal();
    $api      = socket_channel_principal(['kind' => 'api', 'sub' => 'cred-a', 'ids' => ['cred-a']]);
    $driver   = socket_channel_driver();
    $checkout = new SocketPrincipal(kind: 'checkout', sub: 'checkout-1', cid: 'company-a');
    $sandbox  = socket_channel_principal(['env' => 'test']);

    expect($resolver->authorize($user, 'file_aaa', 'file.file_aaa'))->toBeTrue()
        ->and($resolver->authorize($user, 'file-a', 'file.file-a'))->toBeTrue()
        ->and($resolver->authorize($api, 'file-a', 'file.file-a'))->toBeTrue()
        ->and($resolver->authorize($user, 'file_bbb', 'file.file_bbb'))->toBeFalse()
        ->and($resolver->authorize($user, 'file-missing', 'file.file-missing'))->toBeFalse()
        ->and($resolver->authorize(socket_channel_principal(['cid' => null]), 'file-a', 'file.file-a'))->toBeFalse()
        ->and($resolver->authorize($driver, 'file-a', 'file.file-a'))->toBeFalse()
        ->and($narrowed->authorize($driver, 'file-a', 'file.file-a'))->toBeTrue()
        ->and($narrowed->authorize($driver, 'file-b', 'file.file-b'))->toBeFalse()
        ->and($narrowed->authorize($checkout, 'file-a', 'file.file-a'))->toBeFalse()
        ->and($resolver->authorize($sandbox, 'file_aaa_test', 'file.file_aaa_test'))->toBeTrue()
        ->and($resolver->authorize($sandbox, 'file-a', 'file.file-a'))->toBeFalse()
        ->and(ModelChannelResolver::connection($sandbox))->toBe('sandbox')
        ->and(ModelChannelResolver::connection($user))->toBeNull();
});

test('core registers its channel prefixes', function () {
    $registry = new SocketChannelRegistry();
    CoreChannelResolvers::register($registry);

    foreach (['company', 'api', 'user', 'test', 'install', 'uninstall'] as $prefix) {
        expect($registry->resolve($prefix))->toBeArray();
    }

    foreach (['chat', 'chat_channel', 'chat_participant', 'chat_message', 'file'] as $prefix) {
        expect($registry->resolve($prefix))->toBeInstanceOf(ModelChannelResolver::class);
    }
});

test('company and user channels resolve only within the principal company', function () {
    SocketAuthFixtures::database();

    $user   = socket_channel_principal();
    $driver = socket_channel_driver();

    expect(CoreChannelResolvers::company($user, 'company-a'))->toBeTrue()
        ->and(CoreChannelResolvers::company($user, 'company_aaa'))->toBeTrue()
        ->and(CoreChannelResolvers::company($user, 'company_bbb'))->toBeFalse()
        ->and(CoreChannelResolvers::company($user, 'company-missing'))->toBeFalse()
        ->and(CoreChannelResolvers::company($driver, 'company-a'))->toBeFalse()
        ->and(CoreChannelResolvers::user($user, 'user_a1'))->toBeTrue()
        ->and(CoreChannelResolvers::user($user, 'user-a2'))->toBeTrue()
        ->and(CoreChannelResolvers::user($user, 'user_b1'))->toBeFalse()
        ->and(CoreChannelResolvers::user($user, 'user-missing'))->toBeFalse()
        ->and(CoreChannelResolvers::user($driver, 'user-a2'))->toBeFalse()
        ->and(CoreChannelResolvers::isMember($user, null))->toBeFalse()
        ->and(CoreChannelResolvers::isMember(socket_channel_principal(['cid' => null]), 'user-a1'))->toBeFalse();
});

test('api channels resolve to credentials and personal access tokens of the principal company', function () {
    SocketAuthFixtures::database();

    $user    = socket_channel_principal();
    $sandbox = socket_channel_principal(['env' => 'test']);

    expect(CoreChannelResolvers::api($user, 'cred-a'))->toBeTrue()
        ->and(CoreChannelResolvers::api($user, 'cred-b'))->toBeFalse()
        ->and(CoreChannelResolvers::api($user, 'cred-missing'))->toBeFalse()
        ->and(CoreChannelResolvers::api($user, 'cred-a-test'))->toBeTrue()
        ->and(CoreChannelResolvers::api($sandbox, 'cred-a-test'))->toBeTrue()
        ->and(CoreChannelResolvers::api($sandbox, 'cred-a'))->toBeTrue()
        ->and(CoreChannelResolvers::api($user, '1'))->toBeTrue()
        ->and(CoreChannelResolvers::api($user, '2'))->toBeFalse()
        ->and(CoreChannelResolvers::api($user, '99'))->toBeFalse()
        ->and(CoreChannelResolvers::api(socket_channel_driver(), 'cred-a'))->toBeFalse();
});

test('test and install channels follow their owner and company', function () {
    SocketAuthFixtures::container();

    $user = socket_channel_principal();

    expect(CoreChannelResolvers::test($user, 'user-a1'))->toBeTrue()
        ->and(CoreChannelResolvers::test($user, 'user-b1'))->toBeFalse()
        ->and(CoreChannelResolvers::test(socket_channel_principal(['adm' => true]), 'user-b1'))->toBeTrue()
        ->and(CoreChannelResolvers::install($user, 'company-a.fleetops'))->toBeTrue()
        ->and(CoreChannelResolvers::install($user, 'company-b.fleetops'))->toBeFalse()
        ->and(CoreChannelResolvers::install(socket_channel_principal(['kind' => 'api', 'sub' => 'cred-a']), 'company-a.fleetops'))->toBeTrue()
        ->and(CoreChannelResolvers::install(socket_channel_driver(), 'company-a.fleetops'))->toBeFalse()
        ->and(CoreChannelResolvers::install(socket_channel_principal(['cid' => null]), 'company-a.fleetops'))->toBeFalse();
});

test('company principals are denied every core channel of another company', function () {
    SocketAuthFixtures::database();

    $authorizer = socket_channel_core_authorizer();
    $user       = socket_channel_principal();
    $api        = socket_channel_principal(['kind' => 'api', 'sub' => 'cred-a', 'ids' => ['cred-a']]);

    foreach (['chat.chat_aaa', 'chat_channel.chat-a', 'chat_participant.participant-a1', 'chat_message.chat_message_a', 'file.file_aaa', 'user.user-a2', 'api.cred-a', 'test.user-a1'] as $channel) {
        expect($authorizer->authorize($user, $channel)->allow)->toBeTrue();
    }

    foreach (['chat.chat_bbb', 'chat_channel.chat-b', 'chat_participant.participant-b1', 'chat_message.chat_message_b', 'file.file_bbb', 'user.user_b1', 'company.company_bbb', 'api.cred-b', 'api.2', 'test.user-b1', 'install.company-b.fleetops'] as $channel) {
        expect($authorizer->authorize($user, $channel)->toArray())->toBe(['allow' => false, 'ttl' => 30, 'reason' => 'forbidden'])
            ->and($authorizer->authorize($api, $channel)->allow)->toBeFalse();
    }
});

test('drivers reach only the chats they take part in', function () {
    SocketAuthFixtures::database();

    $authorizer = socket_channel_core_authorizer();
    $driver     = socket_channel_driver();

    foreach (['chat.chat_aaa', 'chat_channel.chat-a', 'chat_participant.chat_participant_a1', 'chat_message.message-a', 'user.user-a1', 'driver.driver-1'] as $channel) {
        expect($authorizer->authorize($driver, $channel)->allow)->toBeTrue();
    }

    foreach (['chat.chat_bbb', 'chat_participant.participant-b1', 'chat_message.message-b', 'user.user-a2', 'company.company-a', 'file.file_aaa', 'api.cred-a'] as $channel) {
        expect($authorizer->authorize($driver, $channel)->allow)->toBeFalse();
    }

    expect($authorizer->authorize(socket_channel_driver(['ids' => ['driver-1']]), 'chat.chat_aaa')->allow)->toBeFalse()
        ->and($authorizer->authorize(socket_channel_driver(['ids' => []]), 'chat.chat_aaa')->allow)->toBeFalse()
        ->and(CoreChannelResolvers::isChatParticipant($driver, null))->toBeFalse();
});

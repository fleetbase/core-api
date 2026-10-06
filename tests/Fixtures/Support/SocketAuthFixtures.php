<?php

namespace Fleetbase\Tests\Fixtures\Support;

use Fleetbase\Models\User;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

/**
 * Shared setup for the realtime socket authentication tests.
 *
 * Two companies (A and B) with a user, chat, file and API credential each, so every resolver
 * can be checked for both the allowed and the cross-company case. The "mysql" and "sandbox"
 * connections are separate in-memory databases with the same schema.
 */
class SocketAuthFixtures
{
    public const KEY = 'socket-auth-test-key-0123456789abcdefghij';

    public const OTHER_KEY = 'another-socket-auth-key-0123456789abcdefgh';

    /**
     * A fixed instant (unix seconds) so expiry arithmetic is exact.
     */
    public const NOW = 1800000000;

    /**
     * Bind a test container with socket authentication on (or off), freeze the clock and clear the session.
     */
    public static function container(?string $key = self::KEY, array $config = []): Container
    {
        $container = bind_test_container(array_merge([
            'broadcasting.connections.socketcluster.auth_key'    => $key,
            'broadcasting.connections.socketcluster.publish_url' => 'http://socket.test:8001',
            'broadcasting.connections.socketcluster.token_ttl'   => 900,
            'broadcasting.connections.socketcluster.options'     => [
                'secure' => false,
                'host'   => 'socket.test',
                'port'   => 8000,
                'path'   => '/socketcluster/',
                'query'  => [],
            ],
        ], $config));
        $container->instance(HttpFactory::class, new HttpFactory());
        Facade::clearResolvedInstances();
        Carbon::setTestNow(Carbon::createFromTimestampUTC(self::NOW));
        session()->flush();

        return $container;
    }

    /**
     * Undo container(): a fresh container (so socket authentication is off again), and the
     * clock, session and booted models released.
     */
    public static function reset(): void
    {
        Carbon::setTestNow();
        session()->flush();
        EloquentModel::clearBootedModels();
        Container::setInstance(new \FleetbaseTestContainer());
        Facade::clearResolvedInstances();
    }

    /**
     * Seeded databases for resolver, principal and controller tests.
     *
     * @param array<int, string> $skipTables tables to leave out, to exercise missing-schema paths
     */
    public static function database(?string $key = self::KEY, array $skipTables = [], bool $seed = true): Capsule
    {
        EloquentModel::clearBootedModels();

        $connection = [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ];

        $container = static::container($key, [
            'database.default'             => 'mysql',
            'database.connections.mysql'   => $connection,
            'database.connections.sandbox' => $connection,
            'fleetbase.connection.db'      => 'mysql',
        ]);

        $capsule = new Capsule($container);
        $capsule->addConnection($connection, 'mysql');
        $capsule->addConnection($connection, 'sandbox');
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $capsule->getDatabaseManager()->setDefaultConnection('mysql');

        $container->instance('db', $capsule->getDatabaseManager());
        Facade::clearResolvedInstance('db');

        foreach (['mysql', 'sandbox'] as $name) {
            static::createSchema($capsule, $name, $skipTables);
        }

        if ($seed) {
            static::seed($capsule);
        }

        return $capsule;
    }

    /**
     * An unsigned or HS256-signed JWT with exactly the given header and claims.
     */
    public static function jwt(array $header, array $claims, ?string $key = null): string
    {
        $unsigned  = static::base64Url(json_encode($header)) . '.' . static::base64Url(json_encode($claims));
        $signature = $key === null ? '' : static::base64Url(hash_hmac('sha256', $unsigned, $key, true));

        return $unsigned . '.' . $signature;
    }

    /**
     * Valid claims for a hand-built token, before any test-specific changes.
     */
    public static function claims(array $overrides = []): array
    {
        return array_merge([
            'iss'  => 'fleetbase-api',
            'aud'  => 'fleetbase-socket',
            'iat'  => self::NOW,
            'nbf'  => self::NOW,
            'exp'  => self::NOW + 600,
            'jti'  => 'jti-handmade',
            'sub'  => 'user-a1',
            'kind' => 'user',
            'cid'  => 'company-a',
            'env'  => 'live',
            'ids'  => ['user-a1'],
            'adm'  => false,
        ], $overrides);
    }

    /**
     * The decoded payload segment of a JWT.
     */
    public static function payload(string $jwt): array
    {
        return json_decode(static::base64UrlDecode(explode('.', $jwt)[1]), true);
    }

    /**
     * The decoded header segment of a JWT.
     */
    public static function header(string $jwt): array
    {
        return json_decode(static::base64UrlDecode(explode('.', $jwt)[0]), true);
    }

    public static function user(array $attributes = []): User
    {
        $user = new User();
        $user->setRawAttributes(array_merge([
            'uuid'         => 'user-a1',
            'public_id'    => 'user_a1',
            'company_uuid' => 'company-a',
            'type'         => 'user',
        ], $attributes));

        return $user;
    }

    protected static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected static function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'));
    }

    protected static function createSchema(Capsule $capsule, string $name, array $skipTables): void
    {
        $schema = $capsule->getConnection($name)->getSchemaBuilder();
        $tables = [
            'companies' => function ($table) {
                $table->string('uuid')->primary();
                $table->string('public_id')->nullable();
                $table->string('name')->nullable();
            },
            'users' => function ($table) {
                $table->string('uuid')->primary();
                $table->string('public_id')->nullable();
                $table->string('company_uuid')->nullable();
                $table->string('type')->nullable();
            },
            'company_users' => function ($table) {
                $table->string('uuid')->primary();
                $table->string('company_uuid')->nullable();
                $table->string('user_uuid')->nullable();
            },
            'api_credentials' => function ($table) {
                $table->string('uuid')->primary();
                $table->string('company_uuid')->nullable();
                $table->string('user_uuid')->nullable();
                $table->string('key')->nullable();
                $table->boolean('test_mode')->default(false);
                $table->timestamp('expires_at')->nullable();
            },
            'personal_access_tokens' => function ($table) {
                $table->increments('id');
                $table->string('tokenable_type');
                $table->string('tokenable_id');
                $table->string('name')->nullable();
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
            },
            'chat_channels' => function ($table) {
                $table->string('uuid')->primary();
                $table->string('public_id')->nullable();
                $table->string('company_uuid')->nullable();
            },
            'chat_participants' => function ($table) {
                $table->string('uuid')->primary();
                $table->string('public_id')->nullable();
                $table->string('company_uuid')->nullable();
                $table->string('chat_channel_uuid')->nullable();
                $table->string('user_uuid')->nullable();
            },
            'chat_messages' => function ($table) {
                $table->string('uuid')->primary();
                $table->string('public_id')->nullable();
                $table->string('company_uuid')->nullable();
                $table->string('chat_channel_uuid')->nullable();
            },
            'files' => function ($table) {
                $table->string('uuid')->primary();
                $table->string('public_id')->nullable();
                $table->string('company_uuid')->nullable();
            },
            'orders' => function ($table) {
                $table->string('uuid')->primary();
                $table->string('company_uuid')->nullable();
            },
        ];

        foreach ($tables as $table => $definition) {
            if (in_array($table, $skipTables, true)) {
                continue;
            }

            $schema->create($table, function ($blueprint) use ($definition) {
                $definition($blueprint);
                $blueprint->timestamp('created_at')->nullable();
                $blueprint->timestamp('updated_at')->nullable();
                $blueprint->timestamp('deleted_at')->nullable();
            });
        }
    }

    protected static function seed(Capsule $capsule): void
    {
        $live    = $capsule->getConnection('mysql');
        $sandbox = $capsule->getConnection('sandbox');

        $live->table('companies')->insert([
            ['uuid' => 'company-a', 'public_id' => 'company_aaa', 'name' => 'Company A'],
            ['uuid' => 'company-b', 'public_id' => 'company_bbb', 'name' => 'Company B'],
        ]);
        $live->table('users')->insert([
            ['uuid' => 'user-a1', 'public_id' => 'user_a1', 'company_uuid' => 'company-a', 'type' => 'user'],
            ['uuid' => 'user-a2', 'public_id' => 'user_a2', 'company_uuid' => 'company-a', 'type' => 'user'],
            ['uuid' => 'user-b1', 'public_id' => 'user_b1', 'company_uuid' => 'company-b', 'type' => 'user'],
        ]);
        // user-a2 has no company_users row: membership then falls back to users.company_uuid.
        $live->table('company_users')->insert([
            ['uuid' => 'company-user-a1', 'company_uuid' => 'company-a', 'user_uuid' => 'user-a1'],
            ['uuid' => 'company-user-b1', 'company_uuid' => 'company-b', 'user_uuid' => 'user-b1'],
        ]);
        $live->table('api_credentials')->insert([
            ['uuid' => 'cred-a', 'company_uuid' => 'company-a', 'user_uuid' => 'user-a1', 'key' => 'flb_live_a', 'test_mode' => false],
            ['uuid' => 'cred-b', 'company_uuid' => 'company-b', 'user_uuid' => 'user-b1', 'key' => 'flb_live_b', 'test_mode' => false],
        ]);
        $live->table('personal_access_tokens')->insert([
            ['id' => 1, 'tokenable_type' => User::class, 'tokenable_id' => 'user-a1', 'name' => 'navigator', 'token' => hash('sha256', 'plain-token-a1'), 'abilities' => '["*"]'],
            ['id' => 2, 'tokenable_type' => User::class, 'tokenable_id' => 'user-b1', 'name' => 'navigator', 'token' => hash('sha256', 'plain-token-b1'), 'abilities' => '["*"]'],
        ]);
        $live->table('chat_channels')->insert([
            ['uuid' => 'chat-a', 'public_id' => 'chat_aaa', 'company_uuid' => 'company-a'],
            ['uuid' => 'chat-b', 'public_id' => 'chat_bbb', 'company_uuid' => 'company-b'],
        ]);
        $live->table('chat_participants')->insert([
            ['uuid' => 'participant-a1', 'public_id' => 'chat_participant_a1', 'company_uuid' => 'company-a', 'chat_channel_uuid' => 'chat-a', 'user_uuid' => 'user-a1'],
            ['uuid' => 'participant-b1', 'public_id' => 'chat_participant_b1', 'company_uuid' => 'company-b', 'chat_channel_uuid' => 'chat-b', 'user_uuid' => 'user-b1'],
        ]);
        $live->table('chat_messages')->insert([
            ['uuid' => 'message-a', 'public_id' => 'chat_message_a', 'company_uuid' => 'company-a', 'chat_channel_uuid' => 'chat-a'],
            ['uuid' => 'message-b', 'public_id' => 'chat_message_b', 'company_uuid' => 'company-b', 'chat_channel_uuid' => 'chat-b'],
        ]);
        $live->table('files')->insert([
            ['uuid' => 'file-a', 'public_id' => 'file_aaa', 'company_uuid' => 'company-a'],
            ['uuid' => 'file-b', 'public_id' => 'file_bbb', 'company_uuid' => 'company-b'],
        ]);
        $live->table('orders')->insert([
            ['uuid' => 'order-a', 'company_uuid' => 'company-a'],
        ]);

        // The sandbox carries synced companies and its own test-mode records.
        $sandbox->table('companies')->insert([
            ['uuid' => 'company-a', 'public_id' => 'company_aaa', 'name' => 'Company A'],
        ]);
        $sandbox->table('api_credentials')->insert([
            ['uuid' => 'cred-a-test', 'company_uuid' => 'company-a', 'user_uuid' => 'user-a1', 'key' => 'flb_test_a', 'test_mode' => true],
        ]);
        $sandbox->table('files')->insert([
            ['uuid' => 'file-a-test', 'public_id' => 'file_aaa_test', 'company_uuid' => 'company-a'],
        ]);
    }
}

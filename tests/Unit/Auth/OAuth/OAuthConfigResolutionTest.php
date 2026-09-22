<?php

use Fleetbase\Auth\OAuth\Drivers\AppleDriver;
use Fleetbase\Auth\OAuth\Drivers\GoogleDriver;
use Fleetbase\Auth\OAuth\Exceptions\OAuthProviderNotConfiguredException;
use Fleetbase\Auth\OAuth\OAuthProviderConfig;
use Fleetbase\Models\Setting;
use Fleetbase\Services\OAuth\OAuthConfigRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

/**
 * Keyed so that decrypting with a different instance fails the way a rotated
 * APP_KEY does. core-api requires illuminate/contracts but not
 * illuminate/encryption — the concrete encrypter is supplied by the host app.
 */
class OAuthConfigEncrypterFake implements Encrypter
{
    public function __construct(private string $key = 'primary')
    {
    }

    public function encrypt($value, $serialize = true)
    {
        return 'enc:' . $this->key . ':' . base64_encode($serialize ? serialize($value) : (string) $value);
    }

    public function decrypt($payload, $unserialize = true)
    {
        $prefix = 'enc:' . $this->key . ':';

        if (!is_string($payload) || !str_starts_with($payload, $prefix)) {
            throw new DecryptException('The MAC is invalid.');
        }

        $value = base64_decode(substr($payload, strlen($prefix)), true);

        if ($value === false) {
            throw new DecryptException('The payload is invalid.');
        }

        return $unserialize ? unserialize($value) : $value;
    }

    public function getKey()
    {
        return $this->key;
    }
}

class OAuthConfigCacheFake
{
    private array $values = [];

    public function rememberForever(string $key, Closure $callback): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            $this->values[$key] = $callback();
        }

        return $this->values[$key];
    }

    public function remember(string $key, mixed $ttl, Closure $callback): mixed
    {
        return $this->rememberForever($key, $callback);
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

    public function forget(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function increment(string $key, int $value = 1): int
    {
        $this->values[$key] = (int) ($this->values[$key] ?? 0) + $value;

        return $this->values[$key];
    }

    public function tags(array|string $tags): self
    {
        return $this;
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }

    public function getPrefix(): string
    {
        return '';
    }
}

function oauth_config_repository(array $config = [], ?Encrypter $encrypter = null): OAuthConfigRepository
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container(array_merge([
        'api.cache.enabled'          => false,
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
        // A trimmed stand-in for config/oauth.php.
        'oauth.enabled'              => true,
        'oauth.allow_registration'   => true,
        'oauth.providers'            => [
            'google' => [
                'driver'        => GoogleDriver::class,
                'enabled'       => false,
                'client_id'     => 'google-id-from-env',
                'client_secret' => null,
                'hosted_domain' => null,
            ],
            'apple' => [
                'driver'      => AppleDriver::class,
                'enabled'     => false,
                'client_id'   => null,
                'team_id'     => null,
                'key_id'      => null,
                'private_key' => null,
            ],
        ],
    ], $config));

    $cache = new OAuthConfigCacheFake();
    $container->instance('cache', $cache);
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('log');

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('settings', function ($table) {
        $table->increments('id');
        $table->string('key')->unique();
        $table->text('value')->nullable();
    });

    return new OAuthConfigRepository($encrypter ?? new OAuthConfigEncrypterFake());
}

it('falls back to the env backed config when nothing is stored', function () {
    $repository = oauth_config_repository();

    expect($repository->isEnabled())->toBeTrue()
        ->and($repository->allowsRegistration())->toBeTrue()
        ->and($repository->providerIds())->toBe(['google', 'apple'])
        ->and($repository->forProvider('google')->get('client_id'))->toBe('google-id-from-env')
        ->and($repository->forProvider('google')->enabled())->toBeFalse();
});

it('layers stored settings over the config defaults', function () {
    $repository = oauth_config_repository();

    Setting::configureSystem('oauth', [
        'enabled'   => true,
        'providers' => [
            'google' => ['enabled' => true, 'client_id' => 'google-id-from-db'],
        ],
    ]);

    $google = $repository->forProvider('google');

    expect($google->get('client_id'))->toBe('google-id-from-db')
        ->and($google->enabled())->toBeTrue()
        // A key absent from the database still resolves from config.
        ->and($google->get('hosted_domain', 'none'))->toBe('none');
});

it('respects a stored global kill switch', function () {
    $repository = oauth_config_repository();

    Setting::configureSystem('oauth', ['enabled' => false, 'allow_registration' => false]);

    expect($repository->isEnabled())->toBeFalse()
        ->and($repository->allowsRegistration())->toBeFalse();
});

it('never lets the driver class be set from the database', function () {
    $repository = oauth_config_repository();

    Setting::configureSystem('oauth', [
        'providers' => ['google' => ['driver' => 'Evil\\ArbitraryClass']],
    ]);

    // Allowing this would be arbitrary class instantiation from a settings row.
    expect($repository->forProvider('google')->all())->not->toHaveKey('driver')
        ->and($repository->driverClass('google'))->toBe(GoogleDriver::class);
});

it('decrypts a secret stored through the admin ui', function () {
    $encrypter  = new OAuthConfigEncrypterFake();
    $repository = oauth_config_repository([], $encrypter);

    Setting::configureSystem('oauth', [
        'providers' => [
            'google' => ['client_secret_encrypted' => $encrypter->encrypt('s3cret', false)],
        ],
    ]);

    expect($repository->forProvider('google')->secret('client_secret'))->toBe('s3cret');
});

it('accepts a plaintext secret supplied through the environment', function () {
    $repository = oauth_config_repository([
        'oauth.providers.google.client_secret' => 'from-env',
    ]);

    expect($repository->forProvider('google')->secret('client_secret'))->toBe('from-env')
        ->and($repository->forProvider('google')->hasSecret('client_secret'))->toBeTrue();
});

it('prefers the encrypted secret over a plaintext one', function () {
    $encrypter  = new OAuthConfigEncrypterFake();
    $repository = oauth_config_repository(['oauth.providers.google.client_secret' => 'from-env'], $encrypter);

    Setting::configureSystem('oauth', [
        'providers' => ['google' => ['client_secret_encrypted' => $encrypter->encrypt('from-db', false)]],
    ]);

    expect($repository->forProvider('google')->secret('client_secret'))->toBe('from-db');
});

it('reports a provider unconfigured when its secret will not decrypt and logs nothing sensitive', function () {
    $writer     = new OAuthConfigEncrypterFake('old-key');
    $repository = oauth_config_repository([], new OAuthConfigEncrypterFake('rotated-key'));

    Setting::configureSystem('oauth', [
        'providers' => [
            'google' => ['enabled' => true, 'client_id' => 'id', 'client_secret_encrypted' => $writer->encrypt('s3cret', false)],
        ],
    ]);

    $google = $repository->forProvider('google');

    expect($google->hasSecret('client_secret'))->toBeFalse()
        ->and($google->isConfigured(['client_id'], ['client_secret']))->toBeFalse()
        ->and(fn () => $google->secret('client_secret'))->toThrow(OAuthProviderNotConfiguredException::class);

    $errors = array_filter(app('log')->entries, fn ($entry) => $entry[0] === 'error');
    expect($errors)->not->toBeEmpty();

    foreach ($errors as $entry) {
        expect(json_encode($entry))->not->toContain('s3cret')
            ->and(json_encode($entry))->not->toContain('enc:old-key');
    }
});

it('never exposes a secret value to an administrator', function () {
    $encrypter  = new OAuthConfigEncrypterFake();
    $repository = oauth_config_repository([], $encrypter);

    Setting::configureSystem('oauth', [
        'providers' => [
            'google' => [
                'enabled'                 => true,
                'client_id'               => 'google-id',
                'client_secret_encrypted' => $encrypter->encrypt('super-secret-value', false),
            ],
        ],
    ]);

    $admin = $repository->toAdminArray(['google' => GoogleDriver::configSchema()]);

    expect(json_encode($admin))->not->toContain('super-secret-value')
        ->and($admin['providers']['google']['client_id'])->toBe('google-id')
        ->and($admin['providers']['google']['client_secret']['configured'])->toBeTrue()
        // Enough to tell two credentials apart while rotating, not enough to use.
        ->and($admin['providers']['google']['client_secret']['hint'])->toBe('••••alue');
});

it('masks a short secret entirely', function () {
    $encrypter  = new OAuthConfigEncrypterFake();
    $repository = oauth_config_repository([], $encrypter);

    Setting::configureSystem('oauth', [
        'providers' => ['google' => ['client_secret_encrypted' => $encrypter->encrypt('abc', false)]],
    ]);

    $admin = $repository->toAdminArray(['google' => GoogleDriver::configSchema()]);

    expect($admin['providers']['google']['client_secret']['hint'])->toBe('••••');
});

it('reports an unset secret as not configured', function () {
    $repository = oauth_config_repository();

    $admin = $repository->toAdminArray(['google' => GoogleDriver::configSchema()]);

    expect($admin['providers']['google']['client_secret'])->toBe(['configured' => false, 'hint' => null]);
});

it('encrypts secrets on save and drops any plaintext left behind', function () {
    $encrypter  = new OAuthConfigEncrypterFake();
    $repository = oauth_config_repository([], $encrypter);

    Setting::configureSystem('oauth', [
        'providers' => ['google' => ['client_secret' => 'legacy-plaintext']],
    ]);

    $repository->save(
        ['enabled' => true],
        ['google' => ['enabled' => true, 'client_id' => 'id-1', 'client_secret' => 'brand-new']],
        ['google' => ['client_secret']]
    );

    $stored = Setting::query()->where('key', 'system.oauth')->first()->value;

    expect($stored['providers']['google'])->not->toHaveKey('client_secret')
        ->and($stored['providers']['google']['client_secret' . OAuthProviderConfig::ENCRYPTED_SUFFIX])
            ->toBe($encrypter->encrypt('brand-new', false))
        ->and($stored['enabled'])->toBeTrue();
});

it('keeps the stored secret when an empty one is submitted', function () {
    $encrypter  = new OAuthConfigEncrypterFake();
    $repository = oauth_config_repository([], $encrypter);

    $repository->save([], ['google' => ['client_secret' => 'original']], ['google' => ['client_secret']]);
    // The admin UI renders a masked placeholder, so a save that did not touch the
    // field submits an empty string. That must not wipe the credential.
    $repository->save([], ['google' => ['client_id' => 'id-2', 'client_secret' => '   ']], ['google' => ['client_secret']]);

    $google = $repository->forProvider('google');

    expect($google->secret('client_secret'))->toBe('original')
        ->and($google->get('client_id'))->toBe('id-2');
});

it('does not discard another providers block on a partial save', function () {
    $repository = oauth_config_repository();

    $repository->save([], ['google' => ['client_id' => 'google-id']], []);
    $repository->save([], ['apple' => ['client_id' => 'apple-id']], []);

    expect($repository->forProvider('google')->get('client_id'))->toBe('google-id')
        ->and($repository->forProvider('apple')->get('client_id'))->toBe('apple-id');
});

it('reports apple configured only once every part of the signing key is present', function () {
    $encrypter  = new OAuthConfigEncrypterFake();
    $repository = oauth_config_repository([], $encrypter);

    Setting::configureSystem('oauth', [
        'providers' => [
            'apple' => ['client_id' => 'io.fleetbase.console', 'team_id' => 'TEAM123'],
        ],
    ]);

    $apple = $repository->forProvider('apple');

    expect($apple->isConfigured(['client_id', 'team_id', 'key_id'], ['private_key']))->toBeFalse();

    Setting::configureSystem('oauth', [
        'providers' => [
            'apple' => [
                'client_id'             => 'io.fleetbase.console',
                'team_id'               => 'TEAM123',
                'key_id'                => 'KEY123',
                'private_key_encrypted' => $encrypter->encrypt('-----BEGIN PRIVATE KEY-----', false),
            ],
        ],
    ]);

    expect($repository->forProvider('apple')->isConfigured(['client_id', 'team_id', 'key_id'], ['private_key']))->toBeTrue();
});

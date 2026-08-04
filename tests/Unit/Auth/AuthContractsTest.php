<?php

namespace Fleetbase\Auth {
    if (!function_exists(__NAMESPACE__ . '\\logger')) {
        function logger(): mixed
        {
            return app('log');
        }
    }
}

namespace {
    use Fleetbase\Auth\AppleVerifier;
    use Fleetbase\Auth\GoogleVerifier;
    use Fleetbase\Auth\Schemas\Developers;
    use Fleetbase\Auth\Schemas\IAM;
    use Fleetbase\Auth\Signers\AppleSignerInMemory;
    use Fleetbase\Auth\Signers\AppleSignerNone;
    use Google_Client as GoogleClient;
    use Illuminate\Container\Container;
    use Illuminate\Support\Facades\Facade;

    class AuthContractCacheFake
    {
        public array $remembered = [];
        public mixed $privateKey;
        public array $details;

        public function __construct(public string $kid = 'other-key')
        {
            $this->privateKey = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
            $this->details = openssl_pkey_get_details($this->privateKey);
        }

        public function remember(string $key, int $ttl, callable $callback): mixed
        {
            $this->remembered[] = [$key, $ttl];

            $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

            return [
                'keys' => [
                    [
                        'kty' => 'RSA',
                        'kid' => $this->kid,
                        'use' => 'sig',
                        'alg' => 'RS256',
                        'n'   => $encode($this->details['rsa']['n']),
                        'e'   => $encode($this->details['rsa']['e']),
                    ],
                ],
            ];
        }

        public function privateKeyContents(): string
        {
            openssl_pkey_export($this->privateKey, $privateKey);

            return $privateKey;
        }

        public function publicKeyContents(): string
        {
            return $this->details['key'];
        }
    }

    class AuthContractGoogleClientFake extends GoogleClient
    {
        public static mixed $payload        = null;
        public static ?Throwable $exception = null;
        public static array $instances      = [];

        public mixed $httpClient = null;

        public function __construct(public array $config = [])
        {
            static::$instances[] = $this;
        }

        public function setHttpClient($http)
        {
            $this->httpClient = $http;
        }

        public function verifyIdToken($idToken = null)
        {
            if (static::$exception) {
                throw static::$exception;
            }

            return static::$payload;
        }
    }

    class AuthContractGoogleVerifierFake extends GoogleVerifier
    {
        protected static function createGoogleClient(string $clientId): GoogleClient
        {
            return new AuthContractGoogleClientFake(['client_id' => $clientId]);
        }
    }

    function auth_contract_jwt(array $header, array $payload): string
    {
        $encode    = fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode('signature'), '+/', '-_'), '=');

        return $encode($header) . '.' . $encode($payload) . '.' . $signature;
    }

    function auth_contract_signed_apple_jwt(AuthContractCacheFake $cache, array $claims = []): string
    {
        $configuration = Lcobucci\JWT\Configuration::forAsymmetricSigner(
            new Lcobucci\JWT\Signer\Rsa\Sha256(),
            AppleSignerInMemory::plainText($cache->privateKeyContents()),
            AppleSignerInMemory::plainText($cache->publicKeyContents()),
        );

        $now = new DateTimeImmutable();

        return $configuration->builder()
            ->issuedBy($claims['iss'] ?? 'https://appleid.apple.com')
            ->issuedAt($claims['iat'] ?? $now)
            ->expiresAt($claims['exp'] ?? $now->modify('+5 minutes'))
            ->relatedTo($claims['sub'] ?? 'apple-user')
            ->withHeader('kid', $cache->kid)
            ->getToken($configuration->signer(), $configuration->signingKey())
            ->toString();
    }

    beforeEach(function () {
        bind_test_container([
            'app.debug' => false,
            'app.env'   => 'testing',
        ]);

        AuthContractGoogleClientFake::$payload   = null;
        AuthContractGoogleClientFake::$exception = null;
        AuthContractGoogleClientFake::$instances = [];
    });

    afterEach(function () {
        Facade::clearResolvedInstances();
        Container::setInstance(new FleetbaseTestContainer());
    });

    test('apple signer contracts keep plain text key material and none signature behavior stable', function () {
        $key    = AppleSignerInMemory::plainText('private-key-material', 'secret');
        $signer = new AppleSignerNone();

        expect($key->contents())->toBe('private-key-material')
            ->and($key->passphrase())->toBe('secret')
            ->and($signer->algorithmId())->toBe('none')
            ->and($signer->sign('payload', $key))->toBe('')
            ->and($signer->verify('', 'payload', $key))->toBeTrue()
            ->and($signer->verify('unexpected', 'payload', $key))->toBeFalse();
    });

    test('apple verifier rejects tokens when the cached apple key set does not contain the token key id', function () {
        $cache = new AuthContractCacheFake();
        app()->instance('cache', $cache);

        $jwt = auth_contract_jwt(
            ['alg' => 'RS256', 'kid' => 'missing-key'],
            ['iss' => 'https://appleid.apple.com', 'sub' => 'apple-user']
        );

        expect(fn () => AppleVerifier::verifyAppleJwt($jwt))
            ->toThrow(Exception::class, 'Invalid JWT Signature or missing key ID.')
            ->and($cache->remembered)->toBe([['apple-JWKSet', 300]]);
    });

    test('apple verifier returns true for a valid apple-issued token signed by the cached key', function () {
        $cache = new AuthContractCacheFake('matching-key');
        app()->instance('cache', $cache);

        $jwt = auth_contract_signed_apple_jwt($cache);

        expect(AppleVerifier::verifyAppleJwt($jwt))->toBeTrue()
            ->and($cache->remembered)->toBe([['apple-JWKSet', 300]]);
    });

    test('apple verifier wraps constraint failures for tokens signed by a known key', function () {
        $cache = new AuthContractCacheFake('matching-key');
        app()->instance('cache', $cache);

        $jwt = auth_contract_signed_apple_jwt($cache, [
            'iss' => 'https://issuer.example.test',
        ]);

        expect(fn () => AppleVerifier::verifyAppleJwt($jwt))
            ->toThrow(Exception::class, 'JWT validation failed:');
    });

    test('google verifier returns null for invalid identity tokens instead of leaking verification exceptions', function () {
        $result = GoogleVerifier::verifyIdToken('not-a-jwt', 'client-id.apps.googleusercontent.com');

        expect($result)->toBeNull();
    });

    test('google verifier returns verified payloads without configuring relaxed http outside debug environments', function () {
        AuthContractGoogleClientFake::$payload = [
            'sub'            => 'google-user-1',
            'email'          => 'user@example.test',
            'email_verified' => true,
            'aud'            => 'client-id.apps.googleusercontent.com',
        ];

        $result = AuthContractGoogleVerifierFake::verifyIdToken('valid-token', 'client-id.apps.googleusercontent.com');

        expect($result)->toBe(AuthContractGoogleClientFake::$payload)
            ->and(AuthContractGoogleClientFake::$instances)->toHaveCount(1)
            ->and(AuthContractGoogleClientFake::$instances[0]->config)->toBe([
                'client_id' => 'client-id.apps.googleusercontent.com',
            ])
            ->and(AuthContractGoogleClientFake::$instances[0]->httpClient)->toBeNull();
    });

    test('google verifier returns null when google verification returns a falsey payload', function () {
        AuthContractGoogleClientFake::$payload = false;

        expect(AuthContractGoogleVerifierFake::verifyIdToken('falsey-token', 'client-id.apps.googleusercontent.com'))->toBeNull();
    });

    test('google verifier logs fakeable client exceptions and returns null', function () {
        AuthContractGoogleClientFake::$exception = new RuntimeException('google rejected token');

        $result = AuthContractGoogleVerifierFake::verifyIdToken('bad-token', 'client-id.apps.googleusercontent.com');

        expect($result)->toBeNull()
            ->and(app('log')->entries[0][0])->toBe('error')
            ->and(app('log')->entries[0][1])->toBe('Google ID Token verification failed: google rejected token');
    });

    test('google verifier uses relaxed http verification in debug mode and still hides invalid token failures', function () {
        bind_test_container([
            'app.debug' => true,
            'app.env'   => 'development',
        ]);

        AuthContractGoogleClientFake::$exception = new RuntimeException('debug rejected token');

        $result = AuthContractGoogleVerifierFake::verifyIdToken('not-a-jwt', 'client-id.apps.googleusercontent.com');

        expect($result)->toBeNull()
            ->and(AuthContractGoogleClientFake::$instances[0]->httpClient)->not->toBeNull()
            ->and(app('log')->entries[0][0])->toBe('error')
            ->and(app('log')->entries[0][1])->toContain('Google ID Token verification failed:');
    });

    test('auth permission schemas expose stable guards resources policies and roles', function () {
        $developers = new Developers();
        $iam        = new IAM();

        expect($developers->name)->toBe('developers')
            ->and($developers->policyName)->toBe('Developers')
            ->and($developers->guards)->toBe(['sanctum'])
            ->and($developers->resources)->toContain([
                'name'    => 'api-key',
                'actions' => ['roll', 'export'],
            ])
            ->and($developers->policies[0]['name'])->toBe('FLBDeveloper')
            ->and($developers->policies[0]['permissions'])->toContain('* api-key', '* webhook', '* socket')
            ->and($developers->roles[0])->toMatchArray([
                'name'     => 'Fleetbase Developer',
                'policies' => ['FLBDeveloper'],
            ])
            ->and($iam->name)->toBe('iam')
            ->and($iam->policyName)->toBe('IAM')
            ->and($iam->guards)->toBe(['sanctum'])
            ->and($iam->permissions)->toBe(['change-password'])
            ->and($iam->resources[1])->toMatchArray([
                'name'    => 'user',
                'actions' => ['deactivate', 'activate', 'verify', 'export', 'change-password-for', 'change-email-for'],
            ])
            ->and($iam->policies[1])->toMatchArray([
                'name'        => 'PolicyManager',
                'permissions' => ['see extension', '* policy', '* role'],
            ])
            ->and($iam->roles[2]['name'])->toBe('IAM Administrator')
            ->and($iam->roles[2]['permissions'])->toContain('* user', '* group', '* role', '* policy');
    });
}

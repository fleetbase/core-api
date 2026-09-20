<?php

use Fleetbase\Auth\OAuth\Drivers\AppleDriver;
use Fleetbase\Auth\OAuth\Drivers\GithubDriver;
use Fleetbase\Auth\OAuth\Drivers\GoogleDriver;
use Fleetbase\Auth\OAuth\Drivers\MicrosoftDriver;
use Fleetbase\Auth\OAuth\Exceptions\OAuthException;
use Fleetbase\Auth\OAuth\IdTokenVerifier;
use Fleetbase\Auth\OAuth\OAuthProviderConfig;
use Fleetbase\Auth\OAuth\OAuthUserProfile;
use Illuminate\Http\Request;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Build a driver with plaintext config (no encrypter needed for normalization).
 *
 * @param array<string, mixed> $values
 */
function normalization_driver(string $class, array $values = []): object
{
    bind_test_container();

    return new $class(
        new OAuthProviderConfig($class::id(), $values, null),
        Request::create('/int/v1/auth/oauth/callback', 'GET'),
        new IdTokenVerifier()
    );
}

/**
 * @param array<string, mixed> $raw
 * @param array<string, mixed> $mapped
 */
function normalization_user(array $raw, array $mapped = []): SocialiteUser
{
    return (new SocialiteUser())->setRaw($raw)->map($mapped);
}

/**
 * normalize() is protected; Closure::call binds to the driver so the real method is
 * exercised rather than a test-only subclass that could drift from it.
 *
 * @param array<string, mixed> $callbackPayload
 */
function normalization_run(object $driver, SocialiteUser $user, array $callbackPayload = []): OAuthUserProfile
{
    return (fn () => $this->normalize($user, $callbackPayload))->call($driver);
}

// ---------------------------------------------------------------------------
// Google
// ---------------------------------------------------------------------------

it('trusts a google address only when google says it verified it', function (mixed $claim, bool $expected) {
    $driver = normalization_driver(GoogleDriver::class);

    $profile = normalization_run($driver, normalization_user(
        ['sub' => '1091', 'email' => 'ada@example.com', 'email_verified' => $claim],
        ['id'  => '1091', 'email' => 'ada@example.com', 'name' => 'Ada']
    ));

    expect($profile->emailVerified)->toBe($expected);
})->with([
    'boolean true'            => [true, true],
    'boolean false'           => [false, false],
    // The string "true" is NOT a verification for Google — only Apple sends that
    // shape, and accepting it here would widen the rule for no reason.
    'string true'             => ['true', false],
    'absent'                  => [null, false],
    'integer one'             => [1, false],
]);

it('maps a google profile', function () {
    $driver = normalization_driver(GoogleDriver::class);

    $profile = normalization_run($driver, normalization_user(
        ['sub' => '1091', 'email_verified' => true, 'hd' => 'fleetbase.io', 'locale' => 'en'],
        ['id'  => '1091', 'email' => 'ada@fleetbase.io', 'name' => 'Ada Lovelace', 'avatar' => 'https://x/y.png']
    ));

    expect($profile->provider)->toBe('google')
        ->and($profile->providerUserId)->toBe('1091')
        ->and($profile->email)->toBe('ada@fleetbase.io')
        ->and($profile->name)->toBe('Ada Lovelace')
        ->and($profile->avatar)->toBe('https://x/y.png')
        ->and($profile->meta)->toBe(['hd' => 'fleetbase.io', 'locale' => 'en']);
});

it('enforces a google workspace domain against the verified claim', function () {
    $driver = normalization_driver(GoogleDriver::class, ['hosted_domain' => 'fleetbase.io']);

    // Google documents that the `hd` authorization parameter is a hint, not a
    // guarantee — without this server-side check a user outside the domain can still
    // complete the flow.
    expect(fn () => normalization_run($driver, normalization_user(
        ['sub' => '1', 'email_verified' => true, 'hd' => 'evil.example'],
        ['id'  => '1', 'email' => 'mallory@evil.example']
    )))->toThrow(OAuthException::class, 'hosted_domain_mismatch');

    expect(fn () => normalization_run($driver, normalization_user(
        ['sub' => '1', 'email_verified' => true],
        ['id'  => '1', 'email' => 'mallory@gmail.com']
    )))->toThrow(OAuthException::class, 'hosted_domain_mismatch');

    $allowed = normalization_run($driver, normalization_user(
        ['sub' => '1', 'email_verified' => true, 'hd' => 'fleetbase.io'],
        ['id'  => '1', 'email' => 'ada@fleetbase.io']
    ));

    expect($allowed->email)->toBe('ada@fleetbase.io');
});

// ---------------------------------------------------------------------------
// Microsoft
// ---------------------------------------------------------------------------

it('trusts a microsoft address when the domain owner is verified', function () {
    $driver = normalization_driver(MicrosoftDriver::class, ['tenant' => 'common']);

    $profile = normalization_run($driver, normalization_user(
        ['oid' => 'oid-1', 'tid' => 'some-work-tenant', 'xms_edov' => true, 'email' => 'ada@corp.example'],
        ['id'  => 'oid-1', 'email' => 'ada@corp.example', 'name' => 'Ada']
    ));

    expect($profile->emailVerified)->toBeTrue()
        ->and($profile->providerUserId)->toBe('oid-1')
        ->and($profile->meta['tid'])->toBe('some-work-tenant');
});

it('does not trust a multi tenant microsoft address without the domain owner claim', function () {
    $driver = normalization_driver(MicrosoftDriver::class, ['tenant' => 'common']);

    // Anyone can stand up an Entra tenant, and a personal account holder can set an
    // arbitrary preferred_username. On 'common' only xms_edov is evidence.
    $work = normalization_run($driver, normalization_user(
        ['oid' => 'oid-1', 'tid' => 'attacker-tenant', 'email' => 'ceo@victim.example'],
        ['id'  => 'oid-1', 'email' => 'ceo@victim.example']
    ));

    $personal = normalization_run($driver, normalization_user(
        ['oid' => 'oid-2', 'tid' => MicrosoftDriver::MSA_CONSUMER_TENANT, 'preferred_username' => 'ceo@victim.example'],
        ['id'  => 'oid-2', 'email' => 'ceo@victim.example']
    ));

    expect($work->emailVerified)->toBeFalse()
        ->and($personal->emailVerified)->toBeFalse();
});

it('trusts a single tenant microsoft address from the configured directory', function () {
    $driver = normalization_driver(MicrosoftDriver::class, ['tenant' => 'CONTOSO-TENANT-ID']);

    // The operator controls this directory, so its addresses are as trustworthy as
    // the operator's own user list.
    $matching = normalization_run($driver, normalization_user(
        ['oid' => 'oid-1', 'tid' => 'contoso-tenant-id', 'email' => 'ada@contoso.example'],
        ['id'  => 'oid-1', 'email' => 'ada@contoso.example']
    ));

    $other = normalization_run($driver, normalization_user(
        ['oid' => 'oid-2', 'tid' => 'another-tenant', 'email' => 'mallory@evil.example'],
        ['id'  => 'oid-2', 'email' => 'mallory@evil.example']
    ));

    expect($matching->emailVerified)->toBeTrue()
        ->and($other->emailVerified)->toBeFalse();
});

it('never trusts a personal microsoft account even on a single tenant deployment', function () {
    $driver = normalization_driver(MicrosoftDriver::class, ['tenant' => MicrosoftDriver::MSA_CONSUMER_TENANT]);

    $profile = normalization_run($driver, normalization_user(
        ['oid' => 'oid-1', 'tid' => MicrosoftDriver::MSA_CONSUMER_TENANT, 'email' => 'someone@outlook.com'],
        ['id'  => 'oid-1', 'email' => 'someone@outlook.com']
    ));

    expect($profile->emailVerified)->toBeFalse();
});

it('falls back to preferred username when microsoft sends no email claim', function () {
    $driver = normalization_driver(MicrosoftDriver::class, ['tenant' => 'common']);

    $user = (new Fleetbase\Auth\OAuth\Socialite\MicrosoftProvider(
        Request::create('/'), 'client', 'secret', 'https://api.example/callback'
    ));

    $mapped = (fn () => $this->mapUserToObject([
        'oid'                => 'oid-1',
        'tid'                => 'tenant',
        'preferred_username' => 'ada@corp.example',
        'name'               => 'Ada',
    ]))->call($user);

    expect($mapped->getEmail())->toBe('ada@corp.example')
        ->and($mapped->getId())->toBe('oid-1');
});

// ---------------------------------------------------------------------------
// GitHub
// ---------------------------------------------------------------------------

it('treats a present github address as verified and an absent one as unusable', function () {
    $driver = normalization_driver(GithubDriver::class);

    // Socialite's GithubProvider only returns an address when GitHub reports it
    // primary AND verified, and nulls it otherwise — so presence IS the flag.
    $verified = normalization_run($driver, normalization_user(
        ['id' => 42, 'login' => 'ada'],
        ['id' => 42, 'email' => 'ada@example.com', 'name' => 'Ada', 'nickname' => 'ada']
    ));

    $unverified = normalization_run($driver, normalization_user(
        ['id' => 43, 'login' => 'mallory'],
        ['id' => 43, 'email' => null, 'name' => 'Mallory', 'nickname' => 'mallory']
    ));

    expect($verified->emailVerified)->toBeTrue()
        ->and($verified->providerUserId)->toBe('42')
        ->and($verified->meta)->toBe(['login' => 'ada'])
        ->and($unverified->emailVerified)->toBeFalse()
        ->and($unverified->email)->toBeNull();
});

it('does not treat an empty github address as verified', function () {
    $driver = normalization_driver(GithubDriver::class);

    $profile = normalization_run($driver, normalization_user(['id' => 44], ['id' => 44, 'email' => '']));

    expect($profile->emailVerified)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Apple
// ---------------------------------------------------------------------------

it('accepts both shapes apple uses for email_verified', function (mixed $claim, bool $expected) {
    $driver = normalization_driver(AppleDriver::class);

    $profile = normalization_run($driver, normalization_user(
        ['sub' => 'apple-sub', 'email' => 'ada@example.com', 'email_verified' => $claim],
        ['id'  => 'apple-sub', 'email' => 'ada@example.com']
    ));

    expect($profile->emailVerified)->toBe($expected);
})->with([
    'boolean true'  => [true, true],
    'boolean false' => [false, false],
    // Apple sends the string form in some flows; both mean verified.
    'string true'   => ['true', true],
    'string false'  => ['false', false],
    'absent'        => [null, false],
]);

it('flags an apple private relay alias', function () {
    $driver = normalization_driver(AppleDriver::class);

    // A relay alias is unique per application, so it can never match an address
    // Fleetbase already holds and must not be used for an account-exists check.
    $relay = normalization_run($driver, normalization_user(
        ['sub' => 'apple-sub', 'email' => 'ABC123@PrivateRelay.AppleID.com', 'email_verified' => true],
        ['id'  => 'apple-sub', 'email' => 'ABC123@PrivateRelay.AppleID.com']
    ));

    $real = normalization_run($driver, normalization_user(
        ['sub' => 'apple-sub-2', 'email' => 'ada@example.com', 'email_verified' => true],
        ['id'  => 'apple-sub-2', 'email' => 'ada@example.com']
    ));

    expect($relay->meta('private_relay'))->toBeTrue()
        ->and($real->meta)->toBe([]);
});

it('lifts the apple display name out of the first authorization callback', function () {
    $driver = normalization_driver(AppleDriver::class);

    // Apple sends the name exactly once, in the body of the FIRST authorization, and
    // never again — if it is not captured here it is gone for good.
    $fromJson = normalization_run($driver, normalization_user(
        ['sub' => 'apple-sub', 'email_verified' => true],
        ['id'  => 'apple-sub', 'email' => 'ada@example.com']
    ), ['user' => json_encode(['name' => ['firstName' => 'Ada', 'lastName' => 'Lovelace']])]);

    $fromArray = normalization_run($driver, normalization_user(
        ['sub' => 'apple-sub', 'email_verified' => true],
        ['id'  => 'apple-sub', 'email' => 'ada@example.com']
    ), ['user' => ['name' => ['firstName' => 'Ada', 'lastName' => 'Lovelace']]]);

    expect($fromJson->name)->toBe('Ada Lovelace')
        ->and($fromArray->name)->toBe('Ada Lovelace');
});

it('tolerates every shape of missing apple name', function (mixed $payload) {
    $driver = normalization_driver(AppleDriver::class);

    $profile = normalization_run($driver, normalization_user(
        ['sub' => 'apple-sub', 'email_verified' => true],
        ['id'  => 'apple-sub', 'email' => 'ada@example.com']
    ), $payload === null ? [] : ['user' => $payload]);

    expect($profile->name)->toBeNull();
})->with([
    'no user field'      => [null],
    'malformed json'     => ['{not json'],
    'no name key'        => ['{"email":"ada@example.com"}'],
    'empty name parts'   => ['{"name":{"firstName":"","lastName":""}}'],
    'name not an object' => ['{"name":"Ada"}'],
]);

it('drops the raw token response from anything persisted or serialized', function () {
    $profile = (new OAuthUserProfile('google', '1091', 'ada@example.com', true, 'Ada'))
        ->withRawTokenResponse(['access_token' => 'at-secret', 'refresh_token' => 'rt-secret', 'id_token' => 'jwt']);

    // Fleetbase never stores provider tokens; a database compromise must yield no
    // live provider credentials.
    expect(json_encode($profile))->not->toContain('at-secret')
        ->and(json_encode($profile))->not->toContain('rt-secret')
        ->and(json_encode($profile->toIdentityAttributes()))->not->toContain('at-secret')
        ->and($profile->toIdentityAttributes())->not->toHaveKey('rawTokenResponse')
        // …but it is still readable in memory for the driver that needs id_token claims.
        ->and($profile->rawTokenResponse['id_token'])->toBe('jwt');
});

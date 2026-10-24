<?php

use Fleetbase\Http\Controllers\Internal\v1\ReviewAccessController;
use Fleetbase\Models\Company;
use Fleetbase\Models\Model as FleetbaseModel;
use Fleetbase\Models\ReviewAccess;
use Fleetbase\Support\AppReview;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store as SessionStore;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;

class AppReviewTestStore extends FleetbaseModel
{
    protected $table   = 'app_review_test_stores';
    protected $guarded = [];
}

class AppReviewTestNetwork extends FleetbaseModel
{
    protected $table   = 'app_review_test_networks';
    protected $guarded = [];
}

/**
 * An in-memory database with two stores in two companies, a network and the review access
 * table, and the store and network types registered the way Storefront registers them.
 */
function app_review_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
    $container  = bind_test_container([
        'database.default'             => 'testing',
        'database.connections.testing' => $connection,
        'database.connections.mysql'   => $connection,
        'fleetbase.connection.db'      => 'testing',
    ]);
    $container->instance('hash', new class {
        public function make($value, array $options = []): string
        {
            return password_hash((string) $value, PASSWORD_BCRYPT);
        }

        public function check($value, $hashedValue, array $options = []): bool
        {
            return is_string($hashedValue) && password_verify((string) $value, $hashedValue);
        }
    });
    $container->instance('cache', new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
    $container->instance('responsecache', new class {
        public function clear(): void
        {
        }
    });
    Facade::clearResolvedInstances();

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'testing');
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('testing');
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $capsule->getConnection('testing')->getSchemaBuilder());
    Facade::clearResolvedInstance('db');
    Facade::clearResolvedInstance('db.schema');

    $schema = $capsule->getConnection('testing')->getSchemaBuilder();
    foreach (['app_review_test_stores', 'app_review_test_networks'] as $table) {
        $schema->create($table, function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('company_uuid')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
    // Company lives on the core connection.
    $capsule->getConnection('mysql')->getSchemaBuilder()->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->text('options')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    // The migration, as it runs on the core connection.
    (require __DIR__ . '/../../../migrations/2026_10_24_000000_create_review_accesses_table.php')->up();

    $db = $capsule->getConnection('testing');
    $db->table('app_review_test_stores')->insert([
        ['uuid' => 'store-a', 'public_id' => 'store_a', 'company_uuid' => 'company-a'],
        ['uuid' => 'store-b', 'public_id' => 'store_b', 'company_uuid' => 'company-b'],
    ]);
    $db->table('app_review_test_networks')->insert([
        ['uuid' => 'network-a', 'public_id' => 'network_a', 'company_uuid' => 'company-a'],
    ]);
    $capsule->getConnection('mysql')->table('companies')->insert([
        ['uuid' => 'company-a', 'public_id' => 'company_a', 'name' => 'Corner Co'],
        ['uuid' => 'company-b', 'public_id' => 'company_b', 'name' => 'Night Co'],
    ]);

    ReviewAccess::creating(fn ($access) => $access->uuid ??= (string) Str::uuid());

    AppReview::register('test-store', ['model' => AppReviewTestStore::class, 'phone' => '+1 (202) 555-0143', 'name' => 'Storefront']);
    AppReview::register('test-network', ['model' => AppReviewTestNetwork::class, 'phone' => '+12025550143', 'name' => 'Storefront']);
    AppReview::register('test-navigator', ['model' => Company::class, 'phone' => '+12025550144', 'name' => 'Navigator']);

    return $capsule;
}

function app_review_store(string $uuid): AppReviewTestStore
{
    return AppReviewTestStore::where('uuid', $uuid)->first();
}

function app_review_console_request(string $company, array $input = [], string $method = 'PUT'): Request
{
    $request = Request::create('/int/v1/review-access', $method, $input);
    $request->setLaravelSession(new SessionStore('app-review-test', new ArraySessionHandler(120)));
    $request->session()->put('company', $company);

    return $request;
}

afterEach(function () {
    foreach (['test-store', 'test-network', 'test-navigator', 'test-guarded'] as $type) {
        AppReview::forget($type);
    }
    EloquentModel::unsetConnectionResolver();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('review numbers must come from the range reserved for fiction', function () {
    bind_test_container();

    expect(AppReview::isFictionalPhone('+12025550143'))->toBeTrue()
        ->and(AppReview::isFictionalPhone('+1 (415) 555-0199'))->toBeTrue()
        ->and(AppReview::isFictionalPhone('+12025550200'))->toBeFalse()
        ->and(AppReview::isFictionalPhone('+12025551234'))->toBeFalse()
        ->and(AppReview::isFictionalPhone('+97699112233'))->toBeFalse()
        ->and(AppReview::isFictionalPhone(null))->toBeFalse()
        ->and(AppReview::formatPhone('+12025550143'))->toBe('+1 202 555 0143')
        ->and(fn () => AppReview::register('test-guarded', ['model' => AppReviewTestStore::class, 'phone' => '+12025551234']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => AppReview::register('test-guarded', ['model' => 'Missing\\Model', 'phone' => '+12025550143']))->toThrow(InvalidArgumentException::class);

    // libphonenumber accepts the numbers the apps register.
    $util = libphonenumber\PhoneNumberUtil::getInstance();
    foreach (['+12025550143', '+12025550144'] as $phone) {
        expect($util->isValidNumber($util->parse($phone, null)))->toBeTrue();
    }
});

test('turning access on creates a code that only works for that subject', function () {
    app_review_database();
    $storeA = app_review_store('store-a');
    $storeB = app_review_store('store-b');

    expect(AppReview::typeOf($storeA))->toBe('test-store')
        ->and(AppReview::isEnabledFor($storeA))->toBeFalse()
        ->and(AppReview::acceptsCode($storeA, '+12025550143', '000000'))->toBeFalse();

    [$access, $codeA] = AppReview::update($storeA, true);
    do {
        [, $codeB] = AppReview::update($storeB, true, true);
    } while ($codeB === $codeA);

    expect($codeA)->toMatch('/^\d{6}$/')
        ->and($access->getRawOriginal('code'))->not->toBe($codeA)
        ->and($access->toArray())->not->toHaveKey('code')
        ->and(AppReview::isEnabledFor($storeA))->toBeTrue()
        ->and(AppReview::acceptsCode($storeA, '+1 202 555 0143', $codeA))->toBeTrue()
        ->and(AppReview::acceptsCode($storeA, '+12025550143', $codeB))->toBeFalse()
        ->and(AppReview::acceptsCode($storeB, '+12025550143', $codeA))->toBeFalse()
        // Only the type's own number signs in.
        ->and(AppReview::acceptsCode($storeA, '+12025550144', $codeA))->toBeFalse()
        ->and(AppReview::acceptsCode($storeA, '+97699112233', $codeA))->toBeFalse()
        ->and(AppReview::acceptsCode(null, '+12025550143', $codeA))->toBeFalse();
});

test('turning access off keeps the code, and regenerating replaces it', function () {
    app_review_database();
    $store = app_review_store('store-a');

    [, $code] = AppReview::update($store, true);
    [, $none] = AppReview::update($store, false);
    expect($none)->toBeNull()
        ->and(AppReview::acceptsCode($store, '+12025550143', $code))->toBeFalse()
        ->and(AppReview::status($store))->toMatchArray(['enabled' => false, 'has_code' => true]);

    [, $restored] = AppReview::update($store, true);
    expect($restored)->toBeNull()
        ->and(AppReview::acceptsCode($store, '+12025550143', $code))->toBeTrue();

    [, $new] = AppReview::update($store, null, true);
    expect($new)->toMatch('/^\d{6}$/')
        ->and(AppReview::acceptsCode($store, '+12025550143', $new))->toBeTrue()
        ->and($new === $code || !AppReview::acceptsCode($store, '+12025550143', $code))->toBeTrue()
        ->and(ReviewAccess::count())->toBe(1);
});

test('a company can be the subject, with its own review number', function () {
    app_review_database();
    $company = Company::where('uuid', 'company-a')->first();

    [, $code] = AppReview::update($company, true);

    expect(AppReview::typeOf($company))->toBe('test-navigator')
        ->and(AppReview::status($company))->toMatchArray(['type' => 'test-navigator', 'phone' => '+12025550144', 'phone_display' => '+1 202 555 0144', 'enabled' => true])
        ->and(AppReview::acceptsCode($company, '+12025550144', $code))->toBeTrue()
        ->and(AppReview::acceptsCode($company, '+12025550143', $code))->toBeFalse()
        ->and(ReviewAccess::first()->company_uuid)->toBe('company-a')
        ->and(AppReview::resolveSubject('test-navigator', 'company_a', 'company-a')?->uuid)->toBe('company-a')
        ->and(AppReview::resolveSubject('test-navigator', 'company_b', 'company-a'))->toBeNull();
});

test('a subject is found by uuid or public id in the signed-in company only', function () {
    app_review_database();

    expect(AppReview::resolveSubject('test-store', 'store-a', 'company-a')?->uuid)->toBe('store-a')
        ->and(AppReview::resolveSubject('test-store', 'store_a', 'company-a')?->uuid)->toBe('store-a')
        ->and(AppReview::resolveSubject('test-store', 'store_b', 'company-a'))->toBeNull()
        ->and(AppReview::resolveSubject('test-store', 'store_a', null))->toBeNull()
        ->and(AppReview::resolveSubject('unknown-type', 'store_a', 'company-a'))->toBeNull();
});

test('the instance-wide review accounts only accept their code for listed identities', function () {
    bind_test_container([
        'app.review.code'     => '135790',
        'app.review.accounts' => [' Reviewer@Example.Test ', '+12025550100'],
    ]);

    expect(AppReview::isConfiguredAccount('app.review.code', 'app.review.accounts', 'reviewer@example.test'))->toBeTrue()
        ->and(AppReview::isConfiguredAccount('app.review.code', 'app.review.accounts', 'other@example.test'))->toBeFalse()
        ->and(AppReview::configuredAccountMatches('app.review.code', 'app.review.accounts', '+12025550100', '135790'))->toBeTrue()
        ->and(AppReview::configuredAccountMatches('app.review.code', 'app.review.accounts', '+12025550100', '135791'))->toBeFalse()
        ->and(AppReview::configuredAccountMatches('app.review.code', 'app.review.accounts', 'other@example.test', '135790'))->toBeFalse()
        ->and(AppReview::configuredAccountMatches('app.review.code', 'app.review.accounts', '+12025550100', null))->toBeFalse();

    config(['app.review.code' => null]);
    expect(AppReview::configuredAccountMatches('app.review.code', 'app.review.accounts', '+12025550100', ''))->toBeFalse()
        ->and(AppReview::isConfiguredAccount('app.review.code', 'app.review.accounts', '+12025550100'))->toBeFalse();
});

test('the Console endpoint shows the code once and never again', function () {
    app_review_database();
    app()->instance('validator', new Illuminate\Validation\Factory(new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en'), app()));
    Request::macro('validate', function (array $rules) {
        return app('validator')->make($this->all(), $rules)->validate();
    });
    $controller = new ReviewAccessController();

    $initial = $controller->show('test-store', 'store_a', app_review_console_request('company-a', [], 'GET'))->getData(true)['review_access'];
    expect($initial)->toBe([
        'type'              => 'test-store',
        'enabled'           => false,
        'phone'             => '+12025550143',
        'phone_display'     => '+1 202 555 0143',
        'has_code'          => false,
        'code_generated_at' => null,
        'enabled_at'        => null,
    ]);

    $on    = $controller->update('test-store', 'store_a', app_review_console_request('company-a', ['enabled' => true]))->getData(true)['review_access'];
    $again = $controller->show('test-store', 'store_a', app_review_console_request('company-a', [], 'GET'))->getData(true)['review_access'];
    $saved = $controller->update('test-store', 'store_a', app_review_console_request('company-a', ['enabled' => true]))->getData(true)['review_access'];

    expect($on['enabled'])->toBeTrue()
        ->and($on['code'])->toMatch('/^\d{6}$/')
        ->and($again)->not->toHaveKey('code')
        ->and($saved)->not->toHaveKey('code')
        ->and(AppReview::acceptsCode(app_review_store('store-a'), '+12025550143', $on['code']))->toBeTrue();

    // Another company's store, and a type nobody registered, are not found.
    expect($controller->update('test-store', 'store_b', app_review_console_request('company-a', ['enabled' => true]))->getStatusCode())->toBe(404)
        ->and($controller->show('nothing', 'store_a', app_review_console_request('company-a', [], 'GET'))->getStatusCode())->toBe(404)
        ->and($controller->update('test-network', 'network_a', app_review_console_request('company-a', ['enabled' => true]))->getData(true)['review_access']['type'])->toBe('test-network');
});

test('a registered permission is required to change access', function () {
    app_review_database();
    AppReview::register('test-guarded', ['model' => AppReviewTestStore::class, 'phone' => '+12025550143', 'permission' => 'storefront manage-app-review store']);

    // Nobody signed in, so nobody holds the permission: refused before anything changes.
    $response = (new ReviewAccessController())->update('test-guarded', 'store_a', app_review_console_request('company-a', ['enabled' => true]));

    expect($response->getStatusCode())->toBe(401)
        ->and(ReviewAccess::count())->toBe(0);
})->skip(fn () => !method_exists(Fleetbase\Support\Auth::class, 'getUserFromSession'));

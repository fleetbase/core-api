<?php

use Fleetbase\Http\Controllers\Internal\v1\LookupController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

class LookupControllerContractsCacheFake
{
    public array $values    = [];
    public array $forgotten = [];

    public function remember(string $key, mixed $ttl, callable $callback): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            $this->values[$key] = $callback();
        }

        return $this->values[$key];
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function forget(string $key): bool
    {
        $this->forgotten[] = $key;
        unset($this->values[$key]);

        return true;
    }
}

class LookupControllerContractsController extends LookupController
{
    public array $fetchedLimits = [];

    public function __construct(private array $posts = [])
    {
    }

    protected function fetchBlogPosts(int $limit): array
    {
        $this->fetchedLimits[] = $limit;

        return array_slice($this->posts, 0, $limit);
    }

    protected function getFleetbaseBlogFeedUrl(): string
    {
        return 'https://feeds.example.test/fleetbase.xml';
    }

    protected function getFleetbaseBlogUrl(): string
    {
        return 'https://www.example.test/blog';
    }
}

function lookup_controller_request(array $query = []): Request
{
    return Request::create('/int/v1/lookup', 'GET', $query);
}

function lookup_controller_boot(?LookupControllerContractsCacheFake $cache = null): LookupControllerContractsCacheFake
{
    bind_test_container();

    if (!Request::hasMacro('array')) {
        Request::macro('array', function (string $key, array $default = []): array {
            $value = $this->input($key, $default);

            return is_array($value) ? $value : $default;
        });
    }

    $cache ??= new LookupControllerContractsCacheFake();
    app()->instance('cache', $cache);
    Cache::swap($cache);
    Facade::clearResolvedInstance('cache');

    return $cache;
}

function with_lookup_controller_country_deprecations_suppressed(callable $callback)
{
    $previousReporting = error_reporting();

    error_reporting($previousReporting & ~E_DEPRECATED);
    set_error_handler(function (int $severity, string $message): bool {
        return $severity === E_DEPRECATED && str_contains($message, 'PragmaRX\\Coollection\\Package\\Coollection');
    }, E_DEPRECATED);

    try {
        return $callback();
    } finally {
        restore_error_handler();
        error_reporting($previousReporting);
    }
}

afterEach(function () {
    Facade::clearResolvedInstances();
});

test('lookup countries supports search projection simple shape and include exclude filters', function () {
    lookup_controller_boot();

    with_lookup_controller_country_deprecations_suppressed(function () {
        $controller = new LookupController();

        $projected = $controller->countries(lookup_controller_request([
            'query'   => 'mng',
            'columns' => ['cca2', ['currency' => 'currency_code'], ['geo.region' => 'region']],
        ]))->getData(true);

        $simple = $controller->countries(lookup_controller_request([
            'query'  => 'mnt',
            'simple' => true,
        ]))->getData(true);

        $filtered = $controller->countries(lookup_controller_request([
            'only'   => ['mn', 'us'],
            'except' => ['us'],
        ]))->getData(true);

        expect($projected)->toBe([
            [
                'cca2'          => 'MN',
                'currency_code' => 'MNT',
                'region'        => 'Asia',
            ],
        ])
            ->and($simple[0])->toMatchArray([
                'name'     => 'Mongolia',
                'code'     => 'MNG',
                'currency' => 'MNT',
                'emoji'    => "\u{1F1F2}\u{1F1F3}",
                'cca2'     => 'MN',
                'abbrev'   => 'Mong.',
            ])
            ->and($filtered)->toHaveCount(1)
            ->and($filtered[0]['cca2'])->toBe('MN')
            ->and($filtered[0]['name'])->toBe('Mongolia');
    });
});

test('lookup country returns simple responses by default and full country data when requested', function () {
    lookup_controller_boot();

    with_lookup_controller_country_deprecations_suppressed(function () {
        $controller = new LookupController();

        $simple = $controller->country('mn', lookup_controller_request())->getData(true);
        $full   = $controller->country('mn', lookup_controller_request(['simple' => false]))->getData(true);
        $none   = $controller->country('zz', lookup_controller_request())->getData(true);

        expect($simple)->toMatchArray([
            'name'     => 'Mongolia',
            'code'     => 'MNG',
            'currency' => 'MNT',
            'cca2'     => 'MN',
        ])
            ->and($full['cca2'])->toBe('MN')
            ->and($full['name'])->toBe('Mongolia')
            ->and($none)->toBe([]);
    });
});

test('lookup currencies filters by code or title and preserves currency response fields', function () {
    lookup_controller_boot();

    $controller = new LookupController();

    $byCode  = $controller->currencies(lookup_controller_request(['query' => 'mnt']))->getData(true);
    $byTitle = $controller->currencies(lookup_controller_request(['query' => 'dollar']))->getData(true);

    expect($byCode)->toHaveCount(1)
        ->and($byCode[0])->toMatchArray([
            'code'              => 'MNT',
            'title'             => 'Mongolian tugrik',
            'symbol'            => '₮',
            'precision'         => 0,
            'thousandSeparator' => ',',
            'decimalSeparator'  => '',
            'symbolPlacement'   => 'before',
        ])
        ->and(collect($byTitle)->pluck('code'))->toContain('USD');
});

test('lookup blog endpoint clamps limits caches responses and reports cache status', function () {
    $cache = lookup_controller_boot();

    $controller = new LookupControllerContractsController([
        ['title' => 'One'],
        ['title' => 'Two'],
    ]);

    $first  = $controller->fleetbaseBlog(lookup_controller_request(['limit' => 50]));
    $second = $controller->fleetbaseBlog(lookup_controller_request(['limit' => 50]));

    expect($first->getStatusCode())->toBe(200)
        ->and($first->getData(true))->toBe([
            ['title' => 'One'],
            ['title' => 'Two'],
        ])
        ->and($first->headers->getCacheControlDirective('public'))->toBeTrue()
        ->and($first->headers->getCacheControlDirective('max-age'))->toBe('345600')
        ->and($first->headers->get('X-Cache-Status'))->toBe('HIT')
        ->and($second->getData(true))->toBe($first->getData(true))
        ->and($controller->fetchedLimits)->toBe([20])
        ->and(array_keys($cache->values))->toHaveCount(1)
        ->and(array_key_first($cache->values))->toStartWith('fleetbase_blog_posts_20_');
});

test('lookup refresh blog cache clears known limits and warms the default feed', function () {
    $cache      = lookup_controller_boot();
    $controller = new LookupControllerContractsController([
        ['title' => 'One'],
        ['title' => 'Two'],
        ['title' => 'Three'],
    ]);

    $response = $controller->refreshBlogCache();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'status'      => 'success',
            'message'     => 'Blog cache refreshed',
            'posts_count' => 3,
        ])
        ->and($controller->fetchedLimits)->toBe([6])
        ->and($cache->forgotten)->toHaveCount(3)
        ->and($cache->forgotten[0])->toStartWith('fleetbase_blog_posts_6_')
        ->and($cache->forgotten[1])->toStartWith('fleetbase_blog_posts_10_')
        ->and($cache->forgotten[2])->toStartWith('fleetbase_blog_posts_20_')
        ->and(array_key_first($cache->values))->toStartWith('fleetbase_blog_posts_6_');
});

test('lookup timezones returns php timezone identifiers as a json response', function () {
    lookup_controller_boot();

    $response = (new LookupController())->timezones();
    $payload  = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($payload)->toContain('UTC', 'Asia/Ulaanbaatar', 'America/New_York');
});

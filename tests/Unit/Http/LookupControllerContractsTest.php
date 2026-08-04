<?php

use Fleetbase\Http\Controllers\Internal\v1\LookupController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;

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

class LookupControllerContractsLogFake
{
    public array $warnings = [];
    public array $errors   = [];

    public function warning(string $message, array $context = []): void
    {
        $this->warnings[] = compact('message', 'context');
    }

    public function error(string $message, array $context = []): void
    {
        $this->errors[] = compact('message', 'context');
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

class LookupControllerContractsRssController extends LookupController
{
    public function __construct(private string $feedUrl = 'https://feeds.example.test/fleetbase.xml')
    {
    }

    public function fetchFixturePosts(int $limit): array
    {
        return $this->fetchBlogPosts($limit);
    }

    public function parseFixtureRss(string $rssXml, int $limit): array
    {
        return $this->parseBlogPostsFromRss($rssXml, $limit);
    }

    protected function getFleetbaseBlogFeedUrl(): string
    {
        return $this->feedUrl;
    }

    protected function getFleetbaseBlogUrl(): string
    {
        return 'https://www.example.test/blog';
    }
}

class LookupControllerContractsBlogProbe extends LookupController
{
    public function feedUrl(): string
    {
        return $this->getFleetbaseBlogFeedUrl();
    }

    public function blogUrl(): string
    {
        return $this->getFleetbaseBlogUrl();
    }

    public function normalizedLink(?string $link): string
    {
        return $this->normalizeFleetbaseBlogLink($link);
    }
}

class LookupControllerContractsIconController extends LookupController
{
    public function __construct(private string $metadata)
    {
    }

    protected function fetchFontAwesomeIconMetadata(): string
    {
        return $this->metadata;
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

    $byCode   = $controller->currencies(lookup_controller_request(['query' => 'mnt']))->getData(true);
    $byTitle  = $controller->currencies(lookup_controller_request(['query' => 'dollar']))->getData(true);
    $all      = $controller->currencies(lookup_controller_request(['query' => '']))->getData(true);

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
        ->and(collect($byTitle)->pluck('code'))->toContain('USD')
        ->and(collect($all)->pluck('code'))->toContain('MNT', 'USD');
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

test('lookup font awesome icons filters by query id prefix and limit without network access', function () {
    lookup_controller_boot();

    $controller = new LookupControllerContractsIconController(json_encode([
        'truck-fast' => [
            'label'  => 'Truck Fast',
            'styles' => ['solid', 'regular'],
            'search' => [
                'terms' => ['delivery', 'transport'],
            ],
        ],
        'warehouse' => [
            'label'  => 'Warehouse',
            'styles' => ['solid'],
            'search' => [
                'terms' => ['storage', 'inventory'],
            ],
        ],
    ]));

    $queryMatches  = $controller->fontAwesomeIcons(lookup_controller_request(['query' => 'deliver']));
    $prefixMatches = $controller->fontAwesomeIcons(lookup_controller_request(['id' => 'truck-fast', 'prefix' => 'far']));
    $limited       = $controller->fontAwesomeIcons(lookup_controller_request(['limit' => 1]));
    $missing       = $controller->fontAwesomeIcons(lookup_controller_request(['query' => 'airplane']));

    expect($queryMatches)->toBe([
        ['prefix' => 'fas', 'label' => 'Truck Fast', 'id' => 'truck-fast'],
        ['prefix' => 'far', 'label' => 'Truck Fast', 'id' => 'truck-fast'],
    ])
        ->and($prefixMatches)->toBe([
            ['prefix' => 'far', 'label' => 'Truck Fast', 'id' => 'truck-fast'],
        ])
        ->and($limited)->toBe([
            ['prefix' => 'fas', 'label' => 'Truck Fast', 'id' => 'truck-fast'],
            ['prefix' => 'far', 'label' => 'Truck Fast', 'id' => 'truck-fast'],
        ])
        ->and($missing)->toBe([]);
});

test('lookup whois returns resolved payloads and stable error responses', function () {
    lookup_controller_boot();
    config(['fleetbase.services.ipinfo.api_key' => null]);
    Http::fake([
        'https://json.geoiplookup.io/8.8.8.8' => Http::response([
            'ip'           => '8.8.8.8',
            'country_code' => 'US',
        ]),
        'https://json.geoiplookup.io/1.1.1.1' => Http::response([
            'message' => 'lookup quota exceeded',
        ], 429),
    ]);

    $controller = new LookupController();
    $success    = $controller->whois(Request::create('/int/v1/lookup/whois', 'GET', [], [], [], ['REMOTE_ADDR' => '8.8.8.8']));
    $failure    = $controller->whois(Request::create('/int/v1/lookup/whois', 'GET', [], [], [], ['REMOTE_ADDR' => '1.1.1.1']));

    expect($success->getStatusCode())->toBe(200)
        ->and($success->getData(true))->toBe([
            'ip'           => '8.8.8.8',
            'country_code' => 'US',
        ])
        ->and($failure->getStatusCode())->toBe(400)
        ->and($failure->getData(true))->toBe(['errors' => ['lookup quota exceeded']]);
});

test('lookup blog rss parsing normalizes links limits posts and fetches through http client', function () {
    lookup_controller_boot();
    Http::fake([
        'https://feeds.example.test/fleetbase.xml' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <item>
      <title>First Update</title>
      <link>https://fleetbase.ghost.io/first-update/</link>
      <description><![CDATA[<p>First description</p>]]></description>
      <pubDate>Sat, 18 Jul 2026 10:00:00 GMT</pubDate>
      <category>Release</category>
    </item>
    <item>
      <title>Second Update</title>
      <link>https://www.example.test/blog/second-update/</link>
      <description>Second description</description>
      <pubDate>Sat, 18 Jul 2026 11:00:00 GMT</pubDate>
      <category>Engineering</category>
    </item>
  </channel>
</rss>
XML),
    ]);

    $controller = new LookupControllerContractsRssController();
    $parsed     = $controller->parseFixtureRss(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <item>
      <title>Direct Parse</title>
      <link>https://fleetbase.ghost.io/direct-parse/</link>
      <description><![CDATA[<p>Direct description</p>]]></description>
      <pubDate>Sat, 18 Jul 2026 12:00:00 GMT</pubDate>
      <category>Product</category>
    </item>
  </channel>
</rss>
XML, 1);
    $response   = $controller->fleetbaseBlog(lookup_controller_request(['limit' => 2]));
    $payload    = $response->getData(true);

    expect($parsed)->toHaveCount(1)
        ->and($parsed[0]['title'])->toBe('Direct Parse')
        ->and($parsed[0]['link'])->toBe('https://www.example.test/blog/direct-parse')
        ->and($parsed[0]['description'])->toBe('<p>Direct description</p>')
        ->and($parsed[0]['published_at'])->toBe('2026-07-18T12:00:00+00:00')
        ->and($response->getStatusCode())->toBe(200)
        ->and($payload)->toHaveCount(2)
        ->and($payload[0]['title'])->toBe('First Update')
        ->and($payload[0]['link'])->toBe('https://www.example.test/blog/first-update')
        ->and($payload[1]['title'])->toBe('Second Update')
        ->and($payload[1]['link'])->toBe('https://www.example.test/blog/second-update/');
});

test('lookup blog fetch returns empty payload when upstream rss fails', function () {
    lookup_controller_boot();
    $logger = new LookupControllerContractsLogFake();
    app()->instance('log', $logger);
    Facade::clearResolvedInstance('log');
    Http::fake([
        '*' => Http::response('not found', 404),
    ]);

    $response = (new LookupControllerContractsRssController('https://feeds.example.test/fleetbase-missing.xml'))->fleetbaseBlog(lookup_controller_request(['limit' => 3]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([])
        ->and($response->headers->get('X-Cache-Status'))->toBe('HIT')
        ->and($logger->errors[0]['message'])->toBe('[Blog] Exception fetching RSS feed')
        ->and($logger->errors[0]['context']['error'])->toBeString()->not->toBe('')
        ->and($logger->errors[0]['context'])->toMatchArray([
            'url'    => 'https://feeds.example.test/fleetbase-missing.xml',
        ]);
});

test('lookup blog fetch returns empty payload when upstream rss throws', function () {
    lookup_controller_boot();
    $logger = new LookupControllerContractsLogFake();
    app()->instance('log', $logger);
    Facade::clearResolvedInstance('log');
    Http::fake(function () {
        throw new Illuminate\Http\Client\ConnectionException('rss connection refused');
    });

    $response = (new LookupControllerContractsRssController('https://feeds.example.test/fleetbase-throws.xml'))->fleetbaseBlog(lookup_controller_request(['limit' => 3]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([])
        ->and($response->headers->get('X-Cache-Status'))->toBe('HIT')
        ->and($logger->errors[0]['message'])->toBe('[Blog] Exception fetching RSS feed')
        ->and($logger->errors[0]['context'])->toMatchArray([
            'error' => 'rss connection refused',
            'url'   => 'https://feeds.example.test/fleetbase-throws.xml',
        ]);
});

test('lookup blog exposes default URL helpers and canonical link normalization', function () {
    lookup_controller_boot();

    $controller = new LookupControllerContractsBlogProbe();

    expect($controller->feedUrl())->toBe('https://blog.fleetbase.io/rss/')
        ->and($controller->blogUrl())->toBe('https://www.fleetbase.io/blog')
        ->and($controller->normalizedLink('https://fleetbase.ghost.io/release-notes/'))->toBe('https://www.fleetbase.io/blog/release-notes');
});

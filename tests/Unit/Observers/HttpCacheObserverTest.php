<?php

use Fleetbase\Observers\HttpCacheObserver;
use Illuminate\Support\Facades\Facade;

class HttpCacheObserverResponseCacheFake
{
    public int $clears = 0;

    public function clear(array $tags = []): void
    {
        $this->clears++;
    }
}

function http_cache_observer_subject(): array
{
    bind_test_container();
    $cache = new HttpCacheObserverResponseCacheFake();
    app()->instance('responsecache', $cache);
    Facade::clearResolvedInstance('responsecache');

    return [new HttpCacheObserver(), $cache];
}

afterEach(function () {
    Facade::clearResolvedInstances();
});

it('clears the response cache for model lifecycle writes', function (string $event) {
    [$observer, $cache] = http_cache_observer_subject();

    $observer->{$event}();

    expect($cache->clears)->toBe(1);
})->with(['created', 'updated', 'deleted']);

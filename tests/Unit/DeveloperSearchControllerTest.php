<?php

use Fleetbase\Http\Controllers\Internal\v1\DeveloperSearchController;
use Illuminate\Http\Request;

test('developer search returns empty results for blank query', function () {
    $controller = new DeveloperSearchController();
    $response   = $controller->search(Request::create('/developers/search', 'GET', ['query' => '   ']));
    $payload    = json_decode($response->getContent(), true);

    expect($payload)->toBe(['results' => []]);
});

<?php

namespace Fleetbase\Tests\FindFixtures\Models {
    use Illuminate\Database\Eloquent\Model;

    class FindWidget extends Model
    {
    }
}

namespace Fleetbase\Tests\FindFixtures\Http\Resources\v1 {
    use Illuminate\Http\Resources\Json\JsonResource;

    class FindWidget extends JsonResource
    {
    }
}

namespace Fleetbase\Tests\FindFixtures\Http\Resources\Internal\v1 {
    use Illuminate\Http\Resources\Json\JsonResource;

    class FindWidget extends JsonResource
    {
    }
}

namespace {
    use Fleetbase\Support\Find;
    use Fleetbase\Tests\FindFixtures\Models\FindWidget;
    use Illuminate\Container\Container;
    use Illuminate\Http\Request;
    use Illuminate\Routing\Route;

    function find_fixture_request(string $uri): Request
    {
        $uri     = ltrim($uri, '/');
        $request = Request::create('/' . $uri, 'GET');
        $route   = new Route('GET', $uri, []);
        $request->setRouteResolver(fn () => $route);
        Container::getInstance()->instance('request', $request);

        return $request;
    }

    test('find resolves internal and public http resources independently of resolution order', function () {
        bind_test_container();

        $model     = new FindWidget();
        $namespace = '\\Fleetbase\\Tests\\FindFixtures';

        find_fixture_request('int/v1/find-widgets');
        $internal = Find::httpResourceForModel($model, $namespace);

        find_fixture_request('v1/find-widgets');
        $public = Find::httpResourceForModel($model, $namespace);

        find_fixture_request('int/v1/find-widgets');
        $internalAgain = Find::httpResourceForModel($model, $namespace);

        expect($internal)->toBe('\\Fleetbase\\Tests\\FindFixtures\\Http\\Resources\\Internal\\v1\\FindWidget')
            ->and($public)->toBe('\\Fleetbase\\Tests\\FindFixtures\\Http\\Resources\\v1\\FindWidget')
            ->and($internalAgain)->toBe($internal);
    });
}

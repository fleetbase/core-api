<?php

namespace Fleetbase\Support {
    if (!function_exists('Fleetbase\Support\request')) {
        function request()
        {
            if (\function_exists('request')) {
                return \request();
            }

            return new class {
                public function route()
                {
                    return null;
                }
            };
        }
    }
}

namespace Fleetbase\Tests\Fixtures\Models {
    class InternalRequestResolutionRecord extends \Illuminate\Database\Eloquent\Model
    {
    }

    class ResourceResolutionRecord extends \Illuminate\Database\Eloquent\Model
    {
    }

    class InternalResourceResolutionRecord extends \Illuminate\Database\Eloquent\Model
    {
    }

    class FilterResolutionRecord extends \Illuminate\Database\Eloquent\Model
    {
    }

    /** A model whose controller may name its filter and request, as FleetbaseController does. */
    class ControllerChosenResolutionRecord extends \Illuminate\Database\Eloquent\Model
    {
        public ?string $filter  = null;
        public ?string $request = null;

        public function getFilter(): ?string
        {
            return $this->filter;
        }

        public function getRequest(): ?string
        {
            return $this->request;
        }
    }

    class AudienceFilterResolutionRecord extends \Illuminate\Database\Eloquent\Model
    {
    }

    class ExplicitResourceResolutionRecord extends \Illuminate\Database\Eloquent\Model
    {
        public function getResource(): string
        {
            return \Fleetbase\Tests\Fixtures\Http\Resources\ExplicitResource::class;
        }
    }
}

namespace Fleetbase\Tests\Fixtures\Http\Resources\v1 {
    class ResourceResolutionRecord
    {
    }
}

namespace Fleetbase\Tests\Fixtures\Http\Resources\Internal\v1 {
    class InternalResourceResolutionRecord
    {
    }
}

namespace Fleetbase\Tests\Fixtures\Http\Resources {
    class ExplicitResource
    {
    }
}

namespace Fleetbase\Tests\Fixtures\Http\Filter {
    class FilterResolutionRecordFilter
    {
    }

    /** The model's own filter, found by convention. */
    class ControllerChosenResolutionRecordFilter
    {
    }

    /** Another module's filter for the same model (Storefront's order filter over Fleet-Ops orders). */
    class OtherModuleFilter
    {
    }
}

namespace Fleetbase\Tests\Fixtures\Http\Filter\v1 {
    class AudienceFilterResolutionRecord
    {
    }
}

namespace Fleetbase\Tests\Fixtures\Http\Filter\Internal\v1 {
    class AudienceFilterResolutionRecord
    {
    }
}

namespace Fleetbase\Tests\Fixtures\Http\Requests {
    class OtherModuleRequest
    {
    }
}

namespace Fleetbase\Tests\Fixtures\Http\Requests\Internal\v1 {
    class InternalRequestResolutionRecord
    {
    }
}

namespace Fleetbase\Storefront\Models {
    class PackageResolutionRecord extends \Illuminate\Database\Eloquent\Model
    {
    }
}

namespace {
    use Fleetbase\Http\Requests\FleetbaseRequest;
    use Fleetbase\Support\Find;
    use Fleetbase\Tests\Fixtures\Http\Filter\ControllerChosenResolutionRecordFilter;
    use Fleetbase\Tests\Fixtures\Http\Filter\FilterResolutionRecordFilter;
    use Fleetbase\Tests\Fixtures\Http\Filter\Internal\v1\AudienceFilterResolutionRecord as AudienceFilterInternal;
    use Fleetbase\Tests\Fixtures\Http\Filter\OtherModuleFilter;
    use Fleetbase\Tests\Fixtures\Http\Filter\v1\AudienceFilterResolutionRecord as AudienceFilterPublic;
    use Fleetbase\Tests\Fixtures\Http\Requests\CreateAssetValidationRecordRequest;
    use Fleetbase\Tests\Fixtures\Http\Requests\Internal\v1\InternalRequestResolutionRecord as InternalRequestResolutionRecordRequest;
    use Fleetbase\Tests\Fixtures\Http\Requests\OtherModuleRequest;
    use Fleetbase\Tests\Fixtures\Http\Requests\UpdateAssetValidationRecordRequest;
    use Fleetbase\Tests\Fixtures\Http\Resources\ExplicitResource;
    use Fleetbase\Tests\Fixtures\Http\Resources\Internal\v1\InternalResourceResolutionRecord as InternalResourceResolutionRecordResource;
    use Fleetbase\Tests\Fixtures\Http\Resources\v1\ResourceResolutionRecord as ResourceResolutionRecordResource;
    use Fleetbase\Tests\Fixtures\Models\AssetValidationRecord;
    use Fleetbase\Tests\Fixtures\Models\AudienceFilterResolutionRecord;
    use Fleetbase\Tests\Fixtures\Models\ControllerChosenResolutionRecord;
    use Fleetbase\Tests\Fixtures\Models\ExplicitResourceResolutionRecord;
    use Fleetbase\Tests\Fixtures\Models\FilterResolutionRecord;
    use Fleetbase\Tests\Fixtures\Models\InternalRequestResolutionRecord;
    use Fleetbase\Tests\Fixtures\Models\InternalResourceResolutionRecord;
    use Fleetbase\Tests\Fixtures\Models\MissingRequestRecord;
    use Fleetbase\Tests\Fixtures\Models\ResourceResolutionRecord;
    use Illuminate\Http\Request;

    beforeEach(function () {
        unset($_SERVER['REQUEST_METHOD']);
        bind_test_container();
        app()->instance('request', find_test_request('/test', 'test'));
    });

    class FindTestRoute
    {
        public array $action = [];

        public function __construct(private string $uri, string $namespace = '')
        {
            $this->action = ['namespace' => $namespace];
        }

        public function uri(): string
        {
            return $this->uri;
        }
    }

    function find_test_request(string $path, string $routeUri, string $namespace = ''): Request
    {
        $request = Request::create($path);
        $request->setRouteResolver(fn () => new FindTestRoute($routeUri, $namespace));

        return $request;
    }

    test('resolves create request class for a model without duplicating namespace separators', function () {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $requestClass = Find::httpRequestForModel(new AssetValidationRecord(), '\Fleetbase\Tests\Fixtures');

        expect($requestClass)
            ->toBe('\\' . CreateAssetValidationRecordRequest::class)
            ->not->toContain('\\\\CreateAssetValidationRecordRequest');
    });

    test('resolves update request class for a model without duplicating namespace separators', function () {
        $_SERVER['REQUEST_METHOD'] = 'PATCH';

        $requestClass = Find::httpRequestForModel(new AssetValidationRecord(), '\Fleetbase\Tests\Fixtures');

        expect($requestClass)
            ->toBe('\\' . UpdateAssetValidationRecordRequest::class)
            ->not->toContain('\\\\UpdateAssetValidationRecordRequest');
    });

    test('falls back to fleetbase request when no model request exists', function () {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        expect(Find::httpRequestForModel(new MissingRequestRecord(), '\Fleetbase\Tests\Fixtures'))
            ->toBe('\\' . FleetbaseRequest::class);
    });

    test('resolves internal request classes for internal routes', function () {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        app()->instance('request', find_test_request('/int/v1/resources', 'int/v1/resources'));

        expect(Find::httpRequestForModel(new InternalRequestResolutionRecord(), '\Fleetbase\Tests\Fixtures'))
            ->toBe('\\' . InternalRequestResolutionRecordRequest::class);
    });

    test('resolves public internal explicit and fallback resource classes for models', function () {
        app()->instance('request', find_test_request('/v1/resources', 'v1/resources'));

        expect(Find::httpResourceForModel(new ResourceResolutionRecord(), '\Fleetbase\Tests\Fixtures'))
            ->toBe('\\' . ResourceResolutionRecordResource::class);

        app()->instance('request', find_test_request('/int/v1/resources', 'int/v1/resources'));

        expect(Find::httpResourceForModel(new InternalResourceResolutionRecord(), '\Fleetbase\Tests\Fixtures'))
            ->toBe('\\' . InternalResourceResolutionRecordResource::class);

        expect(Find::httpResourceForModel(new ExplicitResourceResolutionRecord(), '\Fleetbase\Tests\Fixtures'))
            ->toBe(ExplicitResource::class);

        expect(Find::httpResourceForModel(new MissingRequestRecord(), '\Fleetbase\Tests\Fixtures'))
            ->toBe('\\Fleetbase\\Http\\Resources\\FleetbaseResource');
    });

    test('resolves filter classes and package names from model namespaces', function () {
        expect(Find::httpFilterForModel(new FilterResolutionRecord()))
            ->toBe('\\' . FilterResolutionRecordFilter::class)
            ->and(Find::httpFilterForModel(new MissingRequestRecord()))
            ->toBeNull()
            ->and(Find::getModelPackage(new Fleetbase\Storefront\Models\PackageResolutionRecord()))
            ->toBe('Storefront');
    });

    test('a filter a controller sets on its model is used even after the model resolved another filter', function () {
        $fleetOps = new ControllerChosenResolutionRecord();

        $storefront         = new ControllerChosenResolutionRecord();
        $storefront->filter = OtherModuleFilter::class;

        // The first controller's answer used to be cached for the model class and served to the second.
        expect(Find::httpFilterForModel($fleetOps))->toBe('\\' . ControllerChosenResolutionRecordFilter::class)
            ->and(Find::httpFilterForModel($storefront))->toBe(OtherModuleFilter::class)
            ->and(Find::httpFilterForModel(new ControllerChosenResolutionRecord()))->toBe('\\' . ControllerChosenResolutionRecordFilter::class);
    });

    test('a convention filter is resolved for the request audience, not the first audience asked', function () {
        app()->instance('request', find_test_request('/v1/records', 'v1/records'));
        $public = Find::httpFilterForModel(new AudienceFilterResolutionRecord());

        app()->instance('request', find_test_request('/int/v1/records', 'int/v1/records'));
        $internal = Find::httpFilterForModel(new AudienceFilterResolutionRecord());

        expect($public)->toBe('\\' . AudienceFilterPublic::class)
            ->and($internal)->toBe('\\' . AudienceFilterInternal::class);
    });

    test('a request class a controller sets on its model is used even after the model resolved another', function () {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $named                     = new ControllerChosenResolutionRecord();
        $named->request            = OtherModuleRequest::class;

        expect(Find::httpRequestForModel(new ControllerChosenResolutionRecord(), '\Fleetbase\Tests\Fixtures'))->toBe('\\' . FleetbaseRequest::class)
            ->and(Find::httpRequestForModel($named, '\Fleetbase\Tests\Fixtures'))->toBe(OtherModuleRequest::class);
    });
}

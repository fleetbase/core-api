<?php

namespace Fleetbase\Support {
    if (!function_exists('Fleetbase\Support\request')) {
        function request()
        {
            return new class {
                public function route()
                {
                    return null;
                }
            };
        }
    }
}

namespace {
    use Fleetbase\Http\Requests\FleetbaseRequest;
    use Fleetbase\Support\Find;
    use Fleetbase\Tests\Fixtures\Http\Requests\CreateAssetValidationRecordRequest;
    use Fleetbase\Tests\Fixtures\Http\Requests\UpdateAssetValidationRecordRequest;
    use Fleetbase\Tests\Fixtures\Models\AssetValidationRecord;
    use Fleetbase\Tests\Fixtures\Models\MissingRequestRecord;

    beforeEach(function () {
        unset($_SERVER['REQUEST_METHOD']);
    });

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
}

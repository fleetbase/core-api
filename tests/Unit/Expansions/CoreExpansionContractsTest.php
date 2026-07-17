<?php

use Fleetbase\Expansions\Blade as BladeExpansion;
use Fleetbase\Expansions\Carbon as CarbonExpansion;
use Fleetbase\Expansions\Request as RequestExpansion;
use Fleetbase\Expansions\Response as ResponseExpansion;
use Fleetbase\Expansions\Str as StrExpansion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Carbon;

class CoreExpansionResponseFactoryFake
{
    public static function json(array $data, int $statusCode = 200, array $headers = [], int $options = 0): JsonResponse
    {
        return new JsonResponse($data, $statusCode, $headers, $options);
    }
}

afterEach(function () {
    HttpRequest::flushMacros();
    Carbon::setTestNow();
});

test('string carbon and blade expansions preserve formatting contracts', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-15 08:30:00'));

    $strExpansion    = new StrExpansion();
    $carbonExpansion = new CarbonExpansion();
    $bladeExpansion  = new BladeExpansion();

    $humanize   = $strExpansion->humanize();
    $domain     = $strExpansion->domain();
    $fromString = $carbonExpansion->fromString()->bindTo(null, Carbon::class);

    expect(\Fleetbase\Expansions\args(' created_at , "Y-m-d" '))->toBe(['created_at', '"Y-m-d"'])
        ->and($humanize('apiCredentialID'))->toBe('API credential i d')
        ->and($humanize('apiCredentialID', false))->toBe('API credential i d')
        ->and($humanize(null))->toBe('')
        ->and($domain('https://console.fleetbase.io/auth/login'))->toBe('fleetbase.io')
        ->and($fromString('first day of quarter')->toDateString())->toBe('2026-04-01')
        ->and($fromString('last day of quarter')->toDateString())->toBe('2026-06-30')
        ->and($fromString('start of decade')->toDateTimeString())->toBe('2020-01-01 00:00:00')
        ->and($fromString('end of decade')->toDateTimeString())->toBe('2029-12-31 23:59:59')
        ->and($fromString('2026-07-17 12:45:00')->toDateTimeString())->toBe('2026-07-17 12:45:00')
        ->and(($bladeExpansion->toTimeString())('2026-07-17 12:45:00'))->toBe('12:45:00')
        ->and(($bladeExpansion->toDateTimeString())('2026-07-17 12:45:00'))->toBe('2026-07-17 12:45:00')
        ->and(($bladeExpansion->formatFromCarbon())('created_at, "Y-m-d"'))->toBe('<?= \Illuminate\Support\Carbon::parse(created_at)->format("Y-m-d") ?>')
        ->and(($bladeExpansion->getFromCarbonParse())('created_at, timestamp'))->toBe('<?= \Illuminate\Support\Carbon::parse(created_at)->{timestamp} ?>');
});

test('request expansion helpers normalize parameters and global filter payloads', function () {
    $expansion = new RequestExpansion();
    HttpRequest::macro('or', $expansion->or());

    $request = HttpRequest::create('/int/v1/test', 'POST', [
        'ids'         => 'one,two,three',
        'tags'        => ['fragile', 'cold'],
        'count'       => '12',
        'uuid'        => '11111111-1111-4111-8111-111111111111',
        'query'       => 'Fleetbase%20API',
        'sort'        => '-created_at',
        'page'        => 2,
        'status'      => 'active',
        'custom'      => 'keep',
        'tenant_only' => 'drop',
    ]);

    expect($expansion->or()->call($request, ['missing', 'status'], 'fallback'))->toBe('active')
        ->and($expansion->or()->call($request, ['missing'], 'fallback'))->toBe('fallback')
        ->and($expansion->array()->call($request, 'ids'))->toBe(['one', 'two', 'three'])
        ->and($expansion->array()->call($request, 'tags'))->toBe(['fragile', 'cold'])
        ->and($expansion->isString()->call($request, 'status'))->toBeTrue()
        ->and($expansion->isUuid()->call($request, 'uuid'))->toBeTrue()
        ->and($expansion->isArray()->call($request, 'tags'))->toBeTrue()
        ->and($expansion->inArray()->call($request, 'tags', 'cold'))->toBeTrue()
        ->and($expansion->integer()->call($request, 'count'))->toBe(12)
        ->and($expansion->searchQuery()->call($request))->toBe('fleetbase api')
        ->and($expansion->getFilters()->call($request, ['tenant_only']))->toBe([
            'ids'    => 'one,two,three',
            'tags'   => ['fragile', 'cold'],
            'count'  => '12',
            'uuid'   => '11111111-1111-4111-8111-111111111111',
            'status' => 'active',
            'custom' => 'keep',
        ]);

    $expansion->removeParam()->call($request, 'status');

    expect($request->has('status'))->toBeFalse();
});

test('response expansion helpers keep internal and public error response shapes stable', function () {
    $factory           = new CoreExpansionResponseFactoryFake();
    $responseExpansion = new ResponseExpansion();

    $error    = $responseExpansion->error()->bindTo($factory, CoreExpansionResponseFactoryFake::class);
    $apiError = $responseExpansion->apiError()->bindTo($factory, CoreExpansionResponseFactoryFake::class);

    $internalResponse = $error('Unable to continue', 409, ['code' => 'conflict']);
    $publicResponse   = $apiError(['message' => 'Invalid request'], 422, ['request_id' => 'req_123']);

    expect($internalResponse->getStatusCode())->toBe(409)
        ->and($internalResponse->getData(true))->toBe([
            'errors' => ['Unable to continue'],
            'code'   => 'conflict',
        ])
        ->and($publicResponse->getStatusCode())->toBe(422)
        ->and($publicResponse->getData(true))->toBe([
            'error'      => ['message' => 'Invalid request'],
            'request_id' => 'req_123',
        ]);
});

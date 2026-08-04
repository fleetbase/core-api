<?php

use Fleetbase\Support\ResourceTransformerRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

class ResourceTransformerRegistryModel extends Model
{
    public function getResource(): string
    {
        return ResourceTransformerRegistryResource::class;
    }
}

class ResourceTransformerRegistryResource extends JsonResource
{
}

class ResourceTransformerRegistryTransformer
{
    public static string $target = ResourceTransformerRegistryResource::class;

    public static function output(Model $model, array $data = []): array
    {
        return array_merge($data, [
            'transformed' => true,
            'model'       => $model::class,
        ]);
    }
}

class ResourceTransformerRegistryOptionTransformer
{
    public static function output(Model $model, array $data = []): array
    {
        return array_merge($data, ['option_transformer' => true]);
    }
}

class ResourceTransformerRegistryNoOutputTransformer
{
    public static string $target = ResourceTransformerRegistryResource::class;
}

beforeEach(function () {
    ResourceTransformerRegistry::$transformers = [];
});

test('resource transformer registry normalizes class names and resolves by target', function () {
    ResourceTransformerRegistry::register(ResourceTransformerRegistryTransformer::class);

    expect(ResourceTransformerRegistry::$transformers)->toBe([
        [
            'definition' => '\\' . ResourceTransformerRegistryTransformer::class,
            'target'     => '\\' . ResourceTransformerRegistryResource::class,
        ],
    ])
        ->and(ResourceTransformerRegistry::resolveByTarget(ResourceTransformerRegistryResource::class))
        ->toBe('\\' . ResourceTransformerRegistryTransformer::class)
        ->and(ResourceTransformerRegistry::resolveByTarget('\\' . ResourceTransformerRegistryResource::class))
        ->toBe('\\' . ResourceTransformerRegistryTransformer::class)
        ->and(ResourceTransformerRegistry::resolveByTarget(JsonResource::class))->toBeNull();
});

test('resource transformer registry supports options batch registration and rejects invalid batch entries', function () {
    ResourceTransformerRegistry::register([
        ResourceTransformerRegistryTransformer::class,
        [ResourceTransformerRegistryOptionTransformer::class, ['target' => ResourceTransformerRegistryResource::class]],
    ]);

    expect(ResourceTransformerRegistry::$transformers)->toHaveCount(2)
        ->and(ResourceTransformerRegistry::$transformers[1])->toBe([
            'definition' => '\\' . ResourceTransformerRegistryOptionTransformer::class,
            'target'     => '\\' . ResourceTransformerRegistryResource::class,
        ]);

    ResourceTransformerRegistry::register([[ResourceTransformerRegistryTransformer::class]]);
})->throws(Exception::class, 'Attempted to register invalid notification.');

test('resource transformer registry transforms model data when a matching output transformer exists', function () {
    ResourceTransformerRegistry::register(ResourceTransformerRegistryTransformer::class);

    $model = new ResourceTransformerRegistryModel();

    expect(ResourceTransformerRegistry::transform($model, ['existing' => 'value']))->toBe([
        'existing'     => 'value',
        'transformed'  => true,
        'model'        => ResourceTransformerRegistryModel::class,
    ]);
});

test('resource transformer registry returns original data without a matching callable transformer', function () {
    $model = new ResourceTransformerRegistryModel();

    expect(ResourceTransformerRegistry::transform($model, ['untouched' => true]))->toBe(['untouched' => true]);

    ResourceTransformerRegistry::register(ResourceTransformerRegistryNoOutputTransformer::class);

    expect(ResourceTransformerRegistry::transform($model, ['still' => 'same']))->toBe(['still' => 'same']);
});

test('resource transformer registry fixes class names only for strings', function () {
    expect(ResourceTransformerRegistry::fixClassName(ResourceTransformerRegistryTransformer::class))
        ->toBe('\\' . ResourceTransformerRegistryTransformer::class)
        ->and(ResourceTransformerRegistry::fixClassName('\\' . ResourceTransformerRegistryTransformer::class))
        ->toBe('\\' . ResourceTransformerRegistryTransformer::class)
        ->and(ResourceTransformerRegistry::fixClassName(null))->toBeNull();
});

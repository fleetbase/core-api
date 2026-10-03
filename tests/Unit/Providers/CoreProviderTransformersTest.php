<?php

use Fleetbase\Http\Transformers\Transformer;
use Fleetbase\Providers\CoreServiceProvider;
use Fleetbase\Support\ResourceTransformerContext;
use Fleetbase\Support\ResourceTransformerRegistry;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CptDiscoveredTransformer extends Transformer
{
    protected static $target = '*';

    public function transform(array $data, JsonResource $resource, Request $request, ResourceTransformerContext $context): array
    {
        return $data + ['discovered' => true];
    }
}

class CptPropertyTransformer extends Transformer
{
    protected static $target = '*';

    public function transform(array $data, JsonResource $resource, Request $request, ResourceTransformerContext $context): array
    {
        return $data + ['property' => true];
    }
}

abstract class CptAbstractTransformer extends Transformer
{
    protected static $target = '*';
}

class CptNotTransformer
{
}

class CptProvider extends CoreServiceProvider
{
    /**
     * Config files need a full application (base_path()); the registry binding is what is under test.
     */
    protected function mergeConfigFrom($path, $key)
    {
    }
}

function cpt_provider(): CoreServiceProvider
{
    $container = bind_test_container(['app.env' => 'testing']);
    ResourceTransformerRegistry::reset();

    return new CptProvider($container);
}

function cpt_transformers_dir(string $suffix, string $namespace): string
{
    $base = sys_get_temp_dir() . '/fleetbase-core-provider-transformers-' . $suffix;
    $path = $base . '/src/Http/Transformers';

    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }

    file_put_contents($base . '/composer.json', json_encode([
        'autoload' => [
            'psr-4' => [
                $namespace . '\\' => 'src/',
            ],
        ],
    ]));

    foreach (['CptDiscoveredTransformer', 'CptAbstractTransformer', 'CptNotTransformer', 'CptMissingTransformer'] as $class) {
        file_put_contents($path . '/' . $class . '.php', "<?php\n");
    }
    file_put_contents($path . '/notes.txt', 'ignored');

    return $path;
}

test('core service provider binds the resource transformer registry as a singleton', function () {
    $provider  = cpt_provider();
    $container = Container::getInstance();

    $container->forgetInstance(ResourceTransformerRegistry::class);
    expect($container->bound(ResourceTransformerRegistry::class))->toBeFalse();

    $provider->register();

    $registry = $container->make(ResourceTransformerRegistry::class);

    expect($registry)->toBeInstanceOf(ResourceTransformerRegistry::class)
        ->and($container->make(ResourceTransformerRegistry::class))->toBe($registry)
        ->and(ResourceTransformerRegistry::instance())->toBe($registry);
});

test('core service provider registers transformers declared on the transformers property', function () {
    $provider = cpt_provider();
    $registry = ResourceTransformerRegistry::instance();

    $provider->registerTransformers();
    expect($registry->isEmpty())->toBeTrue();

    $provider->transformers = [
        CptPropertyTransformer::class,
        [CptDiscoveredTransformer::class, ['priority' => 2, 'contexts' => ['webhook']]],
    ];
    $provider->registerTransformers();

    expect($registry->ids())->toBe([CptPropertyTransformer::class, CptDiscoveredTransformer::class])
        ->and($registry->all()[1]['priority'])->toBe(2)
        ->and($registry->all()[1]['contexts'])->toBe(['webhook']);
});

test('core service provider registers transformers passed explicitly to registerTransformers', function () {
    $provider = cpt_provider();
    $registry = ResourceTransformerRegistry::instance();

    $provider->registerTransformers([
        [fn (array $data) => $data + ['closure' => true], ['target' => '*', 'id' => 'explicit']],
    ]);

    expect($registry->ids())->toBe(['explicit']);
});

test('core service provider discovers transformers from a package directory', function () {
    $path = cpt_transformers_dir('package', 'Fleetbase\\TransformerProviderTest');

    foreach (['CptDiscoveredTransformer', 'CptAbstractTransformer', 'CptNotTransformer'] as $class) {
        $alias = 'Fleetbase\\TransformerProviderTest\\Http\\Transformers\\' . $class;
        if (!class_exists($alias, false)) {
            class_alias($class, $alias);
        }
    }

    $provider = cpt_provider();
    $registry = ResourceTransformerRegistry::instance();

    $provider->registerTransformersFrom($path);
    $provider->registerTransformersFrom($path . '/missing-directory');
    $provider->registerTransformersFrom();

    expect($registry->ids())->toBe(['Fleetbase\\TransformerProviderTest\\Http\\Transformers\\CptDiscoveredTransformer'])
        ->and($registry->all()[0]['targets'])->toBe(['*']);
});

test('core service provider discovers transformers with an explicit namespace and multiple paths', function () {
    $pathOne = cpt_transformers_dir('explicit-one', 'Fleetbase\\TransformerProviderExplicitOne');
    $pathTwo = cpt_transformers_dir('explicit-two', 'Fleetbase\\TransformerProviderExplicitTwo');

    $provider = cpt_provider();
    $registry = ResourceTransformerRegistry::instance();

    // root-namespace classes: the same class is discovered from both paths and registered once
    $provider->registerTransformersFrom([$pathOne, $pathTwo], '');

    expect($registry->ids())->toBe([CptDiscoveredTransformer::class])
        ->and($registry->has(CptAbstractTransformer::class))->toBeFalse()
        ->and($registry->has(CptNotTransformer::class))->toBeFalse();
});

test('core service provider boot registers transformers after expansions and before middleware', function () {
    $source = file_get_contents((new ReflectionClass(CoreServiceProvider::class))->getFileName());

    $bootStart = strpos($source, 'public function boot()');
    $boot      = substr($source, $bootStart, strpos($source, 'public function registerExpansionsFrom') - $bootStart);

    expect(strpos($boot, '$this->registerExpansionsFrom();'))->toBeLessThan(strpos($boot, '$this->registerTransformers();'))
        ->and(strpos($boot, '$this->registerTransformers();'))->toBeLessThan(strpos($boot, '$this->registerTransformersFrom();'))
        ->and(strpos($boot, '$this->registerTransformersFrom();'))->toBeLessThan(strpos($boot, '$this->registerMiddleware();'));
});

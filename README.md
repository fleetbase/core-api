<p align="center">
    <p align="center">
        <img src="https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/fleetbase-logo-svg.svg" width="380" height="100" />
    </p>
    <p align="center">
        API Core and Framework for Fleetbase, an open-source supply chain operating system.
    </p>
</p>

<p align="center">
    <a href="https://codecov.io/gh/fleetbase/core-api"><img src="https://codecov.io/gh/fleetbase/core-api/branch/main/graph/badge.svg" alt="Coverage" /></a>
</p>

------
This package provides the base framework and API resources required by Fleetbase API.

> **Requires [PHP 8.0+](https://php.net/releases/)**

⚡️ Install the Fleetbase Core API [Composer](https://getcomposer.org):

```bash
composer require fleetbase/core-api
```

🧹 Keep a modern codebase with **PHP CS Fixer**:
```bash
composer lint
```

⚗️ Run static analysis using **PHPStan**:
```bash
composer test:types
```

✅ Run unit tests using **PEST**
```bash
composer test:unit
```

🚀 Run the entire test suite:
```bash
composer test
```

## Resource transformers

Any extension can decorate the serialized output of any API resource without touching the resource or its model. Register a transformer against an HTTP resource class, an Eloquent model class, an interface, or `'*'`, and `FleetbaseResource::resolve()` applies it to JSON responses, nested resources, collection items, webhook payloads and broadcast payloads.

```php
namespace Fleetbase\MyExtension\Http\Transformers;

use Fleetbase\Contracts\PreparesResourceTransformation;
use Fleetbase\Http\Transformers\Transformer;
use Fleetbase\Models\User;
use Fleetbase\Support\ResourceTransformerContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class UserBadgeTransformer extends Transformer implements PreparesResourceTransformation
{
    protected static $target   = User::class;      // resource class, model class, interface, array of them, or '*'
    protected static $priority = 10;               // lower runs first; higher runs later and can override
    protected static $contexts = ['http', 'webhook']; // null for every channel (http, webhook, broadcast)
    protected static $only     = 'internal';       // 'internal', 'public' or null for both

    // Optional: runs once per resolve with every model about to be serialized, so you can batch-load.
    public function prepare(Collection $models, Request $request, ResourceTransformerContext $context): void
    {
        $context->set('badges', Badge::whereIn('user_uuid', $models->pluck('uuid'))->get()->keyBy('user_uuid'));
    }

    public function transform(array $data, JsonResource $resource, Request $request, ResourceTransformerContext $context): array
    {
        return $data + ['badge' => $context->get('badges')[$resource->resource->uuid]->name ?? null];
    }
}
```

Register transformers from your extension's service provider, either declaratively or by discovery:

```php
class MyExtensionServiceProvider extends CoreServiceProvider
{
    public $transformers = [
        UserBadgeTransformer::class,
        [OrderTotalsTransformer::class, ['priority' => 5]],
    ];

    public function boot()
    {
        $this->registerTransformers();                                  // the $transformers property
        $this->registerTransformersFrom(__DIR__ . '/../Http/Transformers'); // every ResourceTransformer in the directory
    }
}
```

Closures work too, for quick one-off tweaks:

```php
ResourceTransformerRegistry::register(
    fn (array $data, JsonResource $resource, Request $request, ResourceTransformerContext $context) => $data + ['flag' => true],
    ['target' => \Fleetbase\FleetOps\Models\Order::class, 'contexts' => ['webhook'], 'only' => 'public']
);
```

Notes:

- Transformers may return `MissingValue` / `MergeValue` objects; they are filtered like `when()` / `merge()` output. Keys excluded with `without()` stay excluded.
- Re-registering a class replaces its options; `ResourceTransformerRegistry::forget()` and `reset()` remove registrations.
- The registry is a container singleton (`app(ResourceTransformerRegistry::class)`); registrations happen at boot and are shared by every request in an Octane worker.

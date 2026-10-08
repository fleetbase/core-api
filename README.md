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

## Realtime channel authentication

Authenticated realtime channels are on only when `SOCKETCLUSTER_AUTH_ENABLED=true` **and** `SOCKETCLUSTER_AUTH_KEY` is set (a shared secret of at least 32 characters, also given to the socket server). Until then nothing changes: no socket tokens are minted, the token routes answer 404, broadcasts use the websocket publisher as before, and the console's socket test publishes to the channel it asks for.

The switch is separate from the key so a deployment can provision the key ahead of time and keep every existing socket client working (mobile apps, the console, integrations) until they all fetch socket tokens. Roll out in this order:
1. Ship clients that request a socket token and fall back to connecting without one when the token route answers 404.
2. Set `SOCKETCLUSTER_AUTH_ENABLED=true` on the API, queue and scheduler, and run the socket server with `SOCKETCLUSTER_AUTH_MODE=log`.
3. Check the socket server's deny log, then switch it to `enforce`.

| Variable | Default | Meaning |
|---|---|---|
| `SOCKETCLUSTER_AUTH_ENABLED` | `false` | Turns authenticated realtime channels on. Has no effect without `SOCKETCLUSTER_AUTH_KEY`. |
| `SOCKETCLUSTER_AUTH_KEY` | unset | Signs socket tokens (HS256) and, through derived keys, the API to socket server requests. |
| `SOCKETCLUSTER_PUBLISH_URL` | `http://{SOCKETCLUSTER_HOST}:8001` | The socket server's internal listener; broadcasts are sent as one signed `POST {url}/publish`. |
| `SOCKETCLUSTER_TOKEN_TTL` | `900` | Lifetime in seconds of user, API, driver, customer and checkout tokens. |
| `SOCKETCLUSTER_ORIGIN` | unset | `Origin` header the websocket publisher sends on its handshake. Set it to an origin the socket server allows (e.g. the console URL) when `SOCKETCLUSTER_OPTIONS` restricts `origins`; without it the handshake is refused as `Invalid origin: *`. Not used by the signed HTTP publish. |

Clients fetch a token before connecting: `POST int/v1/socket/token` (console session), `POST v1/socket/token` (API credential or Sanctum user token). The socket server asks `POST int/v1/socket/authorize`, signed with its own derived key, whether a token may subscribe to a channel.

A channel is authorized by the resolver registered for its prefix (the part before the first `.`); unknown prefixes are denied. Extensions register theirs from their service provider:

```php
use Fleetbase\Support\SocketCluster\SocketChannelRegistry;
use Fleetbase\Support\SocketCluster\SocketPrincipal;

$registry = app(SocketChannelRegistry::class);

// `order.{uuid|public_id}`: users and API credentials of the order's company; drivers only when $narrow agrees.
$registry->registerModel('order', Order::class, fn (SocketPrincipal $p, Order $order) => $p->kind === 'driver' && $p->owns((string) $order->driver_assigned_uuid));

// Anything else: fn (SocketPrincipal $p, string $id, string $channel): bool
$registry->register('fleet', fn (SocketPrincipal $p, string $id, string $channel) => /* ... */ false);

// Claim a Sanctum-authenticated user as a more specific principal on `POST v1/socket/token`.
$registry->registerPrincipalResolver(fn (Request $request, $user) => /* ?SocketPrincipal */ null);
```

`app(ChannelAuthorizer::class)->authorize($principal, $channel)` gives the same decision anywhere in PHP.

# Realtime channel authentication

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

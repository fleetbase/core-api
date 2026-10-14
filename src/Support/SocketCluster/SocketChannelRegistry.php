<?php

namespace Fleetbase\Support\SocketCluster;

use Fleetbase\Contracts\SocketChannelResolver;
use Illuminate\Http\Request;

/**
 * Who may subscribe to which realtime channels, by channel prefix.
 *
 * Bound as a singleton. Core registers its own prefixes; extensions register theirs from
 * their service providers, for example:
 *
 *     app(SocketChannelRegistry::class)->registerModel('order', Order::class, $narrow);
 */
class SocketChannelRegistry
{
    /**
     * @var array<string, callable|SocketChannelResolver>
     */
    protected array $resolvers = [];

    /**
     * @var array<int, callable>
     */
    protected array $principalResolvers = [];

    /**
     * Register the resolver for a prefix: fn (SocketPrincipal $p, string $id, string $channel): bool.
     *
     * A later registration for the same prefix replaces the earlier one.
     */
    public function register(string $prefix, callable|SocketChannelResolver $resolver): void
    {
        $this->resolvers[$prefix] = $resolver;
    }

    /**
     * Register a prefix whose id is a model's uuid or public_id.
     *
     * User and API principals are allowed when the model belongs to their company. Driver and
     * customer principals are allowed only when $narrow (fn (SocketPrincipal $p, $model): bool)
     * says so; without it they are denied. Every other kind is denied.
     */
    public function registerModel(string $prefix, string $modelClass, ?callable $narrow = null): void
    {
        $this->register($prefix, new ModelChannelResolver($modelClass, $narrow));
    }

    /**
     * Register a resolver that may claim a Sanctum-authenticated user as a more specific principal:
     * fn (Request $request, $user): ?SocketPrincipal. The first non-null answer wins.
     */
    public function registerPrincipalResolver(callable $resolver): void
    {
        $this->principalResolvers[] = $resolver;
    }

    public function resolve(string $prefix): callable|SocketChannelResolver|null
    {
        return $this->resolvers[$prefix] ?? null;
    }

    /**
     * The principal a registered resolver claims for the user, or null when none does.
     */
    public function resolvePrincipal(Request $request, $user): ?SocketPrincipal
    {
        foreach ($this->principalResolvers as $resolver) {
            $principal = $resolver($request, $user);

            if ($principal instanceof SocketPrincipal) {
                return $principal;
            }
        }

        return null;
    }
}

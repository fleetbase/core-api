<?php

namespace Fleetbase\Contracts;

use Fleetbase\Support\SocketCluster\SocketPrincipal;

/**
 * Decides whether a socket principal may subscribe to channels under one prefix.
 *
 * Registered against a prefix on the SocketChannelRegistry. For a channel such as
 * `order.order_abc123` the resolver registered for `order` receives `order_abc123`
 * as the id and the full channel name.
 */
interface SocketChannelResolver
{
    public function authorize(SocketPrincipal $principal, string $id, string $channel): bool;
}

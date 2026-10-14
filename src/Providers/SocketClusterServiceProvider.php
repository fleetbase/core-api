<?php

namespace Fleetbase\Providers;

use Fleetbase\Support\SocketCluster\ChannelAuthorizer;
use Fleetbase\Support\SocketCluster\CoreChannelResolvers;
use Fleetbase\Support\SocketCluster\SocketChannelRegistry;
use Fleetbase\Support\SocketCluster\SocketClusterBroadcaster;
use Fleetbase\Support\SocketCluster\SocketClusterService;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class SocketClusterServiceProvider extends ServiceProvider
{
    /**
     * Register the realtime channel registry and authorizer.
     *
     * Singletons: extensions add their channel resolvers to the registry from their own
     * service providers, and core's resolvers are registered when it is first built.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(SocketChannelRegistry::class, function () {
            $registry = new SocketChannelRegistry();
            CoreChannelResolvers::register($registry);

            return $registry;
        });

        $this->app->singleton(ChannelAuthorizer::class, function ($app) {
            return new ChannelAuthorizer($app->make(SocketChannelRegistry::class));
        });
    }

    /**
     * Register new BroadcastManager in boot.
     *
     * @return void
     */
    public function boot()
    {
        Broadcast::extend('socketcluster', function ($broadcasting, $config) {
            return new SocketClusterBroadcaster(new SocketClusterService());
        });
    }
}

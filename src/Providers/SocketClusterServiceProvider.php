<?php

namespace Fleetbase\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Broadcast;
use Fleetbase\Support\SocketCluster\SocketClusterService;
use Fleetbase\Support\SocketCluster\SocketClusterBroadcaster;

class SocketClusterServiceProvider extends ServiceProvider
{
    /**
     * Register new BroadcastManager in boot
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

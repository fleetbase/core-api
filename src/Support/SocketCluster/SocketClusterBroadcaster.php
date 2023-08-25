<?php

namespace Fleetbase\Support\SocketCluster;

use Illuminate\Contracts\Broadcasting\Broadcaster;

class SocketClusterBroadcaster implements Broadcaster
{
    /**
     * @var \Fleetbase\Suppoer\SocketCluster\SocketClusterService
     */
    protected SocketClusterService $socketcluster;

    /**
     * Construct
     *
     * @param \Fleetbase\Support\SocketCluster\SocketClusterService $socketcluster
     *
     * @param void
     */
    public function __construct(SocketClusterService $socketcluster)
    {
        $this->socketcluster = $socketcluster;
    }

    /**
     * Authenticate the incoming request for a given channel.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    public function auth($request)
    {
    }

    /**
     * Return the valid authentication response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $result
     * @return mixed
     */
    public function validAuthenticationResponse($request, $result)
    {
    }

    /**
     * Broadcast
     *
     * @param array  $channels
     * @param string $event
     * @param array  $payload
     *
     * @return void
     */
    public function broadcast(array $channels, $event, array $payload = [])
    {
        foreach ($channels as $channel) {
            $this->socketcluster->send($channel, $payload);
        }
    }
}

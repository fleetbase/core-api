<?php

namespace Fleetbase\Support;

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Fleetbase\Support\SocketClusterService;

class SocketClusterBroadcaster implements Broadcaster
{
    /**
     * @var \Fleetbase\Services\SocketClusterService
     */
    protected $socketcluster;

    /**
     * Construct
     *
     * @param \Fleetbase\Services\SocketClusterService $socketcluster
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

<?php

namespace Fleetbase\Support\SocketCluster;

/**
 * Class SocketClusterHandshake
 *
 * Represents a handshake message to be sent to a SocketCluster server.
 *
 * @package Fleetbase\Support\SocketCluster
 */
class SocketClusterHandshake extends SocketClusterMessage
{
    /**
     * The opcode for the message.
     *
     * @var string
     */
    protected $opcode = 'text';

    /**
     * Create a new SocketCluster Handshake Message instance.
     *
     * @param int $cid The socketcluster cid to use.
     */
    public function __construct(int $cid)
    {
        parent::__construct('#handshake', [], $cid);
    }
}

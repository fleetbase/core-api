<?php

namespace Fleetbase\Support;

use Illuminate\Broadcasting\Channel;
use WebSocket\Client;
use Exception;
use Closure;

class SocketClusterService
{
    /**
     * @var \WebSocket\Client
     */
    protected $websocket;

    /**
     * @var string
     */
    protected $error;

    /**
     * @var string
     */
    protected $uri;

    /**
     * Construct
     */
    public function __construct($options = null)
    {
        $this->uri = $uri = $this->parseOptions(is_array($options) ? $options : config('broadcasting.connections.socketcluster.options'));
        $this->websocket = new Client($uri);
    }

    /**
     * Static alias to send
     *
     * @param string       $channel
     * @param mixed        $data
     * @param Closure|null $callback
     *
     * @return boolean
     */
    public static function publish($channel, $data, $event = '#publish', Closure $callback = null)
    {
        return (new static())->send($channel, $data, $event, $callback);
    }

    /**
     * Publish Channel
     *
     * @param string       $channel
     * @param mixed        $data
     * @param Closure|null $callback
     *
     * @return boolean
     */
    public function send($channel, $data, $event = '#publish', Closure $callback = null)
    {
        if ($channel instanceof Channel) {
            $channel = data_get($channel, 'name');
        }

        $data = [
            'channel' => $channel,
            'data' => $data,
        ];

        return $this->emit($event, $data, $callback);
    }

    /**
     * Emit Event
     *
     * @param string $event
     * @param array  $data
     *
     * @return boolean
     */
    protected function emit($event, array $data)
    {
        try {
            $eventData = [
                'event' => $event,
                'data' => $data,
            ];

            $sendData = (string) @json_encode($eventData);

            $this->websocket->send($sendData);

            $this->error = null;

            return true;
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    /**
     * Get Error
     *
     * @return string
     */
    public function error()
    {
        return $this->error;
    }

    /**
     * Parse Options
     *
     * @param string|array $options
     *
     * @return void
     */
    protected static function parseOptions($options)
    {
        $default = [
            'scheme' => '',
            'host' => '',
            'port' => '',
            'path' => '',
            'query' => [],
            'fragment' => '',
        ];

        $optArr = !is_array($options) ? parse_url($options) : $options;
        $optArr = array_merge($default, $optArr);

        if (isset($optArr['secure'])) {
            $scheme = ((bool) $optArr['secure']) ? 'wss' : 'ws';
        } else {
            $scheme = in_array($optArr['scheme'], ['wss', 'https']) ? 'wss' : 'ws';
        }

        $query = $optArr['query'];
        if (!is_array($query)) {
            parse_str($optArr['query'], $query);
        }

        $host = trim($optArr['host'], '/');
        $port = !empty($optArr['port']) ? ':' . $optArr['port'] : '';
        $path = trim($optArr['path'], '/');
        $path = !empty($path) ? $path . '/' : '';
        $query = count($query) ? '?' . http_build_query($query) : '';

        return sprintf('%s://%s%s/%s%s', $scheme, $host, $port, $path, $query);
    }
}

<?php

namespace Fleetbase\Support\SocketCluster;

use Illuminate\Support\Facades\Http;
use WebSocket\Client;

/**
 * Class SocketClusterService.
 *
 * Service class for managing SocketCluster connections and messages.
 *
 * With SOCKETCLUSTER_AUTH_KEY configured, messages are published with one signed HTTP
 * request to the socket server's internal publish endpoint. Without it they are sent over
 * the websocket as before.
 */
class SocketClusterService
{
    /**
     * WebSocket client instance.
     */
    protected Client $client;

    /**
     * The URI for the WebSocket connection.
     */
    protected string $uri;

    /**
     * Connection options.
     */
    protected array $options = [];

    /**
     * Indicates whether the message was sent.
     */
    protected ?bool $sent = null;

    /**
     * The response received, if any.
     */
    protected ?string $response = null;

    /**
     * Error message, if any.
     */
    protected ?string $error = null;

    /**
     * Indicates whether the handshake was sent.
     */
    protected ?bool $handshakeSent = null;

    /**
     * The handshake response received, if any.
     */
    protected ?string $handshakeResponse = null;

    /**
     * Handshake error message, if any.
     */
    protected ?string $handshakeError = null;

    /**
     * Socket protocol error statuses.
     */
    public array $socketProtocolErrorStatuses = [
        1001 => 'Socket was disconnected',
        1002 => 'A WebSocket protocol error was encountered',
        1003 => 'Server terminated socket because it received invalid data',
        1005 => 'Socket closed without status code',
        1006 => 'Socket hung up',
        1007 => 'Message format was incorrect',
        1008 => 'Encountered a policy violation',
        1009 => 'Message was too big to process',
        1010 => 'Client ended the connection because the server did not comply with extension requirements',
        1011 => 'Server encountered an unexpected fatal condition',
        4000 => 'Server ping timed out',
        4001 => 'Client pong timed out',
        4002 => 'Server failed to sign auth token',
        4003 => 'Failed to complete handshake',
        4004 => 'Client failed to save auth token',
        4005 => 'Did not receive #handshake from client before timeout',
        4006 => 'Failed to bind socket to message broker',
        4007 => 'Client connection establishment timed out',
        4008 => 'Server rejected handshake from client',
        4009 => 'Server received a message before the client handshake',
    ];

    /**
     * Create a new SocketClusterService instance.
     *
     * @param array|string $options connection options or URI
     */
    public function __construct($options = [])
    {
        $this->options = $options = $this->getOptions($options);
        $this->uri     = $uri = $this->parseOptions($options);
        $this->client  = new Client($uri, $options);
    }

    /**
     * Get the WebSocket client instance.
     *
     * @return Client
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Get the WebSocket server URI.
     *
     * @return Client
     */
    public function getUri()
    {
        return $this->uri;
    }

    /**
     * Create a new SocketClusterService instance.
     *
     * @param array|string $options connection options or URI
     */
    public static function instance($options = []): SocketClusterService
    {
        return new static($options);
    }

    /**
     * Publishes a message to the specified channel.
     *
     * @param string $channel the channel to publish the message to
     * @param array  $data    the data to send in the message (optional)
     * @param array  $options additional options for the message (optional)
     *
     * @return bool returns true if the message was sent successfully, false otherwise
     */
    public static function publish($channel, array $data = [], $options = []): bool
    {
        return static::instance($options)->send($channel, $data);
    }

    /**
     * Sends a message to the specified channel.
     *
     * @param string $channel the channel to send the message to
     * @param array  $data    the data to send in the message (optional)
     *
     * @return bool returns true if the message was sent successfully, false otherwise
     *
     * @throws \WebSocket\ConnectionException if there is a connection error
     * @throws \WebSocket\TimeoutException    if the operation times out
     * @throws \Throwable                     if any other error occurs
     */
    public function send($channel, array $data = []): bool
    {
        if (static::publishesOverHttp()) {
            return $this->sendMany([$channel], $data);
        }

        $cid        = rand();
        $message    = new SocketClusterMessage($channel, $data, $cid);
        $this->sent = false;

        try {
            $this->handshake($cid);
            $this->client->send($message);
            $this->response = $this->client->receive();
            $this->client->close();
            $this->sent = true;
        } catch (\WebSocket\TimeoutException $e) {
            $this->error = $e->getMessage();
        } catch (\WebSocket\ConnectionException $e) {
            $this->error = $e->getMessage();
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }

        return $this->sent;
    }

    /**
     * Sends one message to several channels.
     *
     * Channels with an empty suffix (for example "company." from a session read in a queue
     * worker) are dropped. Over HTTP all channels go in a single request; over the websocket
     * each channel is sent in turn. Returns true when every send succeeded.
     */
    public function sendMany(array $channels, array $data = []): bool
    {
        $channels = static::filterChannels($channels);

        if ($channels === []) {
            return true;
        }

        if (static::publishesOverHttp()) {
            return $this->publishOverHttp($channels, $data);
        }

        $sent = true;

        foreach ($channels as $channel) {
            $sent = $this->send($channel, $data) && $sent;
        }

        return $sent;
    }

    /**
     * Normalizes channels to unique names, dropping those ending in "." and any the socket
     * server would reject (empty, longer than 255 characters or containing whitespace).
     */
    public static function filterChannels(array $channels): array
    {
        $names = array_map(fn ($channel) => trim((string) $channel), $channels);

        return array_values(array_unique(array_filter($names, fn ($name) => ChannelAuthorizer::isValidChannel($name) && !str_ends_with($name, '.'))));
    }

    /**
     * Whether messages are published over the signed HTTP endpoint rather than the websocket.
     */
    public static function publishesOverHttp(): bool
    {
        return SocketToken::enabled();
    }

    /**
     * The socket server's internal publish endpoint.
     */
    public static function publishUrl(): string
    {
        $base = config('broadcasting.connections.socketcluster.publish_url');

        if (!is_string($base) || $base === '') {
            $base = 'http://' . config('broadcasting.connections.socketcluster.options.host', 'socket') . ':8001';
        }

        return rtrim($base, '/') . '/publish';
    }

    /**
     * Publishes to all channels with one request signed by the derived publish key.
     */
    protected function publishOverHttp(array $channels, array $data): bool
    {
        $this->sent  = false;
        $this->error = null;

        try {
            $body     = json_encode(['channels' => $channels, 'data' => $data === [] ? new \stdClass() : $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $response = Http::withHeaders(SocketSignature::headers(SocketSignature::PUBLISH, $body))
                ->withBody($body, 'application/json')
                ->acceptJson()
                ->connectTimeout(2)
                ->timeout(3)
                ->post(static::publishUrl());

            $this->response = $response->body();
            $this->sent     = $response->successful();

            if (!$this->sent) {
                $this->error = 'Socket publish failed with HTTP status ' . $response->status() . '.';
            }
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }

        return $this->sent;
    }

    /**
     * Sends a handshake message to the SocketCluster server.
     *
     * @param int $cid the connection ID for the handshake
     *
     * @return bool returns true if the handshake was sent successfully, false otherwise
     *
     * @throws \WebSocket\ConnectionException if there is a connection error
     * @throws \WebSocket\TimeoutException    if the operation times out
     * @throws \Throwable                     if any other error occurs
     */
    public function handshake($cid)
    {
        $handshake           = new SocketClusterHandshake($cid);
        $this->handshakeSent = false;

        try {
            $this->client->send($handshake);
            $this->handshakeResponse = $this->client->receive();
            $this->handshakeSent     = true;
        } catch (\WebSocket\TimeoutException $e) {
            $this->handshakeError = $e->getMessage();
        } catch (\WebSocket\ConnectionException $e) {
            $this->handshakeError = $e->getMessage();
        } catch (\Throwable $e) {
            $this->handshakeError = $e->getMessage();
        }

        return $this->handshakeSent;
    }

    /**
     * Get the error message, if any.
     */
    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * Get the response received, if any.
     */
    public function response(): ?string
    {
        return $this->response;
    }

    /**
     * Get a specific option value.
     *
     * @param string $key     the option key
     * @param mixed  $default default value if the option is not set
     */
    public function getOption(string $key, $default = null)
    {
        $value = data_get($this->options, $key, $default);

        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }

    /**
     * Get the connection options.
     *
     * @param array|string $options connection options or URI
     */
    public function getOptions($options): array
    {
        $defaultConnectionOptions = config('broadcasting.connections.socketcluster.options', []);

        if (is_string($options)) {
            $options = parse_url($options);
        }

        return array_merge($defaultConnectionOptions, $options);
    }

    /**
     * Create a socket server URI from connection options provided.
     *
     * @param string|array $options
     *
     * @return void
     */
    protected function parseOptions($options): string
    {
        $default = [
            'scheme'   => '',
            'host'     => '',
            'port'     => '',
            'path'     => '',
            'query'    => [],
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

        $host  = trim($optArr['host'], '/');
        $port  = !empty($optArr['port']) ? ':' . $optArr['port'] : '';
        $path  = trim($optArr['path'], '/');
        $path  = !empty($path) ? $path . '/' : '';
        $query = count($query) ? '?' . http_build_query($query) : '';

        return sprintf('%s://%s%s/%s%s', $scheme, $host, $port, $path, $query);
    }
}

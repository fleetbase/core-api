<?php

namespace Fleetbase\Services;

use Illuminate\Support\Facades\Log;

class SmppGatewayClient
{
    protected const COMMAND_NAMES = [
        0x00000001 => 'bind_receiver',
        0x00000002 => 'bind_transmitter',
        0x00000004 => 'submit_sm',
        0x00000006 => 'unbind',
        0x00000009 => 'bind_transceiver',
        0x80000001 => 'bind_receiver_resp',
        0x80000002 => 'bind_transmitter_resp',
        0x80000004 => 'submit_sm_resp',
        0x80000006 => 'unbind_resp',
        0x80000009 => 'bind_transceiver_resp',
    ];

    protected array $config;

    protected $socket;

    protected int $sequence = 1;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function connect(): void
    {
        $scheme  = data_get($this->config, 'tls') ? 'tls' : 'tcp';
        $host    = data_get($this->config, 'host');
        $port    = (int) data_get($this->config, 'port', 2775);
        $timeout = (float) data_get($this->config, 'timeout', 10);

        $this->socket = @stream_socket_client("{$scheme}://{$host}:{$port}", $errno, $errstr, $timeout);
        if (!$this->socket) {
            throw new \RuntimeException("Unable to connect to SMPP gateway {$scheme}://{$host}:{$port}: {$errstr}", $errno);
        }

        // Successful connection requires a live SMPP gateway; unit tests cover protocol behavior with injected sockets.
        // @codeCoverageIgnoreStart
        stream_set_timeout($this->socket, (int) $timeout);
        $this->bind();
        // @codeCoverageIgnoreEnd
    }

    public function submit(string $from, string $to, string $text, array $options = []): string
    {
        $shortMessage = (string) data_get($options, 'short_message', $text);
        $body         = $this->cstring((string) data_get($options, 'service_type', data_get($this->config, 'service_type', '')))
            . chr($this->integerOption($options, 'source_addr_ton', 5))
            . chr($this->integerOption($options, 'source_addr_npi', 0))
            . $this->cstring($from)
            . chr($this->integerOption($options, 'dest_addr_ton', 1))
            . chr($this->integerOption($options, 'dest_addr_npi', 1))
            . $this->cstring(ltrim($to, '+'))
            . chr(0)
            . chr(0)
            . chr(0)
            . $this->cstring('')
            . $this->cstring('')
            . chr($this->integerOption($options, 'registered_delivery', 1))
            . chr(0)
            . chr(0)
            . chr($this->integerOption($options, 'data_coding', 0))
            . chr($this->integerOption($options, 'sm_default_msg_id', 0))
            . chr(strlen($shortMessage))
            . $shortMessage;

        $this->logPduContext('submit_sm', [
            'to'                  => ltrim($to, '+'),
            'from'                => $from,
            'source_addr_ton'     => $this->integerOption($options, 'source_addr_ton', 5),
            'source_addr_npi'     => $this->integerOption($options, 'source_addr_npi', 0),
            'dest_addr_ton'       => $this->integerOption($options, 'dest_addr_ton', 1),
            'dest_addr_npi'       => $this->integerOption($options, 'dest_addr_npi', 1),
            'registered_delivery' => $this->integerOption($options, 'registered_delivery', 1),
            'data_coding'         => $this->integerOption($options, 'data_coding', 0),
            'message_length'      => strlen($shortMessage),
        ]);

        $response = $this->sendPdu(0x00000004, $body);

        return $this->readCString($response['body']) ?: (string) $response['sequence'];
    }

    public function close(): void
    {
        if (!$this->socket) {
            return;
        }

        try {
            $this->sendPdu(0x00000006, '');
        } catch (\Throwable $e) {
            // Ignore disconnect errors while closing the transport.
        }

        fclose($this->socket);
        $this->socket = null;
    }

    protected function bind(): void
    {
        $bindMode = (string) data_get($this->config, 'bind_type', 'transceiver');
        $command  = match ($bindMode) {
            'transmitter' => 0x00000002,
            'receiver'    => 0x00000001,
            default       => 0x00000009,
        };

        $body = $this->cstring((string) data_get($this->config, 'system_id'))
            . $this->cstring((string) data_get($this->config, 'password', ''))
            . $this->cstring((string) data_get($this->config, 'system_type', ''))
            . chr((int) data_get($this->config, 'interface_version', 0x34))
            . chr((int) data_get($this->config, 'addr_ton', 0))
            . chr((int) data_get($this->config, 'addr_npi', 0))
            . $this->cstring((string) data_get($this->config, 'address_range', ''));

        $this->logPduContext($this->commandName($command), [
            'bind_type'     => $bindMode,
            'system_id'     => data_get($this->config, 'system_id'),
            'system_type'   => data_get($this->config, 'system_type'),
            'addr_ton'      => (int) data_get($this->config, 'addr_ton', 0),
            'addr_npi'      => (int) data_get($this->config, 'addr_npi', 0),
            'address_range' => data_get($this->config, 'address_range', ''),
        ]);

        $this->sendPdu($command, $body);
    }

    protected function sendPdu(int $commandId, string $body): array
    {
        $sequence = $this->sequence++;
        $length   = 16 + strlen($body);
        $packet   = pack('NNNN', $length, $commandId, 0, $sequence) . $body;

        fwrite($this->socket, $packet);

        $header = fread($this->socket, 16);
        if (strlen($header) !== 16) {
            throw new \RuntimeException(sprintf('%s failed: invalid SMPP response header, expected 16 bytes, read %d bytes', $this->commandName($commandId), strlen($header)));
        }

        $parts        = unpack('Nlength/Ncommand/Nstatus/Nsequence', $header);
        $bodyLength   = max(0, $parts['length'] - 16);
        $responseBody = $bodyLength > 0 ? fread($this->socket, $bodyLength) : '';
        if (strlen($responseBody) !== $bodyLength) {
            throw new \RuntimeException(sprintf('%s failed: invalid SMPP response body, expected %d bytes, read %d bytes from %s', $this->commandName($commandId), $bodyLength, strlen($responseBody), $this->commandName((int) $parts['command'])));
        }

        if ((int) $parts['status'] !== 0) {
            throw new \RuntimeException(sprintf('%s failed with SMPP status %d (0x%08X) from %s; sequence=%d, response_length=%d', $this->commandName($commandId), (int) $parts['status'], (int) $parts['status'], $this->commandName((int) $parts['command']), (int) $parts['sequence'], (int) $parts['length']), (int) $parts['status']);
        }

        return [
            'command'  => (int) $parts['command'],
            'sequence' => (int) $parts['sequence'],
            'body'     => $responseBody,
        ];
    }

    protected function cstring(string $value): string
    {
        return $value . "\0";
    }

    protected function integerOption(array $options, string $key, int $default): int
    {
        return (int) data_get($options, $key, data_get($this->config, $key, $default));
    }

    protected function commandName(int $commandId): string
    {
        return static::COMMAND_NAMES[$commandId] ?? sprintf('command_0x%08X', $commandId);
    }

    protected function logPduContext(string $command, array $context = []): void
    {
        Log::info('Sending SMPP ' . $command, array_merge([
            'host' => data_get($this->config, 'host'),
            'port' => (int) data_get($this->config, 'port', 2775),
            'tls'  => (bool) data_get($this->config, 'tls', false),
        ], $context));
    }

    protected function readCString(string $value): string
    {
        $position = strpos($value, "\0");

        return $position === false ? $value : substr($value, 0, $position);
    }
}

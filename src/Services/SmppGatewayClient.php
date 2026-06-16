<?php

namespace Fleetbase\Services;

class SmppGatewayClient
{
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
            throw new \RuntimeException("Unable to connect to SMPP gateway: {$errstr}", $errno);
        }

        stream_set_timeout($this->socket, (int) $timeout);
        $this->bind();
    }

    public function submit(string $from, string $to, string $text, array $options = []): string
    {
        $body = $this->cstring((string) data_get($options, 'service_type', ''))
            . chr((int) data_get($options, 'source_addr_ton', data_get($this->config, 'source_addr_ton', 5)))
            . chr((int) data_get($options, 'source_addr_npi', data_get($this->config, 'source_addr_npi', 0)))
            . $this->cstring($from)
            . chr((int) data_get($options, 'dest_addr_ton', data_get($this->config, 'dest_addr_ton', 1)))
            . chr((int) data_get($options, 'dest_addr_npi', data_get($this->config, 'dest_addr_npi', 1)))
            . $this->cstring(ltrim($to, '+'))
            . chr(0)
            . chr(0)
            . chr(0)
            . $this->cstring('')
            . $this->cstring('')
            . chr(1)
            . chr(0)
            . chr(0)
            . chr((int) data_get($options, 'data_coding', data_get($this->config, 'data_coding', 0)))
            . chr(0)
            . chr(strlen($text))
            . $text;

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
            throw new \RuntimeException('Invalid SMPP response header');
        }

        $parts        = unpack('Nlength/Ncommand/Nstatus/Nsequence', $header);
        $bodyLength   = max(0, $parts['length'] - 16);
        $responseBody = $bodyLength > 0 ? fread($this->socket, $bodyLength) : '';

        if ((int) $parts['status'] !== 0) {
            throw new \RuntimeException('SMPP command failed with status: ' . $parts['status'], (int) $parts['status']);
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

    protected function readCString(string $value): string
    {
        $position = strpos($value, "\0");

        return $position === false ? $value : substr($value, 0, $position);
    }
}

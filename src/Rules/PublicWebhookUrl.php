<?php

namespace Fleetbase\Rules;

use Illuminate\Contracts\Validation\Rule;

class PublicWebhookUrl implements Rule
{
    protected string $message = 'The :attribute must be a public HTTP or HTTPS URL.';

    public function passes($attribute, $value): bool
    {
        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts  = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        if ($this->isBlockedHostname($host)) {
            return false;
        }

        $addresses = $this->resolveHost($host);

        foreach ($addresses as $address) {
            if ($this->isBlockedIp($address)) {
                return false;
            }
        }

        return true;
    }

    public function message(): string
    {
        return $this->message;
    }

    protected function isBlockedHostname(string $host): bool
    {
        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || $host === 'metadata.google.internal'
            || $host === 'metadata'
            || str_ends_with($host, '.internal');
    }

    protected function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $addresses = [];
        $records   = @dns_get_record($host, DNS_A + DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $addresses[] = $record['ip'];
                }

                if (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique(array_filter($addresses)));
    }

    protected function isBlockedIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach ($this->blockedIpv4Ranges() as $range) {
                if ($this->ipv4InCidr($ip, $range)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function blockedIpv4Ranges(): array
    {
        return [
            '0.0.0.0/8',
            '10.0.0.0/8',
            '100.64.0.0/10',
            '127.0.0.0/8',
            '169.254.0.0/16',
            '172.16.0.0/12',
            '192.0.0.0/24',
            '192.0.2.0/24',
            '192.168.0.0/16',
            '198.18.0.0/15',
            '198.51.100.0/24',
            '203.0.113.0/24',
            '224.0.0.0/4',
            '240.0.0.0/4',
        ];
    }

    protected function ipv4InCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}

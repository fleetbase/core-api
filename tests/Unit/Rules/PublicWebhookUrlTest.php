<?php

namespace Fleetbase\Rules {
    function dns_get_record(string $hostname, int $type): array|false
    {
        return PublicWebhookUrlDnsRecords::$records[$hostname] ?? [];
    }

    class PublicWebhookUrlDnsRecords
    {
        public static array $records = [];
    }
}

namespace {
    use Fleetbase\Rules\PublicWebhookUrl;
    use Fleetbase\Rules\PublicWebhookUrlDnsRecords;

    beforeEach(function () {
        PublicWebhookUrlDnsRecords::$records = [];
    });

    test('public webhook url rule rejects malformed non public and invalid scheme values', function () {
        $rule = new PublicWebhookUrl();

        expect($rule->passes('url', ['https://example.com/hook']))->toBeFalse()
            ->and($rule->passes('url', 'not-a-url'))->toBeFalse()
            ->and($rule->passes('url', 'ftp://93.184.216.34/hook'))->toBeFalse()
            ->and($rule->passes('url', 'http:///missing-host'))->toBeFalse()
            ->and($rule->message())->toBe('The :attribute must be a public HTTP or HTTPS URL.');
    });

    test('public webhook url rule evaluates resolved a and aaaa records without live dns', function () {
        PublicWebhookUrlDnsRecords::$records = [
            'hooks.fleetbase.test' => [
                ['ip' => '93.184.216.34'],
                ['ipv6' => '2606:2800:220:1:248:1893:25c8:1946'],
            ],
            'internal.fleetbase.test' => [
                ['ip' => '10.10.10.10'],
            ],
        ];

        $rule = new PublicWebhookUrl();

        expect($rule->passes('url', 'https://hooks.fleetbase.test/events'))->toBeTrue()
            ->and($rule->passes('url', 'https://internal.fleetbase.test/events'))->toBeFalse();
    });

    test('public webhook url rule blocks valid public-filtered ipv4 ranges reserved for local infrastructure', function () {
        $rule = new PublicWebhookUrl();

        expect($rule->passes('url', 'https://100.64.0.1/hook'))->toBeFalse()
            ->and($rule->passes('url', 'https://198.18.0.1/hook'))->toBeFalse()
            ->and($rule->passes('url', 'https://224.0.0.1/hook'))->toBeFalse();
    });

    test('public webhook url cidr helper safely rejects invalid comparison inputs', function () {
        $rule = new class extends PublicWebhookUrl {
            public function cidrMatches(string $ip, string $cidr): bool
            {
                return $this->ipv4InCidr($ip, $cidr);
            }
        };

        expect($rule->cidrMatches('not-an-ip', '10.0.0.0/8'))->toBeFalse()
            ->and($rule->cidrMatches('93.184.216.34', 'not-a-subnet/8'))->toBeFalse();
    });
}

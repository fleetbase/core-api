<?php

namespace Fleetbase\Expansions;

use Fleetbase\Build\Expansion;

class Str implements Expansion
{
    /**
     * Get the target class to expand.
     *
     * @return string|Class
     */
    public static function target()
    {
        return \Illuminate\Support\Str::class;
    }

    public function humanize()
    {
        return function (?string $string, bool $uppercase = true) {
            if (!is_string($string)) {
                return '';
            }

            $forbidden = [];
            $uppercase = ['api', 'vat', 'id', 'sku', 'usa', 'faq', '3pl'];

            $humanized = trim(strtolower((string) preg_replace(['/([A-Z])/', sprintf('/[%s\s]+/', '-'), sprintf('/[%s\s]+/', '_')], ['_$1', ' ', ' '], $string)));
            $humanized = trim(str_replace($forbidden, '', $humanized));
            $humanized = trim(
                str_replace(
                    $uppercase,
                    array_map(
                        function ($w) {
                            return strtoupper($w);
                        },
                        $uppercase
                    ),
                    $humanized
                )
            );

            return $uppercase ? ucfirst($humanized) : $humanized;
        };
    }

    public function domain()
    {
        return function (string $url) {
            // Accept a bare host or a full URL; fall back to the raw value when
            // there is no scheme for parse_url to key off of.
            $host = parse_url($url, PHP_URL_HOST) ?: $url;

            $labels = array_values(array_filter(explode('.', (string) $host), 'strlen'));
            $count  = count($labels);

            // Single-label ("localhost") or empty hosts have no registrable
            // domain — return the host as-is instead of reading a negative index.
            if ($count < 2) {
                return (string) $host;
            }

            return $labels[$count - 2] . '.' . $labels[$count - 1];
        };
    }
}

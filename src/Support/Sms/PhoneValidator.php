<?php

namespace Fleetbase\Support\Sms;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

/**
 * Decides whether a destination may be texted at all.
 *
 * A number must parse, be valid for its country and be of a type that can receive a
 * text (mobile, or the fixed-or-mobile type some countries report). Premium-rate,
 * shared-cost, satellite and similar ranges, the ones that pay the sender of SMS pumping
 * per message, are refused by type and by an administrator's prefix and country lists.
 *
 * There is deliberately no per-tenant country restriction by default: tenants operate
 * across borders and their countries cannot be inferred. An administrator may still set
 * `allowed_countries` on one company's exception.
 */
final class PhoneValidator
{
    public const REASON_INVALID             = 'invalid_destination';
    public const REASON_TYPE_NOT_ALLOWED    = 'type_not_allowed';
    public const REASON_COUNTRY_BLOCKED     = 'country_blocked';
    public const REASON_PREFIX_BLOCKED      = 'prefix_blocked';
    public const REASON_COUNTRY_NOT_ALLOWED = 'country_not_allowed';

    public const DEFAULT_ALLOWED_TYPES = ['MOBILE', 'FIXED_LINE_OR_MOBILE'];

    /**
     * Validate a destination against the rules: `enabled`, `allowed_types`,
     * `blocked_countries`, `blocked_prefixes`, `allowed_countries` (lists, or comma
     * separated strings). With validation disabled the number is only normalised.
     *
     * @return array{valid: bool, e164: ?string, country: ?string, type: ?string, reason: ?string}
     */
    public static function validate(string $phone, array $rules = []): array
    {
        $enabled    = (bool) ($rules['enabled'] ?? true);
        $normalized = self::normalize($phone);
        $result     = ['valid' => true, 'e164' => $normalized !== '' ? $normalized : null, 'country' => null, 'type' => null, 'reason' => null];

        $util   = PhoneNumberUtil::getInstance();
        $parsed = null;
        if ($normalized !== '') {
            try {
                $parsed = $util->parse($normalized, null);
            } catch (NumberParseException $e) {
                $parsed = null;
            }
        }

        if (!$parsed || !$util->isValidNumber($parsed)) {
            return $enabled ? self::refuse($result, self::REASON_INVALID) : $result;
        }

        $result['e164']    = $util->format($parsed, PhoneNumberFormat::E164);
        $result['country'] = $util->getRegionCodeForNumber($parsed) ?: null;
        $result['type']    = self::typeName($util->getNumberType($parsed));

        if (!$enabled) {
            return $result;
        }

        $allowedTypes = self::upperList($rules['allowed_types'] ?? self::DEFAULT_ALLOWED_TYPES);
        if ($allowedTypes && !in_array((string) $result['type'], $allowedTypes, true)) {
            return self::refuse($result, self::REASON_TYPE_NOT_ALLOWED);
        }

        foreach (self::list($rules['blocked_prefixes'] ?? []) as $prefix) {
            $prefix = '+' . ltrim(preg_replace('/[^0-9+]/', '', $prefix), '+');
            if (strlen($prefix) > 1 && str_starts_with($result['e164'], $prefix)) {
                return self::refuse($result, self::REASON_PREFIX_BLOCKED);
            }
        }

        $blockedCountries = self::upperList($rules['blocked_countries'] ?? []);
        if ($result['country'] && in_array($result['country'], $blockedCountries, true)) {
            return self::refuse($result, self::REASON_COUNTRY_BLOCKED);
        }

        $allowedCountries = self::upperList($rules['allowed_countries'] ?? []);
        if ($allowedCountries && !in_array((string) $result['country'], $allowedCountries, true)) {
            return self::refuse($result, self::REASON_COUNTRY_NOT_ALLOWED);
        }

        return $result;
    }

    /**
     * Strip formatting and give the number a leading `+`, the way the rest of Fleetbase
     * expects destinations (a `00` international prefix becomes `+`).
     */
    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/[^0-9+]/', '', trim($phone)) ?? '';
        $digits = preg_replace('/(?!^)\+/', '', $digits) ?? '';

        if ($digits === '' || $digits === '+') {
            return '';
        }

        if (str_starts_with($digits, '00')) {
            $digits = '+' . substr($digits, 2);
        }

        return str_starts_with($digits, '+') ? $digits : '+' . $digits;
    }

    /**
     * What to tell the caller. Deliberately says nothing about which rule matched beyond
     * "invalid" versus "cannot receive texts", so a list of blocked ranges cannot be probed.
     */
    public static function messageFor(?string $reason): string
    {
        return match ($reason) {
            self::REASON_INVALID          => 'The phone number provided is not valid.',
            self::REASON_TYPE_NOT_ALLOWED => 'The phone number provided cannot receive text messages.',
            default                       => 'Text messages cannot be sent to this phone number.',
        };
    }

    /**
     * The libphonenumber type as its constant name (MOBILE, PREMIUM_RATE, ...).
     */
    public static function typeName(int $type): string
    {
        $name = array_search($type, (new \ReflectionClass(PhoneNumberType::class))->getConstants(), true);

        return is_string($name) ? $name : 'UNKNOWN';
    }

    private static function refuse(array $result, string $reason): array
    {
        $result['valid']  = false;
        $result['reason'] = $reason;

        return $result;
    }

    private static function list(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return array_values(array_filter(array_map(fn ($item) => trim((string) $item), (array) $value), fn ($item) => $item !== ''));
    }

    private static function upperList(mixed $value): array
    {
        return array_map('strtoupper', self::list($value));
    }
}

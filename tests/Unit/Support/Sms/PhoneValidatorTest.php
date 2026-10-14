<?php

use Fleetbase\Support\Sms\PhoneValidator;

test('phone validator accepts valid mobile numbers from any country when no allowlist is configured', function () {
    $uk = PhoneValidator::validate('+44 7400 100123');
    $mn = PhoneValidator::validate('97699112233');
    $us = PhoneValidator::validate('+1 (415) 555-2671');

    expect($uk)->toMatchArray(['valid' => true, 'e164' => '+447400100123', 'country' => 'GB', 'type' => 'MOBILE', 'reason' => null])
        ->and($mn)->toMatchArray(['valid' => true, 'e164' => '+97699112233', 'country' => 'MN', 'type' => 'MOBILE'])
        ->and($us)->toMatchArray(['valid' => true, 'e164' => '+14155552671', 'country' => 'US', 'type' => 'FIXED_LINE_OR_MOBILE']);
});

test('phone validator does not guess a country for a number without one', function () {
    $local = PhoneValidator::validate('(415) 555-2671');

    expect($local['valid'])->toBeFalse()
        ->and($local['reason'])->toBe(PhoneValidator::REASON_INVALID);
});

test('phone validator normalises formatting and international prefixes', function () {
    expect(PhoneValidator::normalize('0044 7400 100123'))->toBe('+447400100123')
        ->and(PhoneValidator::normalize('+1 (415) 555-2671'))->toBe('+14155552671')
        ->and(PhoneValidator::normalize('97699112233'))->toBe('+97699112233')
        ->and(PhoneValidator::normalize('+'))->toBe('')
        ->and(PhoneValidator::normalize(''))->toBe('')
        ->and(PhoneValidator::validate('+14155552671')['e164'])->toBe('+14155552671');
});

test('phone validator refuses numbers that are invalid or cannot receive texts', function () {
    $short   = PhoneValidator::validate('+4471');
    $garbage = PhoneValidator::validate('not-a-number');
    $premium = PhoneValidator::validate('+449098790123');
    $land    = PhoneValidator::validate('+442079460000');

    expect($short)->toMatchArray(['valid' => false, 'reason' => PhoneValidator::REASON_INVALID])
        ->and($garbage)->toMatchArray(['valid' => false, 'e164' => null, 'reason' => PhoneValidator::REASON_INVALID])
        ->and($premium)->toMatchArray(['valid' => false, 'type' => 'PREMIUM_RATE', 'reason' => PhoneValidator::REASON_TYPE_NOT_ALLOWED])
        ->and($land)->toMatchArray(['valid' => false, 'type' => 'FIXED_LINE', 'reason' => PhoneValidator::REASON_TYPE_NOT_ALLOWED]);
});

test('phone validator treats a number the parser rejects outright as invalid', function () {
    expect(PhoneValidator::validate('+12'))->toMatchArray(['valid' => false, 'reason' => PhoneValidator::REASON_INVALID])
        ->and(PhoneValidator::validate('+12', ['enabled' => false]))->toMatchArray(['valid' => true, 'e164' => '+12', 'reason' => null]);
});

test('phone validator applies blocked prefixes, blocked countries and a company allowlist', function () {
    $prefix  = PhoneValidator::validate('+447400100123', ['blocked_prefixes' => '+800, +4474']);
    $country = PhoneValidator::validate('+447400100123', ['blocked_countries' => 'gb,fr']);
    $allowed = PhoneValidator::validate('+447400100123', ['allowed_countries' => ['MN', 'SG']]);
    $fine    = PhoneValidator::validate('+447400100123', ['blocked_prefixes' => ['+800'], 'blocked_countries' => ['FR'], 'allowed_countries' => ['GB']]);

    expect($prefix['reason'])->toBe(PhoneValidator::REASON_PREFIX_BLOCKED)
        ->and($country['reason'])->toBe(PhoneValidator::REASON_COUNTRY_BLOCKED)
        ->and($allowed['reason'])->toBe(PhoneValidator::REASON_COUNTRY_NOT_ALLOWED)
        ->and($fine['valid'])->toBeTrue();
});

test('phone validator only normalises when validation is disabled', function () {
    $invalid = PhoneValidator::validate('+4471', ['enabled' => false]);
    $premium = PhoneValidator::validate('+449098790123', ['enabled' => false, 'blocked_prefixes' => ['+44']]);

    expect($invalid)->toMatchArray(['valid' => true, 'e164' => '+4471', 'reason' => null])
        ->and($premium)->toMatchArray(['valid' => true, 'e164' => '+449098790123', 'type' => 'PREMIUM_RATE', 'reason' => null]);
});

test('phone validator explains refusals without naming the rule', function () {
    expect(PhoneValidator::messageFor(PhoneValidator::REASON_INVALID))->toBe('The phone number provided is not valid.')
        ->and(PhoneValidator::messageFor(PhoneValidator::REASON_TYPE_NOT_ALLOWED))->toBe('The phone number provided cannot receive text messages.')
        ->and(PhoneValidator::messageFor(PhoneValidator::REASON_PREFIX_BLOCKED))->toBe('Text messages cannot be sent to this phone number.')
        ->and(PhoneValidator::messageFor(PhoneValidator::REASON_COUNTRY_NOT_ALLOWED))->toBe('Text messages cannot be sent to this phone number.')
        ->and(PhoneValidator::typeName(1))->toBe('MOBILE')
        ->and(PhoneValidator::typeName(999))->toBe('UNKNOWN');
});

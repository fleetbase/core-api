<?php

use Fleetbase\Support\Utils;

/*
 * These tests exercise Utils::moneyFormat() against the real Cknow\Money\Money
 * adapter (backed by moneyphp/money + the intl extension) rather than a stub,
 * so that per-currency minor-unit exponents and sign handling are verified end
 * to end.
 *
 * The `$amount` argument is expected to be an integer number of the currency's
 * smallest unit (cents for USD, whole yen for JPY, fils for BHD, ...). The
 * adapter derives decimal placement from each currency's ISO-4217 exponent, so
 * the assertions below focus on the numeric core of the formatted output. That
 * keeps them stable across ICU versions, which vary the currency symbol and the
 * (sometimes non-breaking) spacing around it.
 */
beforeEach(function () {
    // A bound container with a config repository is required so the money
    // adapter can resolve its locale/currencies via the config() helper.
    bind_test_container();
});

/**
 * Reduce a formatted currency string to just its signed numeric core so that
 * assertions do not depend on the locale's currency symbol or spacing.
 */
function moneyNumericCore(string $formatted): string
{
    return preg_replace('/[^0-9.\-]/', '', $formatted);
}

test('utils money format renders positive, zero, and negative two-decimal amounts', function () {
    expect(moneyNumericCore(Utils::moneyFormat(500, 'USD')))->toBe('5.00')
        ->and(moneyNumericCore(Utils::moneyFormat(0, 'USD')))->toBe('0.00')
        // Negative amounts (refunds, adjustments) keep their sign.
        ->and(moneyNumericCore(Utils::moneyFormat(-500, 'USD')))->toBe('-5.00')
        ->and(Utils::moneyFormat(-500, 'USD'))->toContain('-');
});

test('utils money format normalizes formatted string input before formatting', function () {
    expect(moneyNumericCore(Utils::moneyFormat('$1,234.56', 'USD')))->toBe('1234.56')
        ->and(moneyNumericCore(Utils::moneyFormat('-$5.00', 'USD')))->toBe('-5.00');
});

test('utils money format honors zero-decimal currencies', function () {
    // JPY and KRW have no minor unit (exponent 0): 500 units renders with no
    // decimal places rather than being treated as cents.
    expect(moneyNumericCore(Utils::moneyFormat(500, 'JPY')))->toBe('500')
        ->and(Utils::moneyFormat(500, 'JPY'))->not->toContain('.')
        ->and(moneyNumericCore(Utils::moneyFormat(500, 'KRW')))->toBe('500')
        ->and(Utils::moneyFormat(500, 'KRW'))->not->toContain('.');
});

test('utils money format honors three-decimal currencies', function () {
    // BHD and KWD have a three-digit minor unit (exponent 3): 500 fils is 0.500,
    // not 5.00.
    expect(moneyNumericCore(Utils::moneyFormat(500, 'BHD')))->toBe('0.500')
        ->and(moneyNumericCore(Utils::moneyFormat(500, 'KWD')))->toBe('0.500');
});

test('utils money format treats non-numeric and null input as zero', function () {
    expect(moneyNumericCore(Utils::moneyFormat('abc', 'USD')))->toBe('0.00')
        ->and(moneyNumericCore(Utils::moneyFormat(null, 'USD')))->toBe('0.00');
});

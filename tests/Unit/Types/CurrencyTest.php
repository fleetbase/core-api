<?php

use Fleetbase\Exceptions\CurrencyException;
use Fleetbase\Types\Currency;

test('currency exposes iso metadata and serializes consistently', function () {
    $currency = new Currency('USD');

    expect($currency->getCode())->toBe('USD')
        ->and($currency->getTitle())->toBe('US Dollar')
        ->and($currency->getSymbol())->toBe('$')
        ->and($currency->getPrecision())->toBe(2)
        ->and($currency->getThousandSeparator())->toBe(',')
        ->and($currency->getDecimalSeparator())->toBe('.')
        ->and($currency->getSymbolPlacement())->toBe('before')
        ->and($currency->toArray())->toMatchArray([
            'code'              => 'USD',
            'title'             => 'US Dollar',
            'symbol'            => '$',
            'precision'         => 2,
            'thousandSeparator' => ',',
            'decimalSeparator'  => '.',
            'symbolPlacement'   => 'before',
        ])
        ->and(json_decode($currency->toJson(), true)['code'])->toBe('USD');
});

test('currency supports array construction lookup filtering and mutable formatting options', function () {
    $currency = new Currency(['code' => 'JPY']);
    $currency
        ->setPrecision(3)
        ->setThousandSeparator(' ')
        ->setDecimalSeparator(',')
        ->setSymbolPlacement('after');

    expect($currency->getCode())->toBe('JPY')
        ->and($currency->getPrecision())->toBe(3)
        ->and($currency->getThousandSeparator())->toBe(' ')
        ->and($currency->getDecimalSeparator())->toBe(',')
        ->and($currency->getSymbolPlacement())->toBe('after')
        ->and(Currency::has('USD'))->toBeTrue()
        ->and(Currency::has(null))->toBeFalse()
        ->and(Currency::getAllCurrencies())->toHaveKey('USD')
        ->and(Currency::getCurrency('USD')['title'])->toBe('US Dollar')
        ->and(Currency::first(fn (Currency $candidate) => $candidate->getCode() === 'MNT')->getCode())->toBe('MNT')
        ->and(Currency::filter(fn (Currency $candidate) => str_ends_with($candidate->getTitle(), 'Dollar'))->map(fn (Currency $candidate) => $candidate->getCode()))->toContain('USD')
        ->and(Currency::all()->map(fn (Currency $candidate) => $candidate->getCode()))->toContain('USD', 'JPY', 'MNT');
});

test('currency rejects unknown currency codes', function () {
    new Currency('ZZZ');
})->throws(CurrencyException::class, 'Currency not found: "ZZZ"');

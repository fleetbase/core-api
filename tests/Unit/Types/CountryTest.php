<?php

use Fleetbase\Exceptions\CountryException;
use Fleetbase\Types\Country;
use Illuminate\Support\Collection;

function withCountryVendorDeprecationsSuppressed(callable $callback)
{
    $previousReporting = error_reporting();

    error_reporting($previousReporting & ~E_DEPRECATED);
    set_error_handler(function (int $severity, string $message): bool {
        return $severity === E_DEPRECATED && str_contains($message, 'PragmaRX\\Coollection\\Package\\Coollection');
    }, E_DEPRECATED);

    try {
        return $callback();
    } finally {
        restore_error_handler();
        error_reporting($previousReporting);
    }
}

test('country exposes iso metadata and serializes consistently', function () {
    withCountryVendorDeprecationsSuppressed(function () {
        $country = new Country('US');

        expect($country->getCode())->toBe('USA')
            ->and($country->getCca2())->toBe('US')
            ->and($country->getCurrency())->toBe('USD')
            ->and($country->getName())->toBe('United States')
            ->and($country->getEmoji())->toBe("\u{1F1FA}\u{1F1F8}")
            ->and($country->simple())->toMatchArray([
                'name'     => 'United States',
                'code'     => 'USA',
                'currency' => 'USD',
                'emoji'    => "\u{1F1FA}\u{1F1F8}",
                'cca2'     => 'US',
                'abbrev'   => 'U.S.A.',
            ])
            ->and($country->only(['cca2', ['currency' => 'currency_code'], ['geo.region' => 'region']]))->toBe([
                'cca2'          => 'US',
                'currency_code' => 'USD',
                'region'        => 'Americas',
            ])
            ->and($country->toArray())->toHaveKey('cca2', 'US')
            ->and(json_decode($country->toJson(), true)['cca2'])->toBe('US')
            ->and($country->missingAccessor())->toBeNull();
    });
});

test('country supports lookup search filtering and currency matching', function () {
    withCountryVendorDeprecationsSuppressed(function () {
        $mongolia = Country::getByIso2('mn');

        expect($mongolia)->toBeInstanceOf(Country::class)
            ->and($mongolia->getCode())->toBe('MNG')
            ->and($mongolia->getName())->toBe('Mongolia')
            ->and($mongolia->getCurrency())->toBe('MNT')
            ->and(Country::has('MN'))->toBeTrue()
            ->and(Country::has(null))->toBeFalse()
            ->and(Country::whereCurrency('MNT')->getCca2())->toBe('MN')
            ->and(Country::fromCurrency('MNT')->getCode())->toBe('MNG')
            ->and(Country::first(fn (Country $country) => $country->getCca2() === 'US')->getCurrency())->toBe('USD')
            ->and(Country::filter(fn (Country $country) => $country->getCurrency() === 'USD'))->toBeInstanceOf(Collection::class)
            ->and(Country::search('')->count())->toBeGreaterThan(200)
            ->and(Country::search('mnt')->map(fn (Country $country) => $country->getCca2()))->toContain('MN')
            ->and(Country::search('u.s')->map(fn (Country $country) => $country->getCca2()))->toContain('US');
    });
});

test('country supports array construction and rejects unknown iso2 codes', function () {
    withCountryVendorDeprecationsSuppressed(function () {
        $country = new Country([
            'cca2'       => 'ZZ',
            'cca3'       => 'ZZZ',
            'name'       => ['common' => 'Testland', 'official' => 'Republic of Testland'],
            'currencies' => ['TST'],
            'flag'       => ['emoji' => "\u{1F3F3}\u{FE0F}"],
            'languages'  => ['eng' => 'English'],
            'geo'        => ['region' => 'Test Region'],
        ]);

        expect($country->getCode())->toBe('ZZZ')
            ->and($country->getName())->toBe('Testland')
            ->and($country->getCurrency())->toBe('TST')
            ->and($country->getEmoji())->toBe("\u{1F3F3}\u{FE0F}")
            ->and($country->only([['name' => 'country_name'], ['geo.region' => 'region']]))->toBe([
                'country_name' => 'Testland',
                'region'       => 'Test Region',
            ])
            ->and($country->__call('toArray', []))->toHaveKey('cca2', 'ZZ')
            ->and($country->only(['cca2', 123, ['missing' => 'missing_alias']]))->toBe([
                'cca2' => 'ZZ',
            ]);

        $arrayableCountry = new Country(new class {
            public function toArray(): array
            {
                return [
                    'cca2'       => 'AA',
                    'cca3'       => 'AAA',
                    'name'       => ['common' => 'Arrayland'],
                    'currencies' => ['ARY'],
                    'flag'       => ['emoji' => 'A'],
                ];
            }
        });

        expect($arrayableCountry->getCode())->toBe('AAA')
            ->and($arrayableCountry->getName())->toBe('Arrayland')
            ->and(Country::missingStatic())->toBeNull();
    });

    expect(fn () => withCountryVendorDeprecationsSuppressed(fn () => new Country('ZZ')))
        ->toThrow(CountryException::class, 'Country not found: "ZZ"');
});

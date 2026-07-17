<?php

use Fleetbase\Support\ParsePhone;
use Illuminate\Database\Eloquent\Model;
use libphonenumber\PhoneNumberFormat;

class ParsePhoneRecord extends Model
{
    protected $guarded = [];
}

test('parse phone preserves valid international numbers and supports alternate output formats', function () {
    $record = new ParsePhoneRecord(['phone' => '+14155552671']);

    expect(ParsePhone::fromModel($record))->toBe('+14155552671')
        ->and(ParsePhone::fromModel($record, [], PhoneNumberFormat::NATIONAL))->toBe('(415) 555-2671');
});

test('parse phone resolves numbers from model country and explicit option country', function () {
    $withCountry = new ParsePhoneRecord([
        'phone'    => '4155552671',
        'country'  => 'US',
        'currency' => 'USD',
        'timezone' => 'America/Los_Angeles',
    ]);
    $withTelephoneAlias = new ParsePhoneRecord(['telephone' => '02079460018']);

    expect(ParsePhone::fromModel($withCountry))->toBe('+14155552671')
        ->and(ParsePhone::fromModel($withTelephoneAlias, [
            'country'  => 'GB',
            'currency' => 'GBP',
            'timezone' => 'Europe/London',
        ]))->toBe('+442079460018');
});

test('parse phone returns original empty or unparseable values when no valid context exists', function () {
    session()->flush();

    expect(ParsePhone::fromModel(new ParsePhoneRecord()))->toBeNull()
        ->and(ParsePhone::fromModel(new ParsePhoneRecord(['phone' => ''])))->toBe('')
        ->and(ParsePhone::fromModel(new ParsePhoneRecord(['phone' => 'definitely-not-a-number']), [
            'country' => 'US',
        ]))->toBe('definitely-not-a-number');
});

<?php

use Fleetbase\Casts\CustomValue;
use Fleetbase\Casts\Json;
use Fleetbase\Casts\Money;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\Models\User;
use Illuminate\Database\Eloquent\Model;

class CastsTestModel extends Model
{
}

test('json cast decodes valid json and leaves non json values unchanged', function () {
    $cast  = new Json();
    $model = new CastsTestModel();

    expect($cast->get($model, 'meta', '{"enabled":true,"count":2}', []))->toBe([
        'enabled' => true,
        'count'   => 2,
    ])
        ->and($cast->get($model, 'meta', 'not-json', []))->toBe('not-json')
        ->and($cast->get($model, 'meta', ['already' => 'array'], []))->toBe(['already' => 'array'])
        ->and($cast->set($model, 'meta', ['nested' => ['value' => 'ok']], []))->toBe('{"nested":{"value":"ok"}}')
        ->and(Json::decode('[1,2,3]'))->toBe([1, 2, 3]);
});

test('money cast stores values as integer minor-unit-like digits', function () {
    $cast  = new Money();
    $model = new CastsTestModel();

    expect($cast->get($model, 'amount', 1299, []))->toBe(1299)
        ->and($cast->set($model, 'amount', null, []))->toBe(0)
        ->and($cast->set($model, 'amount', '$1,234.56', []))->toBe(123456)
        ->and($cast->set($model, 'amount', 'MNT ₮9,900', []))->toBe(9900)
        ->and(Money::apply('€7.05'))->toBe(705)
        ->and(Money::removeCurrencySymbols('$€£¥₹¢฿₽₪₩₮100'))->toBe('100')
        ->and(Money::removeSpecialCharactersExceptDotAndComma('USD 1,234.56!!'))->toBe('1,234.56');
});

test('polymorphic type cast normalizes objects package aliases and class strings', function () {
    bind_test_container();

    $cast  = new PolymorphicType();
    $model = new CastsTestModel();

    expect($cast->get($model, 'subject_type', User::class, []))->toBe(User::class)
        ->and($cast->set($model, 'subject_type', null, []))->toBeNull()
        ->and($cast->set($model, 'subject_type', new User(), []))->toBe(User::class)
        ->and($cast->set($model, 'subject_type', User::class, []))->toBe(User::class)
        ->and($cast->set($model, 'subject_type', 'user', []))->toBe('\\Fleetbase\\Models\\User')
        ->and($cast->set($model, 'subject_type', 'fleet-ops:order', []))->toBe('Fleetbase\\FleetOps\\Models\\Order');
});

test('custom value cast serializes structured values and preserves scalar file references', function () {
    $cast  = new CustomValue();
    $model = new CastsTestModel();

    expect($cast->get($model, 'value', '{"threshold":10}', ['value_type' => 'object']))->toBe(['threshold' => 10])
        ->and($cast->get($model, 'value', '["fragile","cold"]', ['value_type' => 'array']))->toBe(['fragile', 'cold'])
        ->and($cast->set($model, 'value', ['threshold' => 10], ['value_type' => 'object']))->toBe('{"threshold":10}')
        ->and($cast->set($model, 'value', ['fragile', 'cold'], ['value_type' => 'array']))->toBe('["fragile","cold"]')
        ->and($cast->get($model, 'value', 'plain text', ['value_type' => 'text']))->toBe('plain text')
        ->and($cast->set($model, 'value', 'plain text', ['value_type' => 'text']))->toBe('plain text')
        ->and($cast->get($model, 'value', 'file:not-a-uuid', ['value_type' => 'file']))->toBe('file:not-a-uuid');
});

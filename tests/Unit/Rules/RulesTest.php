<?php

use Fleetbase\Rules\ExcludeWords;
use Fleetbase\Rules\FileInput;
use Fleetbase\Rules\RequiredIfCreating;
use Fleetbase\Rules\ValidPhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

test('exclude words rejects full forbidden word segments case insensitively', function () {
    $rule = new ExcludeWords(['Admin', 'Root']);

    expect($rule->passes('name', 'fleet operations workspace'))->toBeTrue()
        ->and($rule->message())->toBe('The :attribute contains forbidden words.')
        ->and($rule->passes('name', 'Root access for ADMIN users'))->toBeFalse()
        ->and($rule->message())->toBe('The :attribute contains forbidden words: admin, root.')
        ->and($rule->passes('name', 'administrator tooling'))->toBeTrue();
});

test('valid phone number requires leading plus and digits only', function (string $value, bool $expected) {
    $rule = new ValidPhoneNumber();

    expect($rule->passes('phone', $value))->toBe($expected)
        ->and($rule->message())->toBe('The :attribute must start with a "+" and include only numbers.');
})->with([
    ['+97699112233', true],
    ['97699112233', false],
    ['+1 561 276 7156', false],
    ['+1-561-276-7156', false],
    ['+', false],
    ['+abc', false],
]);

test('file input accepts uploads public ids base64 data uris and urls', function () {
    $rule = new FileInput();
    $uploadPath = tempnam(sys_get_temp_dir(), 'fleetbase-file-input');
    file_put_contents($uploadPath, 'avatar');

    $upload = new UploadedFile($uploadPath, 'avatar.png', 'image/png', null, true);

    expect($rule->passes('avatar', $upload))->toBeTrue()
        ->and($rule->passes('avatar', 'file_1234567'))->toBeTrue()
        ->and($rule->passes('avatar', 'file_1234567890'))->toBeTrue()
        ->and($rule->passes('avatar', 'data:image/png;base64,' . base64_encode('avatar')))->toBeTrue()
        ->and($rule->passes('avatar', 'data:application/pdf;base64,' . base64_encode('pdf')))->toBeTrue()
        ->and($rule->passes('avatar', 'https://cdn.fleetbase.test/avatar.png'))->toBeTrue()
        ->and($rule->passes('avatar', 'ftp://files.fleetbase.test/avatar.png'))->toBeTrue()
        ->and($rule->passes('avatar', 'not-a-file'))->toBeFalse()
        ->and($rule->passes('avatar', ['file_1234567']))->toBeFalse()
        ->and($rule->message())->toBe('The :attribute must be a valid file upload, base64 string, file ID, or URL.');
});

test('required if creating follows the current request method contract', function () {
    $container = bind_test_container();
    $rule = new RequiredIfCreating();

    $container->instance('request', Request::create('/int/v1/resources', 'POST'));
    expect($rule->passes('name', null))->toBeTrue();

    $container->instance('request', Request::create('/int/v1/resources/resource_123', 'PUT'));
    expect($rule->passes('name', null))->toBeFalse();

    $container->instance('request', Request::create('/int/v1/resources/resource_123', 'PATCH'));
    expect($rule->passes('name', 'value'))->toBeFalse()
        ->and($rule->message())->toBe('The validation error message.');
});

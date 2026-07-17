<?php

use Fleetbase\Models\Company;

test('public id suffix is ten lowercase alphanumeric characters', function () {
    $publicId = Company::getPublicId();

    expect($publicId)->toMatch('/^[a-z0-9]{10}$/');
});

test('public id suffix generation remains unique in tight bulk loops', function () {
    $publicIds = [];

    for ($i = 0; $i < 5000; $i++) {
        $publicIds[] = Company::getPublicId();
    }

    expect(array_unique($publicIds))->toHaveCount(5000);
});

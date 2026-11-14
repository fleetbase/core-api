<?php

use Carbon\Carbon;
use Fleetbase\Models\Alert;
use Fleetbase\Models\ScheduleItem;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/*
 * Carbon 3 changed the default of `diffIn*` to a signed float, where Carbon 2 gave an
 * absolute integer. These values have a fractional part or run backwards in time, so
 * they fail on Carbon 3 if a call loses `absolute` or its `(int)` cast.
 */
beforeEach(function () {
    Container::getInstance()->instance('config', new Repository());
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00.500000'));
});
afterEach(fn () => Carbon::setTestNow());

test('alert duration and age are positive whole minutes', function () {
    $alert = new Alert();
    $alert->setRawAttributes([
        'triggered_at' => Carbon::now()->subSeconds(150),
        'resolved_at'  => Carbon::now(),
    ]);

    expect($alert->duration_minutes)->toBe(2)
        ->and($alert->age_minutes)->toBe(2);
});

test('schedule item duration is whole minutes whichever way round its times are', function () {
    $forward = new ScheduleItem();
    $forward->setRawAttributes(['start_at' => Carbon::now(), 'end_at' => Carbon::now()->addSeconds(150)]);
    $reverse = new ScheduleItem();
    $reverse->setRawAttributes(['start_at' => Carbon::now()->addSeconds(150), 'end_at' => Carbon::now()]);

    expect($forward->calculateDuration())->toBe(2)
        ->and($reverse->calculateDuration())->toBe(2);
});

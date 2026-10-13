<?php

use Fleetbase\Models\SmsSend;
use Fleetbase\Support\Sms\SmsContext;
use Fleetbase\Support\Sms\SmsLedger;
use Fleetbase\Tests\Fixtures\Support\SmsGuardFixture;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

function sms_ledger_context(string $destination = '+447400100123', string $company = 'company-1'): SmsContext
{
    return new SmsContext('storefront_create_customer', $destination, $company, 'store', 'store-1', 'storefront', '203.0.113.9', 'StorefrontApp/3.1', 'Fleetbase\\FleetOps\\Models\\Contact', null);
}

afterEach(function () {
    Carbon::setTestNow();
    EloquentModel::unsetConnectionResolver();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('sms ledger records a reserved send with its context and settles it', function () {
    SmsGuardFixture::database();

    $uuid = SmsLedger::record(sms_ledger_context(), SmsSend::STATUS_RESERVED, ['destination_country' => 'GB', 'destination_type' => 'MOBILE', 'meta' => ['decision' => 'allowed']]);
    SmsLedger::update($uuid, ['status' => SmsSend::STATUS_SENT, 'provider' => 'twilio', 'provider_message_id' => 'SM1']);
    SmsLedger::update(null, ['status' => 'ignored']);

    $row = SmsSend::where('uuid', $uuid)->first();

    expect($uuid)->toBeString()
        ->and($row->company_uuid)->toBe('company-1')
        ->and($row->tenant_type)->toBe('store')
        ->and($row->tenant_uuid)->toBe('store-1')
        ->and($row->purpose)->toBe('storefront_create_customer')
        ->and($row->source)->toBe('storefront')
        ->and($row->destination)->toBe('+447400100123')
        ->and($row->destination_hash)->toBe(hash('sha256', '+447400100123'))
        ->and($row->destination_country)->toBe('GB')
        ->and($row->status)->toBe('sent')
        ->and($row->provider)->toBe('twilio')
        ->and($row->provider_message_id)->toBe('SM1')
        ->and($row->ip_address)->toBe('203.0.113.9')
        ->and($row->user_agent)->toBe('StorefrontApp/3.1')
        ->and($row->meta)->toBe(['decision' => 'allowed'])
        ->and(SmsSend::count())->toBe(1);
});

test('sms ledger answers country and volume questions from sent rows only', function () {
    SmsGuardFixture::database();

    foreach ([['GB', 'sent'], ['GB', 'sent'], ['MN', 'sent'], ['US', 'blocked'], ['FR', 'failed']] as [$country, $status]) {
        SmsLedger::record(sms_ledger_context(), $status, ['destination_country' => strtolower($country)]);
    }
    SmsLedger::record(sms_ledger_context('+15550001111', 'company-2'), 'sent', ['destination_country' => 'US']);

    expect(SmsLedger::countriesFor('company-1'))->toEqualCanonicalizing(['GB', 'MN'])
        ->and(SmsLedger::sentCountFor('company-1'))->toBe(3)
        ->and(SmsLedger::sentCountFor('company-2'))->toBe(1)
        ->and(SmsLedger::countriesFor('company-none'))->toBe([]);
});

test('sms ledger deletes rows past retention and masks the number on older surviving rows', function () {
    SmsGuardFixture::database();
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', 'UTC'));

    $fresh = SmsLedger::record(sms_ledger_context('+447400100001'), 'sent');
    $old   = SmsLedger::record(sms_ledger_context('+447400100002'), 'sent');
    $gone  = SmsLedger::record(sms_ledger_context('+447400100003'), 'sent');
    SmsSend::where('uuid', $old)->update(['created_at' => Carbon::now()->subDays(45)]);
    SmsSend::where('uuid', $gone)->update(['created_at' => Carbon::now()->subDays(120)]);

    $result = SmsLedger::prune(90, 30);
    $again  = SmsLedger::prune(90, 30);

    expect($result)->toBe(['masked' => 1, 'deleted' => 1])
        ->and($again)->toBe(['masked' => 0, 'deleted' => 0])
        ->and(SmsSend::where('uuid', $fresh)->value('destination'))->toBe('+447400100001')
        ->and(SmsSend::where('uuid', $old)->value('destination'))->toBe('•••0002')
        ->and(SmsSend::where('uuid', $old)->value('destination_hash'))->toBe(hash('sha256', '+447400100002'))
        ->and(SmsSend::where('uuid', $gone)->exists())->toBeFalse()
        ->and(SmsLedger::mask('+1 (415) 555-2671'))->toBe('•••2671')
        ->and(SmsLedger::prune(0, 0))->toBe(['masked' => 0, 'deleted' => 0]);
});

test('sms ledger does nothing without a database', function () {
    bind_test_container([]);
    Facade::clearResolvedInstances();

    expect(SmsLedger::available())->toBeFalse()
        ->and(SmsLedger::record(sms_ledger_context(), 'reserved'))->toBeNull()
        ->and(SmsLedger::countriesFor('company-1'))->toBe([])
        ->and(SmsLedger::sentCountFor('company-1'))->toBe(0);

    SmsLedger::update('some-uuid', ['status' => 'sent']);
    expect(true)->toBeTrue();
});

test('sms ledger stops writing for a minute after a failure and never throws', function () {
    $capsule = SmsGuardFixture::database();
    $capsule->getConnection('mysql')->getSchemaBuilder()->drop('sms_sends');

    expect(SmsLedger::available())->toBeTrue()
        ->and(SmsLedger::record(sms_ledger_context(), 'reserved'))->toBeNull()
        ->and(Cache::has(SmsLedger::UNAVAILABLE_KEY))->toBeTrue()
        ->and(SmsLedger::available())->toBeFalse()
        ->and(collect(app('log')->entries)->pluck(1)->all())->toContain('sms.ledger_unavailable');

    SmsGuardFixture::smsSendsSchema($capsule);

    // Still marked unavailable, so nothing is written even though the table is back.
    expect(SmsLedger::record(sms_ledger_context(), 'reserved'))->toBeNull()
        ->and(SmsSend::count())->toBe(0);

    Cache::forget(SmsLedger::UNAVAILABLE_KEY);
    SmsLedger::update('missing-row', ['status' => 'sent']);

    expect(SmsLedger::record(sms_ledger_context(), 'reserved'))->toBeString()
        ->and(SmsSend::count())->toBe(1);
});

<?php

use Fleetbase\Models\User;
use Fleetbase\Notifications\SmsGuardAlert;
use Fleetbase\Support\Sms\SmsContext;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Facade;

function sms_guard_alert_context(): SmsContext
{
    return new SmsContext('storefront_create_customer', '+447400100123', 'company-1', 'store', 'store-1', 'storefront', '203.0.113.9', 'StorefrontApp/3.1');
}

afterEach(function () {
    Facade::clearResolvedInstances();
});

test('sms guard alert names every signal, including one it does not know', function () {
    $context = sms_guard_alert_context();

    $hourly   = SmsGuardAlert::for('company_hourly_budget_repeated', $context, ['budget' => 60, 'hours_at_budget_today' => 2, 'paused_minutes' => null, 'mode' => 'enforce']);
    $platform = SmsGuardAlert::for('platform_budget_threshold', $context, ['percent' => 80, 'sends_this_hour' => 1200, 'budget' => 1500, 'scope' => 'platform']);
    $unknown  = SmsGuardAlert::for('something_new', $context);

    expect($hourly->title)->toBe('tenant hit its hourly SMS budget twice')
        ->and($hourly->lines[0])->toBe('The tenant reached its hourly budget of 60 messages in 2 separate hours today.')
        ->and($hourly->lines)->toContain('SMS is paused for this scope until an administrator unpauses it.')
        ->and($platform->title)->toBe('platform at 80% of its hourly SMS budget')
        ->and($platform->lines[0])->toBe('All tenants together have sent 1200 of 1500 messages this hour.')
        ->and($platform->lines[1])->toBe('Scope platform (company company-1), purpose storefront_create_customer, source storefront.')
        ->and($unknown->title)->toBe('SMS guard signal: something_new')
        ->and($unknown->lines[0])->toBe('The SMS guard raised something_new.')
        ->and($unknown->data['context']['destination'])->toBe('+447400100123');
});

test('sms guard alert goes in app and by mail to administrators, and by mail only to plain addresses', function () {
    bind_test_container(['app.name' => 'Fleetbase']);
    $alert = SmsGuardAlert::for('company_daily_budget', sms_guard_alert_context(), ['sends_today' => 200, 'budget' => 200, 'paused_minutes' => 60, 'mode' => 'enforce']);
    $mail  = $alert->toMail(null);

    expect($alert->via(new User()))->toBe(['mail', 'database'])
        ->and($alert->via(new stdClass()))->toBe(['mail'])
        ->and($mail)->toBeInstanceOf(MailMessage::class)
        ->and($mail->subject)->toBe('Fleetbase SMS guard: tenant hit its daily SMS budget')
        ->and($mail->level)->toBe('error')
        ->and($mail->introLines)->toBe($alert->lines)
        ->and($alert->toArray(null)['signal'])->toBe('company_daily_budget');
});

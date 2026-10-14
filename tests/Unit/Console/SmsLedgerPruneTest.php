<?php

use Fleetbase\Console\Commands\SmsLedgerPrune;
use Fleetbase\Models\SmsSend;
use Fleetbase\Support\Sms\SmsContext;
use Fleetbase\Support\Sms\SmsLedger;
use Fleetbase\Tests\Fixtures\Support\SmsGuardFixture;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Tester\CommandTester;

afterEach(function () {
    Carbon::setTestNow();
    EloquentModel::unsetConnectionResolver();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('sms:ledger:prune applies the retention settings and reports what it did', function () {
    SmsGuardFixture::database();
    config(['sms.guard.ledger.retention_days' => 90, 'sms.guard.ledger.mask_after_days' => 30]);
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', 'UTC'));

    $context = new SmsContext('driver_login', '+447400100777', 'company-1');
    $old     = SmsLedger::record($context, 'sent');
    $gone    = SmsLedger::record($context, 'sent');
    SmsSend::where('uuid', $old)->update(['created_at' => Carbon::now()->subDays(40)]);
    SmsSend::where('uuid', $gone)->update(['created_at' => Carbon::now()->subDays(100)]);

    $command = new SmsLedgerPrune();
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $tester->execute([]);
    $maskedDestination = SmsSend::where('uuid', $old)->value('destination');

    $explicit = new SmsLedgerPrune();
    $explicit->setLaravel(app());
    $explicitTester = new CommandTester($explicit);
    $explicitTester->execute(['--days' => '10', '--mask-after' => '0']);

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('masked 1 row(s) older than 30 day(s), deleted 1 row(s) older than 90 day(s)')
        ->and($maskedDestination)->toBe('•••0777')
        ->and($explicitTester->getStatusCode())->toBe(0)
        ->and($explicitTester->getDisplay())->toContain('deleted 1 row(s) older than 10 day(s)')
        ->and(SmsSend::count())->toBe(0);
});

test('sms:ledger:prune fails cleanly without the ledger table', function () {
    $capsule = SmsGuardFixture::database();
    $capsule->getConnection('mysql')->getSchemaBuilder()->drop('sms_sends');

    $command = new SmsLedgerPrune();
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain('The SMS ledger could not be pruned');
});

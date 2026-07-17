<?php

use Fleetbase\Exports\ApiCredentialExport;
use Fleetbase\Exports\CompanyExport;
use Fleetbase\Exports\GroupExport;
use Fleetbase\Exports\UserExport;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\Company;
use Fleetbase\Models\Group;
use Fleetbase\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

function export_contracts_database(): Capsule
{
    $connectionConfig = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connectionConfig,
        'fleetbase.connection.db'    => 'mysql',
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connectionConfig, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('api_credentials', function ($table) {
        $table->string('uuid')->primary();
        $table->string('_key')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('key')->nullable();
        $table->string('secret')->nullable();
        $table->boolean('test_mode')->default(false);
        $table->boolean('api')->default(false);
        $table->text('browser_origins')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('slug')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('country')->nullable();
        $table->string('timezone')->nullable();
        $table->string('ip_address')->nullable();
        $table->timestamp('last_login')->nullable();
        $table->timestamp('email_verified_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('company_users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('status')->nullable();
        $table->boolean('external')->default(false);
        $table->timestamps();
        $table->softDeletes();
    });

    return $capsule;
}

function export_contracts_insert_rows(Capsule $capsule): void
{
    $db = $capsule->getConnection('mysql');

    $db->table('companies')->insert([
        ['uuid' => 'company-1', 'owner_uuid' => 'owner-1', 'name' => 'Acme Logistics', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
        ['uuid' => 'company-2', 'owner_uuid' => null, 'name' => 'Other Company', 'created_at' => '2026-01-02 00:00:00', 'updated_at' => '2026-01-02 00:00:00'],
    ]);
    $db->table('users')->insert([
        ['uuid' => 'owner-1', 'company_uuid' => 'company-1', 'name' => 'Owner One', 'email' => 'owner@example.test', 'phone' => '+15550000001', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
        ['uuid' => 'user-1', 'company_uuid' => 'company-1', 'name' => 'User One', 'email' => 'one@example.test', 'phone' => null, 'created_at' => '2026-01-03 00:00:00', 'updated_at' => '2026-01-03 00:00:00'],
        ['uuid' => 'user-2', 'company_uuid' => 'company-1', 'name' => 'User Two', 'email' => 'two@example.test', 'phone' => null, 'created_at' => '2026-01-04 00:00:00', 'updated_at' => '2026-01-04 00:00:00'],
        ['uuid' => 'user-other', 'company_uuid' => 'company-2', 'name' => 'Other User', 'email' => 'other@example.test', 'phone' => null, 'created_at' => '2026-01-05 00:00:00', 'updated_at' => '2026-01-05 00:00:00'],
    ]);
    $db->table('company_users')->insert([
        ['uuid' => 'company-user-1', 'company_uuid' => 'company-1', 'user_uuid' => 'owner-1', 'status' => 'active', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
        ['uuid' => 'company-user-2', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'status' => 'active', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
    ]);
    $db->table('api_credentials')->insert([
        ['uuid' => 'cred-1', 'company_uuid' => 'company-1', 'name' => 'Live Key', 'key' => 'flb_live_1', 'secret' => 'secret-1', 'test_mode' => false, 'created_at' => '2026-01-10 00:00:00', 'updated_at' => '2026-01-10 00:00:00'],
        ['uuid' => 'cred-2', 'company_uuid' => 'company-1', 'name' => 'Test Key', 'key' => 'flb_test_1', 'secret' => 'secret-2', 'test_mode' => true, 'created_at' => '2026-01-11 00:00:00', 'updated_at' => '2026-01-11 00:00:00'],
        ['uuid' => 'cred-other', 'company_uuid' => 'company-2', 'name' => 'Other Key', 'key' => 'flb_live_other', 'secret' => 'secret-other', 'test_mode' => false, 'created_at' => '2026-01-12 00:00:00', 'updated_at' => '2026-01-12 00:00:00'],
    ]);
}

afterEach(function () {
    session()->flush();
    Facade::clearResolvedInstances();
});

test('exports expose stable headings and column formats', function () {
    expect((new ApiCredentialExport())->headings())->toBe([
        'Name',
        'Public Key',
        'Secret Key',
        'Environment',
        'Expiry Date',
        'Last Used',
        'Date Created',
    ])
        ->and((new ApiCredentialExport())->columnFormats())->toBe([
            'E' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'F' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'G' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ])
        ->and((new CompanyExport())->headings())->toBe(['Name', 'Owner', 'Email', 'Phone', 'Users Count', 'Created'])
        ->and((new CompanyExport())->columnFormats())->toBe([
            'D' => '+#',
            'F' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ])
        ->and((new GroupExport())->headings())->toBe(['Name', 'Date Created'])
        ->and((new GroupExport())->columnFormats())->toBe(['B' => NumberFormat::FORMAT_DATE_DDMMYYYY])
        ->and((new UserExport())->headings())->toBe([
            'Name',
            'Company',
            'Email',
            'Phone',
            'Country',
            'Timezone',
            'IP Address',
            'Last Login',
            'Email Verified At',
            'Date Created',
        ])
        ->and((new UserExport())->columnFormats())->toBe([
            'D' => '+#',
            'H' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'I' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'J' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ]);
});

test('exports map model attributes and fallback values into spreadsheet rows', function () {
    export_contracts_database();

    $created  = Carbon::parse('2026-02-01 09:00:00');
    $expires  = Carbon::parse('2026-03-01 09:00:00');
    $lastUsed = Carbon::parse('2026-02-15 09:00:00');

    $apiCredential = new ApiCredential();
    $apiCredential->setRawAttributes([
        'name'         => 'Console Key',
        'key'          => 'flb_live_key',
        'secret'       => 'secret-hash',
        'test_mode'    => false,
        'expires_at'   => $expires,
        'last_used_at' => $lastUsed,
        'created_at'   => $created,
    ]);

    $testCredential = new ApiCredential();
    $testCredential->setRawAttributes([
        'name'       => 'Sandbox Key',
        'key'        => 'flb_test_key',
        'secret'     => 'secret-test',
        'test_mode'  => true,
        'created_at' => $created,
    ]);

    $owner = new User();
    $owner->forceFill(['name' => 'Owner One', 'email' => 'owner@example.test', 'phone' => '+15550000001']);
    $company = new Company();
    $company->forceFill(['name' => 'Acme Logistics', 'created_at' => $created]);
    $company->setRelation('owner', $owner);
    $company->setRelation('companyUsers', new Collection([new User(), new User()]));

    $group = new Group();
    $group->forceFill(['name' => 'Dispatchers', 'created_at' => $created]);

    $userCompany = new Company();
    $userCompany->forceFill(['name' => 'Acme Logistics']);
    $user = new User();
    $user->forceFill([
        'name'              => 'User One',
        'email'             => 'one@example.test',
        'phone'             => '+15550000002',
        'country'           => 'US',
        'timezone'          => 'America/New_York',
        'ip_address'        => '203.0.113.10',
        'last_login'        => $lastUsed,
        'email_verified_at' => null,
        'created_at'        => $created,
    ]);
    $user->setRelation('company', $userCompany);

    expect((new ApiCredentialExport())->map($apiCredential))->toEqual([
        'Console Key',
        'flb_live_key',
        'secret-hash',
        'Live',
        $expires,
        $lastUsed,
        $created,
    ])
        ->and((new ApiCredentialExport())->map($testCredential))->toEqual([
            'Sandbox Key',
            'flb_test_key',
            'secret-test',
            'Test',
            'Never',
            'Never',
            $created,
        ])
        ->and((new CompanyExport())->map($company))->toEqual([
            'Acme Logistics',
            'Owner One',
            'owner@example.test',
            '+15550000001',
            2,
            $created,
        ])
        ->and((new GroupExport())->map($group))->toEqual(['Dispatchers', $created])
        ->and((new UserExport())->map($user))->toEqual([
            'User One',
            'Acme Logistics',
            'one@example.test',
            '+15550000002',
            'US',
            'America/New_York',
            '203.0.113.10',
            $lastUsed,
            'Never',
            $created,
        ]);
});

test('exports collect selected and tenant scoped records for api credentials users and companies', function () {
    $capsule = export_contracts_database();
    export_contracts_insert_rows($capsule);
    session(['company' => 'company-1']);

    expect((new ApiCredentialExport())->collection()->pluck('uuid')->all())->toBe(['cred-1', 'cred-2'])
        ->and((new ApiCredentialExport(['cred-other']))->collection()->pluck('uuid')->all())->toBe(['cred-other'])
        ->and((new UserExport())->collection()->pluck('uuid')->all())->toBe(['owner-1', 'user-1', 'user-2'])
        ->and((new UserExport(['user-1', 'user-other']))->collection()->pluck('uuid')->all())->toBe(['user-1'])
        ->and((new CompanyExport())->collection()->pluck('uuid')->all())->toBe(['company-1', 'company-2'])
        ->and((new CompanyExport(['company-1']))->collection()->first()->companyUsers)->toHaveCount(2);
});

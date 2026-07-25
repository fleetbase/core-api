<?php

use Fleetbase\Models\Company;
use Fleetbase\Models\Transaction;
use Fleetbase\Models\TransactionItem;
use Fleetbase\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

function transaction_models_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'api.cache.enabled'          => false,
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
    ]);
    $container->instance('cache', new class {
        private array $values = [];

        public function tags(array $tags): self
        {
            return $this;
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->values[$key] ?? $default;
        }

        public function put(string $key, mixed $value, mixed $ttl = null): bool
        {
            $this->values[$key] = $value;

            return true;
        }

        public function forget(string $key): bool
        {
            unset($this->values[$key]);

            return true;
        }

        public function increment(string $key, int $value = 1): int
        {
            $this->values[$key] = ($this->values[$key] ?? 0) + $value;

            return $this->values[$key];
        }

        public function flush(): bool
        {
            $this->values = [];

            return true;
        }
    });
    $container->instance('responsecache', new class {
        public function clear(): bool
        {
            return true;
        }
    });
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('responsecache');
    session()->flush();

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('transactions', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('payer_uuid')->nullable();
        $table->string('payer_type')->nullable();
        $table->string('payee_uuid')->nullable();
        $table->string('payee_type')->nullable();
        $table->string('initiator_uuid')->nullable();
        $table->string('initiator_type')->nullable();
        $table->string('context_uuid')->nullable();
        $table->string('context_type')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('owner_type')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('customer_type')->nullable();
        $table->string('type')->nullable();
        $table->string('direction')->nullable();
        $table->string('status')->nullable();
        $table->string('settlement_status')->nullable();
        $table->integer('amount')->default(0);
        $table->integer('fee_amount')->default(0);
        $table->integer('tax_amount')->default(0);
        $table->integer('net_amount')->default(0);
        $table->string('currency')->nullable();
        $table->decimal('exchange_rate', 16, 8)->nullable();
        $table->string('settled_currency')->nullable();
        $table->integer('settled_amount')->default(0);
        $table->integer('balance_after')->default(0);
        $table->string('gateway')->nullable();
        $table->string('gateway_uuid')->nullable();
        $table->string('gateway_transaction_id')->nullable();
        $table->text('gateway_response')->nullable();
        $table->string('payment_method')->nullable();
        $table->string('payment_method_last4')->nullable();
        $table->string('payment_method_brand')->nullable();
        $table->string('reference')->nullable();
        $table->string('parent_transaction_uuid')->nullable();
        $table->text('description')->nullable();
        $table->text('notes')->nullable();
        $table->string('failure_reason')->nullable();
        $table->string('failure_code')->nullable();
        $table->string('period')->nullable();
        $table->text('tags')->nullable();
        $table->string('ip_address')->nullable();
        $table->text('meta')->nullable();
        $table->timestamp('settled_at')->nullable();
        $table->timestamp('voided_at')->nullable();
        $table->timestamp('reversed_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('transaction_items', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('transaction_uuid')->nullable();
        $table->integer('quantity')->default(0);
        $table->integer('unit_price')->default(0);
        $table->integer('amount')->default(0);
        $table->string('currency')->nullable();
        $table->decimal('tax_rate', 8, 2)->nullable();
        $table->integer('tax_amount')->default(0);
        $table->text('details')->nullable();
        $table->text('description')->nullable();
        $table->string('code')->nullable();
        $table->integer('sort_order')->default(0);
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    return $capsule;
}

it('casts monetary JSON and polymorphic transaction attributes for API serialization', function () {
    bind_test_container();

    $transaction = new Transaction();
    $transaction->fill([
        'amount'           => '$1,234.56',
        'fee_amount'       => 'USD 12.34',
        'tax_amount'       => null,
        'net_amount'       => '1,222.22',
        'balance_after'    => '9,999.00',
        'settled_amount'   => '500.50',
        'exchange_rate'    => '1.123456789',
        'gateway_response' => ['id' => 'gw_1', 'status' => 'captured'],
        'tags'             => ['dispatch', 'wallet'],
        'meta'             => ['source' => 'checkout'],
        'subject_type'     => User::class,
        'payer_type'       => User::class,
        'payee_type'       => Company::class,
        'initiator_type'   => User::class,
        'context_type'     => 'Fleetbase\\FleetOps\\Models\\Order',
        'customer_type'    => Company::class,
    ]);

    expect($transaction->amount)->toBe(123456)
        ->and($transaction->fee_amount)->toBe(1234)
        ->and($transaction->tax_amount)->toBe(0)
        ->and($transaction->net_amount)->toBe(122222)
        ->and($transaction->balance_after)->toBe(999900)
        ->and($transaction->settled_amount)->toBe(50050)
        ->and((string) $transaction->exchange_rate)->toBe('1.12345679')
        ->and($transaction->gateway_response)->toBe(['id' => 'gw_1', 'status' => 'captured'])
        ->and($transaction->tags)->toBe(['dispatch', 'wallet'])
        ->and($transaction->meta)->toBe(['source' => 'checkout'])
        ->and($transaction->subject_type)->toBe(User::class)
        ->and($transaction->payer_type)->toBe(User::class)
        ->and($transaction->payee_type)->toBe(Company::class)
        ->and($transaction->initiator_type)->toBe(User::class)
        ->and($transaction->context_type)->toBe('Fleetbase\\FleetOps\\Models\\Order')
        ->and($transaction->customer_type)->toBe(Company::class)
        ->and($transaction->toArray())->not->toHaveKey('gateway_response');
});

it('evaluates transaction status settlement refund reversal void and expiry helpers', function () {
    transaction_models_database();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00', 'UTC'));

    $credit = new Transaction([
        'direction'         => Transaction::DIRECTION_CREDIT,
        'status'            => Transaction::STATUS_SUCCESS,
        'settlement_status' => Transaction::SETTLEMENT_STATUS_PAID,
    ]);
    $debit = new Transaction([
        'direction'         => Transaction::DIRECTION_DEBIT,
        'status'            => Transaction::STATUS_PENDING,
        'settlement_status' => Transaction::SETTLEMENT_STATUS_UNPAID,
    ]);
    $failedRefund = new Transaction([
        'status'                  => Transaction::STATUS_FAILED,
        'settlement_status'       => Transaction::SETTLEMENT_STATUS_PARTIALLY_REFUNDED,
        'parent_transaction_uuid' => 'parent-1',
    ]);
    $voidedByTimestamp                = new Transaction(['status' => Transaction::STATUS_SUCCESS]);
    $voidedByTimestamp->voided_at     = Carbon::parse('2026-07-17 10:00:00', 'UTC');
    $reversedByTimestamp              = new Transaction(['status' => Transaction::STATUS_SUCCESS]);
    $reversedByTimestamp->reversed_at = Carbon::parse('2026-07-17 10:30:00', 'UTC');
    $expiredByTimestamp               = new Transaction(['status' => Transaction::STATUS_PENDING]);
    $expiredByTimestamp->expires_at   = Carbon::parse('2026-07-17 11:59:00', 'UTC');
    $futureExpiry                     = new Transaction(['status' => Transaction::STATUS_PENDING]);
    $futureExpiry->expires_at         = Carbon::parse('2026-07-17 12:30:00', 'UTC');

    expect($credit->isCredit())->toBeTrue()
        ->and($credit->isDebit())->toBeFalse()
        ->and($credit->isSuccessful())->toBeTrue()
        ->and($credit->isSettled())->toBeTrue()
        ->and($debit->isDebit())->toBeTrue()
        ->and($debit->isPending())->toBeTrue()
        ->and($debit->isUnpaid())->toBeTrue()
        ->and($failedRefund->isFailed())->toBeTrue()
        ->and($failedRefund->isRefund())->toBeTrue()
        ->and($failedRefund->isRefunded())->toBeTrue()
        ->and((new Transaction(['settlement_status' => Transaction::SETTLEMENT_STATUS_PARTIALLY_PAID]))->isPartiallyPaid())->toBeTrue()
        ->and((new Transaction(['status' => Transaction::STATUS_VOIDED]))->isVoided())->toBeTrue()
        ->and($voidedByTimestamp->isVoided())->toBeTrue()
        ->and((new Transaction(['status' => Transaction::STATUS_REVERSED]))->isReversed())->toBeTrue()
        ->and($reversedByTimestamp->isReversed())->toBeTrue()
        ->and((new Transaction(['status' => Transaction::STATUS_EXPIRED]))->isExpired())->toBeTrue()
        ->and($expiredByTimestamp->isExpired())->toBeTrue()
        ->and($futureExpiry->isExpired())->toBeFalse();

    Carbon::setTestNow();
});

it('filters transactions by direction status settlement type period actor context and refunds', function () {
    $capsule = transaction_models_database();

    $capsule->getConnection('mysql')->table('transactions')->insert([
        [
            'uuid'                    => 'transaction-1',
            'direction'               => Transaction::DIRECTION_CREDIT,
            'status'                  => Transaction::STATUS_SUCCESS,
            'settlement_status'       => Transaction::SETTLEMENT_STATUS_PAID,
            'type'                    => Transaction::TYPE_GATEWAY_CHARGE,
            'period'                  => '2026-07',
            'subject_uuid'            => 'company-1',
            'subject_type'            => Company::class,
            'payer_uuid'              => 'user-1',
            'payer_type'              => User::class,
            'payee_uuid'              => 'company-1',
            'payee_type'              => Company::class,
            'context_uuid'            => 'order-1',
            'context_type'            => Company::class,
            'parent_transaction_uuid' => null,
            'created_at'              => '2026-07-17 10:00:00',
            'updated_at'              => '2026-07-17 10:00:00',
        ],
        [
            'uuid'                    => 'transaction-2',
            'direction'               => Transaction::DIRECTION_DEBIT,
            'status'                  => Transaction::STATUS_PENDING,
            'settlement_status'       => Transaction::SETTLEMENT_STATUS_UNPAID,
            'type'                    => Transaction::TYPE_WALLET_WITHDRAWAL,
            'period'                  => '2026-07',
            'subject_uuid'            => 'user-1',
            'subject_type'            => User::class,
            'payer_uuid'              => 'company-1',
            'payer_type'              => Company::class,
            'payee_uuid'              => 'user-1',
            'payee_type'              => User::class,
            'context_uuid'            => 'wallet-1',
            'context_type'            => 'Wallet',
            'parent_transaction_uuid' => null,
            'created_at'              => '2026-07-17 10:00:00',
            'updated_at'              => '2026-07-17 10:00:00',
        ],
        [
            'uuid'                    => 'transaction-3',
            'direction'               => Transaction::DIRECTION_DEBIT,
            'status'                  => Transaction::STATUS_FAILED,
            'settlement_status'       => Transaction::SETTLEMENT_STATUS_REFUNDED,
            'type'                    => Transaction::TYPE_GATEWAY_REFUND,
            'period'                  => '2026-06',
            'subject_uuid'            => 'company-1',
            'subject_type'            => Company::class,
            'payer_uuid'              => 'company-1',
            'payer_type'              => Company::class,
            'payee_uuid'              => 'user-1',
            'payee_type'              => User::class,
            'context_uuid'            => 'order-1',
            'context_type'            => Company::class,
            'parent_transaction_uuid' => 'transaction-1',
            'created_at'              => '2026-07-17 10:00:00',
            'updated_at'              => '2026-07-17 10:00:00',
        ],
    ]);

    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-1'], true);
    $user = new User();
    $user->setRawAttributes(['uuid' => 'user-1'], true);
    $context = new Company();
    $context->setRawAttributes(['uuid' => 'order-1'], true);

    expect(Transaction::query()->credits()->pluck('uuid')->all())->toBe(['transaction-1'])
        ->and(Transaction::query()->debits()->pluck('uuid')->all())->toBe(['transaction-2', 'transaction-3'])
        ->and(Transaction::query()->successful()->pluck('uuid')->all())->toBe(['transaction-1'])
        ->and(Transaction::query()->pending()->pluck('uuid')->all())->toBe(['transaction-2'])
        ->and(Transaction::query()->failed()->pluck('uuid')->all())->toBe(['transaction-3'])
        ->and(Transaction::query()->settled()->pluck('uuid')->all())->toBe(['transaction-1'])
        ->and(Transaction::query()->ofType(Transaction::TYPE_GATEWAY_REFUND)->pluck('uuid')->all())->toBe(['transaction-3'])
        ->and(Transaction::query()->forPeriod('2026-07')->pluck('uuid')->all())->toBe(['transaction-1', 'transaction-2'])
        ->and(Transaction::query()->forSubject($company)->pluck('uuid')->all())->toBe(['transaction-1', 'transaction-3'])
        ->and(Transaction::query()->forPayer($user)->pluck('uuid')->all())->toBe(['transaction-1'])
        ->and(Transaction::query()->forPayee($user)->pluck('uuid')->all())->toBe(['transaction-2', 'transaction-3'])
        ->and(Transaction::query()->forContext($context)->pluck('uuid')->all())->toBe(['transaction-1', 'transaction-3'])
        ->and(Transaction::query()->refunds()->pluck('uuid')->all())->toBe(['transaction-3']);
});

it('defines transaction relationship key contracts and item monetary calculations', function () {
    bind_test_container();

    $transaction = new Transaction();

    expect($transaction->subject()->getMorphType())->toBe('subject_type')
        ->and($transaction->payer()->getMorphType())->toBe('payer_type')
        ->and($transaction->payee()->getMorphType())->toBe('payee_type')
        ->and($transaction->initiator()->getMorphType())->toBe('initiator_type')
        ->and($transaction->context()->getMorphType())->toBe('context_type')
        ->and($transaction->owner()->getMorphType())->toBe('owner_type')
        ->and($transaction->customer()->getMorphType())->toBe('customer_type')
        ->and($transaction->parentTransaction()->getForeignKeyName())->toBe('parent_transaction_uuid')
        ->and($transaction->parentTransaction()->getOwnerKeyName())->toBe('uuid')
        ->and($transaction->childTransactions()->getForeignKeyName())->toBe('parent_transaction_uuid')
        ->and($transaction->items()->getForeignKeyName())->toBe('transaction_uuid')
        ->and($transaction->items()->getLocalKeyName())->toBe('uuid');

    $item     = new TransactionItem();
    $computed = new TransactionItem([
        'quantity'   => 3,
        'unit_price' => '12.50',
        'amount'     => '1.00',
        'tax_rate'   => '8.25',
        'tax_amount' => '0.10',
    ]);
    $fallback = new TransactionItem([
        'quantity'   => 0,
        'unit_price' => 0,
        'amount'     => '42.00',
        'tax_rate'   => 0,
        'tax_amount' => '5.00',
    ]);

    expect($computed->quantity)->toBe(3)
        ->and($computed->unit_price)->toBe(1250)
        ->and($computed->amount)->toBe(100)
        ->and((string) $computed->tax_rate)->toBe('8.25')
        ->and($item->transaction()->getForeignKeyName())->toBe('transaction_uuid')
        ->and($item->transaction()->getOwnerKeyName())->toBe('uuid')
        ->and($computed->getLineTotal())->toBe(3750)
        ->and($computed->calculateTax())->toBe(309)
        ->and($fallback->getLineTotal())->toBe(4200)
        ->and($fallback->calculateTax())->toBe(500);
});

it('generates internal transaction numbers and retries when generated gateway ids already exist', function () {
    transaction_models_database();

    mt_srand(1776);
    $collidingNumber = Transaction::generateInternalNumber(6);
    Transaction::query()->insert([
        'uuid'                   => 'transaction-collision',
        'gateway_transaction_id' => $collidingNumber,
        'created_at'             => '2026-07-17 10:00:00',
        'updated_at'             => '2026-07-17 10:00:00',
    ]);

    mt_srand(1776);
    $generated = Transaction::generateNumber(6);

    expect($collidingNumber)->toStartWith('TR')
        ->and($collidingNumber)->toHaveLength(8)
        ->and($generated)->toStartWith('TR')
        ->and($generated)->toHaveLength(8)
        ->and($generated)->not->toBe($collidingNumber);

    mt_srand();
});

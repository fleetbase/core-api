<?php

use Fleetbase\Models\Activity;
use Fleetbase\Models\Alert;
use Fleetbase\Models\Comment;
use Fleetbase\Models\Company;
use Fleetbase\Models\Notification;
use Fleetbase\Models\ReportAuditLog;
use Fleetbase\Models\User;
use Illuminate\Auth\AuthManager;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Spatie\Activitylog\ActivityLogger;
use Spatie\Activitylog\ActivityLogStatus;
use Spatie\Activitylog\CauserResolver;
use Spatie\Activitylog\LogBatch;
use Spatie\Activitylog\PendingActivityLog;

class OperationalAlertSpy extends Alert
{
    public array $updates = [];

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }
}

class OperationalFailingAlertSpy extends OperationalAlertSpy
{
    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;

        return false;
    }
}

class OperationalNotificationSpy extends Notification
{
    public int $saves   = 0;
    public int $deletes = 0;

    public function save(array $options = []): bool
    {
        $this->saves++;
        $this->syncOriginal();

        return true;
    }

    public function delete(): ?bool
    {
        $this->deletes++;

        return true;
    }
}

class OperationalScopeBuilderFake
{
    public array $wheres = [];

    public function where(string $column, mixed $value): self
    {
        $this->wheres[] = [$column, $value];

        return $this;
    }
}

function operational_models_database(): Capsule
{
    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'                => 'mysql',
        'database.connections.mysql'      => $connection,
        'fleetbase.connection.db'         => 'mysql',
        'activitylog.enabled'             => true,
        'activitylog.default_log_name'    => 'default',
        'activitylog.default_auth_driver' => null,
        'activitylog.activity_model'      => Activity::class,
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

        public function rememberForever(string $key, callable $callback): mixed
        {
            if (!array_key_exists($key, $this->values)) {
                $this->values[$key] = $callback();
            }

            return $this->values[$key];
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
    Facade::clearResolvedInstance('cache');
    $container->instance('responsecache', new class {
        public function clear(): bool
        {
            return true;
        }
    });
    Facade::clearResolvedInstance('responsecache');

    $causerResolver = new CauserResolver($container->make('config'), new AuthManager($container));
    $causerResolver->resolveUsing(fn () => null);
    $activityLogger = new ActivityLogger($container->make('config'), new ActivityLogStatus($container->make('config')), new LogBatch(), $causerResolver);
    $container->instance(PendingActivityLog::class, new PendingActivityLog($activityLogger, new ActivityLogStatus($container->make('config'))));
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
    $schema->create('activities', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('log_name')->nullable();
        $table->text('description')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('subject_id')->nullable();
        $table->string('causer_type')->nullable();
        $table->string('causer_id')->nullable();
        $table->string('event')->nullable();
        $table->text('properties')->nullable();
        $table->uuid('batch_uuid')->nullable();
        $table->timestamps();
    });
    $schema->create('alerts', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('type')->nullable();
        $table->string('severity')->nullable();
        $table->string('status')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->text('message')->nullable();
        $table->text('rule')->nullable();
        $table->text('context')->nullable();
        $table->timestamp('triggered_at')->nullable();
        $table->timestamp('acknowledged_at')->nullable();
        $table->timestamp('resolved_at')->nullable();
        $table->string('acknowledged_by_uuid')->nullable();
        $table->string('resolved_by_uuid')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('comments', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('author_uuid')->nullable();
        $table->string('parent_comment_uuid')->nullable();
        $table->text('content')->nullable();
        $table->text('tags')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    return $capsule;
}

it('humanizes activity subject and causer model types for API display', function () {
    bind_test_container();

    $emptyActivity = new Activity();
    $activity      = new Activity();
    $activity->setRawAttributes([
        'subject_type' => 'Fleetbase\\FleetOps\\Models\\ServiceQuote',
        'causer_type'  => User::class,
    ], true);

    expect($emptyActivity->humanized_subject_type)->toBeNull()
        ->and($emptyActivity->humanized_causer_type)->toBeNull()
        ->and($activity->humanized_subject_type)->toBe('Service Quote')
        ->and($activity->humanized_causer_type)->toBe('User');
});

it('derives alert names timestamps state and priority values', function () {
    operational_models_database();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00', 'UTC'));

    $subject        = (object) ['display_name' => 'Trailer Sensor'];
    $acknowledgedBy = new User();
    $acknowledgedBy->setRawAttributes(['uuid' => 'user-ack', 'name' => 'Ada'], true);
    $resolvedBy = new User();
    $resolvedBy->setRawAttributes(['uuid' => 'user-res', 'name' => 'Grace'], true);

    $alert = new Alert();
    $alert->setRawAttributes([
        'uuid'            => 'alert-1',
        'severity'        => 'high',
        'status'          => 'resolved',
        'triggered_at'    => Carbon::parse('2026-07-17 10:00:00', 'UTC'),
        'created_at'      => Carbon::parse('2026-07-17 09:30:00', 'UTC'),
        'acknowledged_at' => Carbon::parse('2026-07-17 10:15:00', 'UTC'),
        'resolved_at'     => Carbon::parse('2026-07-17 11:30:00', 'UTC'),
    ], true);
    $alert->setRelation('subject', $subject);
    $alert->setRelation('acknowledgedBy', $acknowledgedBy);
    $alert->setRelation('resolvedBy', $resolvedBy);

    expect($alert->subject_name)->toBe('Trailer Sensor')
        ->and($alert->acknowledged_by_name)->toBe('Ada')
        ->and($alert->resolved_by_name)->toBe('Grace')
        ->and($alert->is_acknowledged)->toBeTrue()
        ->and($alert->is_resolved)->toBeTrue()
        ->and($alert->duration_minutes)->toBe(90)
        ->and($alert->age_minutes)->toBe(120)
        ->and($alert->getSeverityLevel())->toBe(3)
        ->and($alert->getPriorityScore())->toBe(370);

    Carbon::setTestNow();
});

it('exposes alert relationship contracts and nullable computed fallbacks', function () {
    operational_models_database();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00', 'UTC'));

    $alert = new Alert();
    $alert->setRawAttributes([
        'uuid'         => 'alert-nullable',
        'severity'     => 'unknown',
        'status'       => 'open',
        'triggered_at' => null,
        'created_at'   => Carbon::parse('2026-07-17 11:45:00', 'UTC'),
    ], true);

    expect($alert->getActivitylogOptions()->logAttributes)->toBe(['*'])
        ->and($alert->acknowledgedBy()->getForeignKeyName())->toBe('acknowledged_by_uuid')
        ->and($alert->resolvedBy()->getForeignKeyName())->toBe('resolved_by_uuid')
        ->and($alert->subject()->getMorphType())->toBe('subject_type')
        ->and($alert->subject_name)->toBeNull()
        ->and($alert->duration_minutes)->toBeNull()
        ->and($alert->age_minutes)->toBe(15)
        ->and($alert->isSnoozed())->toBeFalse()
        ->and($alert->getSeverityLevel())->toBe(0)
        ->and((new Alert(['severity' => 'critical']))->getSeverityLevel())->toBe(4)
        ->and((new Alert(['severity' => 'medium']))->getSeverityLevel())->toBe(2)
        ->and((new Alert(['severity' => 'low']))->getSeverityLevel())->toBe(1);

    Carbon::setTestNow();
});

it('updates alerts through acknowledge resolve escalate snooze and rule helpers', function () {
    operational_models_database();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00', 'UTC'));
    session(['user' => 'session-user']);

    $user = new User();
    $user->setRawAttributes(['uuid' => 'user-1', 'name' => 'Ada'], true);

    $alert = new OperationalAlertSpy();
    $alert->setRawAttributes([
        'uuid'         => 'alert-1',
        'severity'     => 'medium',
        'status'       => 'open',
        'triggered_at' => Carbon::parse('2026-07-17 11:00:00', 'UTC'),
        'meta'         => ['existing' => true],
        'rule'         => ['metric' => 'temperature', 'operator' => '>', 'threshold' => 5],
    ], true);

    expect($alert->acknowledge($user))->toBeTrue()
        ->and($alert->updates[0]['acknowledged_at']->toISOString())->toBe('2026-07-17T12:00:00.000000Z')
        ->and($alert->updates[0]['acknowledged_by_uuid'])->toBe('user-1')
        ->and($alert->acknowledge($user))->toBeFalse()
        ->and($alert->resolve($user, 'Sensor recalibrated'))->toBeTrue()
        ->and($alert->updates[1]['status'])->toBe('resolved')
        ->and($alert->updates[1]['resolved_by_uuid'])->toBe('user-1')
        ->and($alert->updates[1]['meta']['resolution'])->toBe('Sensor recalibrated')
        ->and($alert->resolve($user))->toBeFalse()
        ->and($alert->matchesRule(['metric' => 'temperature']))->toBeTrue()
        ->and($alert->matchesRule(['metric' => 'humidity']))->toBeFalse();

    $escalatingAlert = new OperationalAlertSpy();
    $escalatingAlert->setRawAttributes([
        'uuid'         => 'alert-2',
        'severity'     => 'low',
        'status'       => 'open',
        'meta'         => [],
        'triggered_at' => Carbon::parse('2026-07-17 11:45:00', 'UTC'),
    ], true);

    expect($escalatingAlert->escalate('low'))->toBeFalse()
        ->and($escalatingAlert->escalate('medium', 'Crossed warning threshold'))->toBeTrue()
        ->and($escalatingAlert->updates[0]['severity'])->toBe('medium')
        ->and($escalatingAlert->updates[0]['meta']['escalation_history'][0]['from'])->toBe('low')
        ->and($escalatingAlert->updates[0]['meta']['escalation_history'][0]['to'])->toBe('medium')
        ->and($escalatingAlert->updates[0]['meta']['escalation_history'][0]['escalated_by'])->toBe('session-user')
        ->and($escalatingAlert->snooze(30, 'Awaiting technician'))->toBeTrue()
        ->and($escalatingAlert->updates[1]['meta']['snooze_reason'])->toBe('Awaiting technician')
        ->and($escalatingAlert->isSnoozed())->toBeTrue();

    Carbon::setTestNow();
});

it('preserves false update results for alert state mutations', function () {
    operational_models_database();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00', 'UTC'));

    $alert = new OperationalFailingAlertSpy();
    $alert->setRawAttributes([
        'uuid'     => 'alert-failing-update',
        'severity' => 'medium',
        'status'   => 'open',
        'meta'     => [],
    ], true);

    expect($alert->acknowledge())->toBeFalse()
        ->and($alert->resolve(null, 'Escalated to dispatch'))->toBeFalse()
        ->and($alert->escalate('critical'))->toBeFalse()
        ->and($alert->snooze(10))->toBeFalse()
        ->and($alert->updates)->toHaveCount(4);

    Carbon::setTestNow();
});

it('filters alerts through type severity status acknowledgement and priority scopes', function () {
    $capsule = operational_models_database();

    $capsule->getConnection('mysql')->table('alerts')->insert([
        [
            'uuid'            => 'alert-1',
            'type'            => 'temperature',
            'severity'        => 'critical',
            'status'          => 'open',
            'acknowledged_at' => null,
            'triggered_at'    => '2026-07-17 10:00:00',
            'created_at'      => '2026-07-17 10:00:00',
            'updated_at'      => '2026-07-17 10:00:00',
        ],
        [
            'uuid'            => 'alert-2',
            'type'            => 'temperature',
            'severity'        => 'medium',
            'status'          => 'resolved',
            'acknowledged_at' => '2026-07-17 10:30:00',
            'triggered_at'    => '2026-07-17 09:00:00',
            'created_at'      => '2026-07-17 09:00:00',
            'updated_at'      => '2026-07-17 09:00:00',
        ],
        [
            'uuid'            => 'alert-3',
            'type'            => 'battery',
            'severity'        => 'high',
            'status'          => 'open',
            'acknowledged_at' => null,
            'triggered_at'    => '2026-07-17 08:00:00',
            'created_at'      => '2026-07-17 08:00:00',
            'updated_at'      => '2026-07-17 08:00:00',
        ],
    ]);

    expect(Alert::query()->byType('temperature')->pluck('uuid')->all())->toBe(['alert-1', 'alert-2'])
        ->and(Alert::query()->bySeverity('critical')->pluck('uuid')->all())->toBe(['alert-1'])
        ->and(Alert::query()->byStatus('resolved')->pluck('uuid')->all())->toBe(['alert-2'])
        ->and(Alert::query()->open()->pluck('uuid')->all())->toBe(['alert-1', 'alert-3'])
        ->and(Alert::query()->acknowledged()->pluck('uuid')->all())->toBe(['alert-2'])
        ->and(Alert::query()->unacknowledged()->pluck('uuid')->all())->toBe(['alert-1', 'alert-3'])
        ->and(Alert::query()->critical()->pluck('uuid')->all())->toBe(['alert-1'])
        ->and(Alert::query()->highPriority()->pluck('uuid')->all())->toBe(['alert-1', 'alert-3']);
});

it('loads related alerts by subject type and alert type without returning itself', function () {
    $capsule = operational_models_database();

    $capsule->getConnection('mysql')->table('alerts')->insert([
        [
            'uuid'         => 'alert-current',
            'type'         => 'temperature',
            'severity'     => 'medium',
            'status'       => 'open',
            'subject_type' => 'vehicle',
            'subject_uuid' => 'vehicle-1',
            'triggered_at' => '2026-07-17 10:00:00',
            'created_at'   => '2026-07-17 10:00:00',
            'updated_at'   => '2026-07-17 10:00:00',
        ],
        [
            'uuid'         => 'alert-newest',
            'type'         => 'temperature',
            'severity'     => 'high',
            'status'       => 'open',
            'subject_type' => 'vehicle',
            'subject_uuid' => 'vehicle-1',
            'triggered_at' => '2026-07-17 11:00:00',
            'created_at'   => '2026-07-17 11:00:00',
            'updated_at'   => '2026-07-17 11:00:00',
        ],
        [
            'uuid'         => 'alert-older',
            'type'         => 'temperature',
            'severity'     => 'low',
            'status'       => 'open',
            'subject_type' => 'vehicle',
            'subject_uuid' => 'vehicle-1',
            'triggered_at' => '2026-07-17 09:00:00',
            'created_at'   => '2026-07-17 09:00:00',
            'updated_at'   => '2026-07-17 09:00:00',
        ],
        [
            'uuid'         => 'alert-other-type',
            'type'         => 'battery',
            'severity'     => 'critical',
            'status'       => 'open',
            'subject_type' => 'vehicle',
            'subject_uuid' => 'vehicle-1',
            'triggered_at' => '2026-07-17 12:00:00',
            'created_at'   => '2026-07-17 12:00:00',
            'updated_at'   => '2026-07-17 12:00:00',
        ],
    ]);

    $alert = Alert::query()->where('uuid', 'alert-current')->first();

    expect($alert->getRelatedAlerts(1)->pluck('uuid')->all())->toBe(['alert-newest']);
});

it('records alert notification attempts as successful activity events', function () {
    operational_models_database();

    $alert = new Alert();
    $alert->setRawAttributes([
        'uuid'     => 'alert-notification',
        'severity' => 'critical',
        'type'     => 'temperature',
        'status'   => 'open',
    ], true);

    expect($alert->createNotification(['ops@example.test']))->toBeTrue();
});

it('report audit log scopes constrain execution and export actions', function () {
    $auditLog   = new ReportAuditLog();
    $actions    = new OperationalScopeBuilderFake();
    $executions = new OperationalScopeBuilderFake();
    $exports    = new OperationalScopeBuilderFake();

    expect($auditLog->scopeAction($actions, 'preview'))->toBe($actions)
        ->and($auditLog->scopeExecutions($executions))->toBe($executions)
        ->and($auditLog->scopeExports($exports))->toBe($exports)
        ->and($auditLog->report()->getForeignKeyName())->toBe('report_uuid')
        ->and($auditLog->user()->getForeignKeyName())->toBe('user_uuid')
        ->and($actions->wheres)->toBe([['action', 'preview']])
        ->and($executions->wheres)->toBe([['action', 'execute']])
        ->and($exports->wheres)->toBe([['action', 'export']]);
});

it('marks notifications as read and delegates delete notification calls', function () {
    bind_test_container();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00', 'UTC'));

    $notification = new OperationalNotificationSpy();

    expect($notification->markAsRead(false))->toBe($notification)
        ->and($notification->read_at->toISOString())->toBe('2026-07-17T12:00:00.000000Z')
        ->and($notification->saves)->toBe(0)
        ->and($notification->markAsRead())->toBe($notification)
        ->and($notification->saves)->toBe(1);

    $notification->deleteNotification();

    expect($notification->deletes)->toBe(1);

    Carbon::setTestNow();
});

it('publishes comments with session defaults sanitized payloads and timestamps', function () {
    operational_models_database();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00', 'UTC'));
    session([
        'user'    => 'user-1',
        'company' => 'company-1',
    ]);

    $comment = Comment::publish([
        'uuid'         => 'comment-1',
        'content'      => 'Driver reached pickup.',
        'subject_uuid' => 'order-1',
        'subject_type' => User::class,
        'replies'      => ['should' => 'drop'],
        'parent'       => ['should' => 'drop'],
    ]);

    expect($comment)->toBeInstanceOf(Comment::class)
        ->and($comment->author_uuid)->toBe('user-1')
        ->and($comment->company_uuid)->toBe('company-1')
        ->and($comment->content)->toBe('Driver reached pickup.')
        ->and($comment->getAttributes())->not->toHaveKey('replies')
        ->and($comment->getAttributes())->not->toHaveKey('parent')
        ->and($comment->created_at->toISOString())->toBe('2026-07-17T12:00:00.000000Z')
        ->and(Comment::query()->whereKey('comment-1')->exists())->toBeTrue();

    Carbon::setTestNow();
});

it('defines comment company parent and replies relationship contracts', function () {
    operational_models_database();

    $comment = new Comment();
    $company = $comment->company();
    $parent  = $comment->parent();
    $replies = $comment->replies();

    expect($company)->toBeInstanceOf(BelongsTo::class)
        ->and($company->getRelated())->toBeInstanceOf(Company::class)
        ->and($company->getForeignKeyName())->toBe('company_uuid')
        ->and($company->getOwnerKeyName())->toBe('uuid')
        ->and($parent)->toBeInstanceOf(BelongsTo::class)
        ->and($parent->getRelated())->toBeInstanceOf(Comment::class)
        ->and($parent->getForeignKeyName())->toBe('parent_uuid')
        ->and($parent->getOwnerKeyName())->toBe('uuid')
        ->and($replies)->toBeInstanceOf(HasMany::class)
        ->and($replies->getRelated())->toBeInstanceOf(Comment::class)
        ->and($replies->getForeignKeyName())->toBe('parent_comment_uuid')
        ->and($replies->getLocalKeyName())->toBe('uuid');
});

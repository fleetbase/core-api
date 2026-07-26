<?php

use Fleetbase\Expansions\Str as StrExpansion;
use Fleetbase\Http\Controllers\Api\v1\CommentController;
use Fleetbase\Http\Requests\CreateCommentRequest;
use Fleetbase\Http\Requests\UpdateCommentRequest;
use Fleetbase\Models\Comment;
use Fleetbase\Models\User;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str as SupportStr;

class CommentControllerContainer extends FleetbaseTestContainer
{
    public function hasDebugModeEnabled(): bool
    {
        return true;
    }
}

class CommentControllerTaggedCacheFake
{
    public function tags(array|string $tags): self
    {
        return $this;
    }

    public function flush(): bool
    {
        return true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        return true;
    }

    public function delete(string $key): bool
    {
        return true;
    }

    public function rememberForever(string $key, Closure $callback): mixed
    {
        return $callback();
    }
}

class CommentControllerResponseCacheFake
{
    public function clear(): void
    {
    }
}

class CommentControllerRoute
{
    public object $controller;

    public function __construct(private string $method = 'query')
    {
        $this->controller = new class {
        };
    }

    public function getAction(?string $key = null): mixed
    {
        $action = [
            'controller' => CommentController::class . '@' . $this->method,
        ];

        return $key ? $action[$key] ?? null : $action;
    }

    public function getActionMethod(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return 'v1/comments';
    }
}

function comment_controller_database(): Capsule
{
    EloquentModel::clearBootedModels();
    Container::setInstance(new CommentControllerContainer());
    $_SERVER['REQUEST_METHOD'] = 'GET';

    if (!SupportStr::hasMacro('humanize')) {
        $strExpansion = new StrExpansion();
        SupportStr::macro('humanize', $strExpansion->humanize());
    }

    if (!Request::hasMacro('array')) {
        Request::macro('array', function (string $key, array $default = []): array {
            $value = $this->input($key, $default);

            return is_array($value) ? $value : $default;
        });
    }
    if (!Request::hasMacro('or')) {
        Request::macro('or', function (array $params = [], mixed $default = null): mixed {
            foreach ($params as $param) {
                if ($this->has($param)) {
                    return $this->input($param);
                }
            }

            return $default;
        });
    }
    Request::macro('getController', function () {
        return $this->route()?->controller;
    });

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

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    $container->instance('responsecache', new CommentControllerResponseCacheFake());
    Cache::swap(new CommentControllerTaggedCacheFake());
    Facade::clearResolvedInstance('db');
    Facade::clearResolvedInstance('schema');

    session()->flush();
    session([
        'company' => 'company-1',
        'user'    => 'user-author',
    ]);

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $container->instance('db.schema', $schema);
    Facade::clearResolvedInstance('db.schema');

    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->index();
        $table->string('company_uuid')->nullable()->index();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('country')->nullable();
        $table->string('timezone')->nullable();
        $table->boolean('is_admin')->default(false);
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('comments', function ($table) {
        $table->increments('id');
        $table->string('uuid')->unique();
        $table->string('public_id')->nullable()->index();
        $table->string('company_uuid')->nullable()->index();
        $table->string('subject_uuid')->nullable()->index();
        $table->string('subject_type')->nullable();
        $table->string('author_id')->nullable();
        $table->string('author_uuid')->nullable();
        $table->string('parent_id')->nullable();
        $table->string('parent_comment_uuid')->nullable()->index();
        $table->text('content')->nullable();
        $table->text('tags')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('directives', function ($table) {
        $table->string('uuid')->primary();
        $table->string('permission_uuid')->nullable()->index();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('key')->nullable();
        $table->string('operator')->nullable();
        $table->string('value')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $now = '2026-07-18 00:00:00';
    $capsule->getConnection('mysql')->table('users')->insert([
        ['uuid' => 'user-author', 'public_id' => 'user_author', 'company_uuid' => 'company-1', 'name' => 'Author User', 'email' => 'author@example.test', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'user-subject', 'public_id' => 'user_subject', 'company_uuid' => 'company-1', 'name' => 'Subject User', 'email' => 'subject@example.test', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'user-foreign', 'public_id' => 'user_foreign', 'company_uuid' => 'company-2', 'name' => 'Foreign User', 'email' => 'foreign@example.test', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $capsule->getConnection('mysql')->table('comments')->insert([
        ['id' => 1, 'uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'comment_root', 'company_uuid' => 'company-1', 'subject_uuid' => 'user-subject', 'subject_type' => '\\' . User::class, 'author_uuid' => 'user-author', 'content' => 'Root comment', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 2, 'uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'comment_foreign', 'company_uuid' => 'company-2', 'subject_uuid' => 'user-foreign', 'subject_type' => '\\' . User::class, 'author_uuid' => 'user-foreign', 'content' => 'Foreign comment', 'created_at' => $now, 'updated_at' => $now],
    ]);

    return $capsule;
}

function comment_controller(): CommentController
{
    return new CommentController();
}

function comment_controller_create_request(array $input): CreateCommentRequest
{
    return CreateCommentRequest::create('/v1/comments', 'POST', $input);
}

function comment_controller_update_request(array $input): UpdateCommentRequest
{
    return UpdateCommentRequest::create('/v1/comments/comment_root', 'PUT', $input);
}

function comment_controller_query_request(array $query = []): Request
{
    $request = Request::create('/v1/comments', 'GET', $query);
    $request->setRouteResolver(fn () => new CommentControllerRoute());

    return $request;
}

function comment_controller_payload($resource): array
{
    return $resource->resolve(Request::create('/v1/comments', 'GET'));
}

afterEach(function () {
    session()->flush();
    config([
        'api.cache.enabled'       => null,
        'database.default'        => null,
        'database.connections'    => [],
        'fleetbase.connection.db' => null,
    ]);
    EloquentModel::clearBootedModels();
    EloquentModel::reguard();
    Container::setInstance(new FleetbaseTestContainer());
    Facade::clearResolvedInstances();
});

test('public comment controller creates comments from resolved subject and rejects invalid subjects', function () {
    $capsule = comment_controller_database();

    $created = comment_controller()->create(comment_controller_create_request([
        'subject' => [
            'id'   => 'user_subject',
            'type' => 'user',
        ],
        'content' => 'Arrived at the dock.',
    ]));
    $invalid = comment_controller()->create(comment_controller_create_request([
        'subject_id'   => 'missing_user',
        'subject_type' => 'user',
        'content'      => 'Cannot attach',
    ]));
    $missingSubject = comment_controller()->create(comment_controller_create_request([
        'content' => 'Cannot attach without subject',
    ]));

    $record = $capsule->getConnection('mysql')->table('comments')->where('content', 'Arrived at the dock.')->first();

    expect($created->resource)->toBeInstanceOf(Comment::class)
        ->and($created->resource->company_uuid)->toBe('company-1')
        ->and($created->resource->author_uuid)->toBe('user-author')
        ->and($created->resource->subject_uuid)->toBe('user-subject')
        ->and(ltrim($created->resource->subject_type, '\\'))->toBe(User::class)
        ->and(comment_controller_payload($created)['content'])->toBe('Arrived at the dock.')
        ->and($record->subject_uuid)->toBe('user-subject')
        ->and($invalid->getStatusCode())->toBe(400)
        ->and($invalid->getData(true))->toBe(['error' => 'Invalid subject provided for comment.'])
        ->and($missingSubject->getStatusCode())->toBe(400)
        ->and($missingSubject->getData(true))->toBe(['error' => 'Invalid subject provided for comment.']);
});

test('public comment controller replies inherit parent subject and stay in active company', function () {
    comment_controller_database();

    $reply = comment_controller()->create(comment_controller_create_request([
        'parent'  => 'comment_root',
        'content' => 'Reply from dispatcher',
    ]));
    $foreignParent = comment_controller()->create(comment_controller_create_request([
        'parent'  => 'comment_foreign',
        'content' => 'Cannot reply cross tenant',
    ]));

    expect($reply->resource->parent_comment_uuid)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($reply->resource->subject_uuid)->toBe('user-subject')
        ->and(ltrim($reply->resource->subject_type, '\\'))->toBe(User::class)
        ->and($reply->resource->company_uuid)->toBe('company-1')
        ->and($foreignParent->getStatusCode())->toBe(400)
        ->and($foreignParent->getData(true))->toBe(['error' => 'Invalid subject provided for comment.']);
});

test('public comment controller updates finds deletes and reports tenant scoped missing records', function () {
    comment_controller_database();

    $updated = comment_controller()->update('comment_root', comment_controller_update_request([
        'content' => 'Updated root comment',
    ]));
    $found         = comment_controller()->find('comment_root');
    $deleted       = comment_controller()->delete('comment_root');
    $missing       = comment_controller()->find('comment_root');
    $foreign       = comment_controller()->find('comment_foreign');
    $missingUpdate = comment_controller()->update('comment_root', comment_controller_update_request([
        'content' => 'No longer available',
    ]));
    $missingDelete = comment_controller()->delete('comment_root');

    expect($updated->resource->content)->toBe('Updated root comment')
        ->and(comment_controller_payload($found)['content'])->toBe('Updated root comment')
        ->and($deleted->resource->public_id)->toBe('comment_root')
        ->and(Comment::withTrashed()->whereKey('11111111-1111-4111-8111-111111111111')->first()->trashed())->toBeTrue()
        ->and($missing->getStatusCode())->toBe(404)
        ->and($missing->getData(true))->toBe(['error' => 'Comment resource not found.'])
        ->and($foreign->getStatusCode())->toBe(404)
        ->and($foreign->getData(true))->toBe(['error' => 'Comment resource not found.'])
        ->and($missingUpdate->getStatusCode())->toBe(404)
        ->and($missingUpdate->getData(true))->toBe(['error' => 'Comment resource not found.'])
        ->and($missingDelete->getStatusCode())->toBe(404)
        ->and($missingDelete->getData(true))->toBe(['error' => 'Comment resource not found.']);
});

test('public comment controller returns stable errors when comment creation fails', function () {
    comment_controller_database();

    try {
        Comment::creating(function () {
            throw new RuntimeException('comment creation failed');
        });

        $response = comment_controller()->create(comment_controller_create_request([
            'subject' => [
                'id'   => 'user_subject',
                'type' => 'user',
            ],
            'content' => 'Cannot persist',
        ]));
    } finally {
        EloquentModel::reguard();
        Comment::flushEventListeners();
        EloquentModel::clearBootedModels();
    }

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe(['error' => 'Uknown error attempting to create comment.']);
});

test('public comment controller returns stable errors when updates fail', function () {
    comment_controller_database();

    try {
        Comment::updating(function () {
            throw new RuntimeException('comment update failed');
        });

        $response = comment_controller()->update('comment_root', comment_controller_update_request([
            'content' => 'Cannot update',
        ]));
    } finally {
        EloquentModel::reguard();
        Comment::flushEventListeners();
        EloquentModel::clearBootedModels();
    }

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe(['error' => 'Uknown error attempting to update comment.']);
});

test('public comment controller returns stable errors when find or delete lookups fail unexpectedly', function () {
    $capsule = comment_controller_database();
    $capsule->getConnection('mysql')->getSchemaBuilder()->drop('comments');

    $findFailure   = comment_controller()->find('comment_root');
    $deleteFailure = comment_controller()->delete('comment_root');

    expect($findFailure->getStatusCode())->toBe(404)
        ->and($findFailure->getData(true))->toBe(['error' => 'Uknown error occured trying to find the comment.'])
        ->and($deleteFailure->getStatusCode())->toBe(404)
        ->and($deleteFailure->getData(true))->toBe(['error' => 'Uknown error occured trying to find the comment.']);
});

test('public comment controller query applies subject parent and active company filters', function () {
    $capsule = comment_controller_database();
    $now     = '2026-07-18 00:05:00';
    $capsule->getConnection('mysql')->table('comments')->insert([
        ['id' => 3, 'uuid' => '33333333-3333-4333-8333-333333333333', 'public_id' => 'comment_reply', 'company_uuid' => 'company-1', 'subject_uuid' => 'user-subject', 'subject_type' => '\\' . User::class, 'author_uuid' => 'user-author', 'parent_comment_uuid' => '11111111-1111-4111-8111-111111111111', 'content' => 'Reply', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 4, 'uuid' => '44444444-4444-4444-8444-444444444444', 'public_id' => 'comment_other_subject', 'company_uuid' => 'company-1', 'subject_uuid' => 'user-author', 'subject_type' => '\\' . User::class, 'author_uuid' => 'user-author', 'parent_comment_uuid' => null, 'content' => 'Other subject', 'created_at' => $now, 'updated_at' => $now],
    ]);

    $allActiveCompany = comment_controller()->query(comment_controller_query_request(['limit' => -1]));
    $subjectScoped    = comment_controller()->query(comment_controller_query_request([
        'limit'        => -1,
        'subject_uuid' => 'user-subject',
        'subject_type' => 'user',
    ]));
    $withoutParent = comment_controller()->query(comment_controller_query_request([
        'limit'          => -1,
        'without_parent' => '1',
    ]));
    $parentScoped = comment_controller()->query(comment_controller_query_request([
        'limit'  => -1,
        'parent' => '11111111-1111-4111-8111-111111111111',
    ]));

    expect($allActiveCompany->collection->pluck('public_id')->all())->toEqualCanonicalizing(['comment_root', 'comment_reply', 'comment_other_subject'])
        ->and($allActiveCompany->collection->pluck('public_id')->all())->not->toContain('comment_foreign')
        ->and($subjectScoped->collection->pluck('public_id')->all())->toEqualCanonicalizing(['comment_root', 'comment_reply'])
        ->and($withoutParent->collection->pluck('public_id')->all())->toEqualCanonicalizing(['comment_root', 'comment_other_subject'])
        ->and($parentScoped->collection->pluck('public_id')->all())->toBe(['comment_reply']);
});

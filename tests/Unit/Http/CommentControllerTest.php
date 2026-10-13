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

class CommentVisibilityRoute
{
    public object $controller;

    public function __construct(private string $uri = 'int/v1/comments', private string $method = 'create')
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
        return $this->uri;
    }
}

function comment_visibility_database(): Capsule
{
    $capsule = comment_controller_database();
    $capsule->getConnection('mysql')->getSchemaBuilder()->create('orders', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->index();
        $table->string('company_uuid')->nullable()->index();
        $table->timestamps();
    });
    $capsule->getConnection('mysql')->table('orders')->insert([
        ['uuid' => 'order-uuid-1', 'public_id' => 'order_1234567', 'company_uuid' => 'company-1'],
    ]);

    return $capsule;
}

function comment_visibility_use_route(string $uri): void
{
    $request = Request::create('/' . $uri, 'POST');
    $request->setRouteResolver(fn () => new CommentVisibilityRoute($uri));
    Container::getInstance()->instance('request', $request);
}

function comment_visibility_publish(array $attributes = []): Comment
{
    return Comment::publish(array_merge([
        'subject_uuid' => 'order-uuid-1',
        'subject_type' => Fleetbase\Tests\Fixtures\Models\Order::class,
        'content'      => 'Leave at the side door.',
    ], $attributes));
}

test('comments on orders default to internal from the console and customer from everywhere else', function () {
    comment_visibility_database();

    comment_visibility_use_route('int/v1/comments');
    $console = comment_visibility_publish();

    comment_visibility_use_route('storefront/int/v1/orders/order_1234567/notes');
    $storefrontConsole = comment_visibility_publish();

    comment_visibility_use_route('v1/comments');
    $publicApi = comment_visibility_publish();

    comment_visibility_use_route('int/v1/comments');
    $explicit = comment_visibility_publish(['visibility' => 'customer']);
    $viaMeta  = comment_visibility_publish(['meta' => ['visibility' => 'customer', 'pinned' => true]]);
    $invalid  = comment_visibility_publish(['meta' => ['visibility' => 'everyone']]);

    expect(Comment::find($console->uuid)->meta)->toBe(['visibility' => 'internal'])
        ->and($console->isInternal())->toBeTrue()
        ->and($storefrontConsole->getVisibility())->toBe('internal')
        ->and(Comment::find($publicApi->uuid)->meta)->toBe(['visibility' => 'customer'])
        ->and($publicApi->isVisibleToCustomers())->toBeTrue()
        ->and(Comment::find($explicit->uuid)->meta)->toBe(['visibility' => 'customer'])
        ->and(Comment::find($viaMeta->uuid)->meta)->toBe(['visibility' => 'customer', 'pinned' => true])
        ->and(Comment::find($invalid->uuid)->meta)->toBe(['visibility' => 'internal']);
});

test('comments on other subjects keep no visibility unless given and replies follow their parent', function () {
    $capsule = comment_visibility_database();

    comment_visibility_use_route('int/v1/comments');
    $userComment = Comment::publish([
        'subject_uuid' => 'user-subject',
        'subject_type' => User::class,
        'content'      => 'Note on a user',
    ]);
    $internalRoot = comment_visibility_publish();
    $reply        = comment_visibility_publish(['parent_comment_uuid' => $internalRoot->uuid]);

    comment_visibility_use_route('v1/comments');
    $publicReplyToInternal = comment_visibility_publish(['parent_comment_uuid' => $internalRoot->uuid]);

    expect($capsule->getConnection('mysql')->table('comments')->where('uuid', $userComment->uuid)->value('meta'))->toBeNull()
        ->and($userComment->getVisibility())->toBe('customer')
        ->and($reply->getVisibility())->toBe('internal')
        ->and($publicReplyToInternal->getVisibility())->toBe('internal');
});

test('visible to customers scope excludes only internal comments', function () {
    $capsule = comment_visibility_database();
    $now     = '2026-07-18 00:10:00';
    $capsule->getConnection('mysql')->table('comments')->insert([
        ['id' => 10, 'uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'public_id' => 'comment_internal', 'company_uuid' => 'company-1', 'subject_uuid' => 'order-uuid-1', 'subject_type' => Fleetbase\Tests\Fixtures\Models\Order::class, 'author_uuid' => 'user-author', 'content' => 'Internal', 'meta' => '{"visibility":"internal"}', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 11, 'uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'public_id' => 'comment_customer', 'company_uuid' => 'company-1', 'subject_uuid' => 'order-uuid-1', 'subject_type' => Fleetbase\Tests\Fixtures\Models\Order::class, 'author_uuid' => 'user-author', 'content' => 'Customer', 'meta' => '{"visibility":"customer"}', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 12, 'uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'public_id' => 'comment_other_meta', 'company_uuid' => 'company-1', 'subject_uuid' => 'order-uuid-1', 'subject_type' => Fleetbase\Tests\Fixtures\Models\Order::class, 'author_uuid' => 'user-author', 'content' => 'Other meta', 'meta' => '{"pinned":true}', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 13, 'uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'public_id' => 'comment_json_null', 'company_uuid' => 'company-1', 'subject_uuid' => 'order-uuid-1', 'subject_type' => Fleetbase\Tests\Fixtures\Models\Order::class, 'author_uuid' => 'user-author', 'content' => 'Json null', 'meta' => 'null', 'created_at' => $now, 'updated_at' => $now],
    ]);

    $visible  = Comment::query()->without(['author', 'replies'])->visibleToCustomers()->pluck('public_id')->all();
    $internal = Comment::query()->without(['author', 'replies'])->internal()->pluck('public_id')->all();

    expect($visible)->toEqualCanonicalizing(['comment_root', 'comment_foreign', 'comment_customer', 'comment_other_meta', 'comment_json_null'])
        ->and($internal)->toBe(['comment_internal']);
});

test('public comment api hides internal comments and internal replies and accepts a visibility', function () {
    $capsule = comment_visibility_database();
    $now     = '2026-07-18 00:10:00';
    $capsule->getConnection('mysql')->table('comments')->insert([
        ['id' => 10, 'uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'public_id' => 'comment_internal', 'company_uuid' => 'company-1', 'subject_uuid' => 'user-subject', 'subject_type' => '\\' . User::class, 'author_uuid' => 'user-author', 'parent_comment_uuid' => null, 'content' => 'Internal', 'meta' => '{"visibility":"internal"}', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 11, 'uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'public_id' => 'comment_internal_reply', 'company_uuid' => 'company-1', 'subject_uuid' => 'user-subject', 'subject_type' => '\\' . User::class, 'author_uuid' => 'user-author', 'parent_comment_uuid' => '11111111-1111-4111-8111-111111111111', 'content' => 'Internal reply', 'meta' => '{"visibility":"internal"}', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 12, 'uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'public_id' => 'comment_customer_reply', 'company_uuid' => 'company-1', 'subject_uuid' => 'user-subject', 'subject_type' => '\\' . User::class, 'author_uuid' => 'user-author', 'parent_comment_uuid' => '11111111-1111-4111-8111-111111111111', 'content' => 'Customer reply', 'meta' => '{"visibility":"customer"}', 'created_at' => $now, 'updated_at' => $now],
    ]);

    $listed = comment_controller()->query(comment_controller_query_request(['limit' => -1, 'without_parent' => '1']));
    $hidden = comment_controller()->find('comment_internal');

    // resources resolve replies against the current request: a public route hides internal replies
    comment_visibility_use_route('v1/comments');
    $root          = collect($listed->collection)->firstWhere('public_id', 'comment_root');
    $publicReplies = collect((new Fleetbase\Http\Resources\Comment($root->resource))->resolve(request())['replies'])->map(fn ($reply) => $reply->resource->public_id)->all();

    comment_visibility_use_route('int/v1/comments');
    $internalReplies = collect((new Fleetbase\Http\Resources\Comment($root->resource))->resolve(request())['replies'])->map(fn ($reply) => $reply->resource->public_id)->all();

    $created = comment_controller()->create(comment_controller_create_request([
        'subject'    => ['id' => 'user_subject', 'type' => 'user'],
        'content'    => 'Only for the team',
        'visibility' => 'internal',
    ]));

    expect($listed->collection->pluck('public_id')->all())->not->toContain('comment_internal')
        ->and($listed->collection->pluck('public_id')->all())->toContain('comment_root')
        ->and($hidden->getStatusCode())->toBe(404)
        ->and($publicReplies)->toBe(['comment_customer_reply'])
        ->and($internalReplies)->toEqualCanonicalizing(['comment_customer_reply', 'comment_internal_reply'])
        ->and($created->resource->getVisibility())->toBe('internal')
        ->and((new CreateCommentRequest())->rules()['visibility'])->toContain('nullable');
});

test('comment created broadcasts internal notes to the company only and customer comments to the subject too', function () {
    comment_visibility_database();

    comment_visibility_use_route('int/v1/comments');
    $internal = comment_visibility_publish();

    comment_visibility_use_route('v1/comments');
    $customer = comment_visibility_publish();
    $unknown  = Comment::publish(['subject_uuid' => 'missing-order', 'subject_type' => Fleetbase\Tests\Fixtures\Models\Order::class, 'content' => 'Lost', 'visibility' => 'customer']);

    $channels = fn (Comment $comment) => array_map(fn ($channel) => (string) $channel, (new Fleetbase\Events\CommentCreated($comment))->broadcastOn());

    $event   = new Fleetbase\Events\CommentCreated($customer);
    $payload = $event->broadcastWith();

    expect($event->broadcastAs())->toBe('comment.created')
        ->and($channels($internal))->toBe(['company.company-1'])
        ->and($channels($customer))->toBe(['company.company-1', 'order.order_1234567', 'order.order-uuid-1'])
        ->and($channels($unknown))->toBe(['company.company-1'])
        ->and($payload['event'])->toBe('comment.created')
        ->and($payload['subject_id'])->toBe('order_1234567')
        ->and($payload['data']['content'])->toBe('Leave at the side door.')
        ->and($payload['data']['visibility'])->toBe('customer');
});

test('creating a comment dispatches comment created and never fails when broadcasting does', function () {
    comment_visibility_database();

    $dispatched = new ArrayObject();
    Container::getInstance()->instance('events', new class($dispatched) {
        public function __construct(private ArrayObject $dispatched)
        {
        }

        public function dispatch($event, $payload = [], $halt = false)
        {
            $this->dispatched->append($event);

            return null;
        }
    });
    Facade::clearResolvedInstance('events');

    comment_visibility_use_route('v1/comments');
    $comment = comment_visibility_publish();

    Container::getInstance()->instance('events', new class {
        public function dispatch($event, $payload = [], $halt = false)
        {
            throw new RuntimeException('socket server unavailable');
        }
    });
    Facade::clearResolvedInstance('events');
    $stillCreated = comment_visibility_publish(['content' => 'Second note']);

    expect($dispatched)->toHaveCount(1)
        ->and($dispatched[0])->toBeInstanceOf(Fleetbase\Events\CommentCreated::class)
        ->and($dispatched[0]->comment->is($comment))->toBeTrue()
        ->and($stillCreated->exists)->toBeTrue();
});

test('comment created broadcasts to the subject only when the subject can be resolved', function () {
    comment_visibility_database();

    $channels = fn (Comment $comment) => array_map(fn ($channel) => (string) $channel, (new Fleetbase\Events\CommentCreated($comment))->broadcastOn());
    $comment  = function (array $attributes): Comment {
        $comment = new Comment();
        $comment->setRawAttributes(array_merge(['uuid' => 'comment-x', 'company_uuid' => 'company-1'], $attributes), true);
        $comment->meta = ['visibility' => 'customer'];

        return $comment;
    };

    $bare   = $comment(['subject_type' => null, 'subject_uuid' => null]);
    $loaded = $comment(['subject_type' => Fleetbase\Tests\Fixtures\Models\Order::class, 'subject_uuid' => 'order-uuid-9']);
    $loaded->setRelation('subject', new Fleetbase\Tests\Fixtures\Models\Order(['uuid' => 'order-uuid-9', 'public_id' => 'order_9']));
    $missing = $comment(['subject_type' => 'Fleetbase\\Missing\\Model', 'subject_uuid' => 'order-uuid-1']);
    $broken  = $comment(['subject_type' => stdClass::class, 'subject_uuid' => 'order-uuid-1']);

    expect($channels($bare))->toBe(['company.company-1'])
        ->and($channels($loaded))->toBe(['company.company-1', 'order.order_9', 'order.order-uuid-9'])
        ->and($channels($missing))->toBe(['company.company-1'])
        ->and($channels($broken))->toBe(['company.company-1'])
        ->and(Comment::isVisibilityAwareSubjectType(null))->toBeFalse()
        ->and(Comment::isVisibilityAwareSubjectType('order'))->toBeTrue()
        ->and(Comment::isVisibilityAwareSubjectType(Fleetbase\Tests\Fixtures\Models\Order::class))->toBeTrue()
        ->and(Comment::isVisibilityAwareSubjectType('contact'))->toBeFalse();
});

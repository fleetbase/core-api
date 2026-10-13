<?php

use Fleetbase\Support\RequestRelations;
use Fleetbase\Traits\Expandable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RequestRelationsParent extends EloquentModel
{
    use Expandable;

    public static int $calls = 0;

    protected $table = 'request_relations_parents';

    protected $withable = ['legacyChildren'];

    public function children(): HasMany
    {
        return $this->hasMany(RequestRelationsChild::class, 'parent_id');
    }

    public function nullableOwner(): ?BelongsTo
    {
        return $this->belongsTo(RequestRelationsChild::class, 'owner_id');
    }

    public function scopedOwner(): BelongsTo|Builder
    {
        return $this->belongsTo(RequestRelationsChild::class, 'owner_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function brokenChildren(): HasMany
    {
        throw new RuntimeException('relation cannot be built on a blank model');
    }

    public function untypedChildren()
    {
        return $this->hasMany(RequestRelationsChild::class, 'parent_id');
    }

    public function legacyChildren()
    {
        return $this->hasMany(RequestRelationsChild::class, 'parent_id');
    }

    public function dispatch(bool $save = true)
    {
        static::$calls++;

        return $this;
    }

    public function typedChildrenFor(string $type): HasMany
    {
        return $this->hasMany(RequestRelationsChild::class, 'parent_id')->where('type', $type);
    }

    public static function staticChildren(): HasMany
    {
        return (new static())->hasMany(RequestRelationsChild::class, 'parent_id');
    }

    protected function hiddenChildren(): HasMany
    {
        return $this->hasMany(RequestRelationsChild::class, 'parent_id');
    }
}

class RequestRelationsChild extends EloquentModel
{
    public static int $calls = 0;

    protected $table = 'request_relations_children';

    public function parent(): BelongsTo
    {
        return $this->belongsTo(RequestRelationsParent::class, 'parent_id');
    }

    public function untypedParent()
    {
        return $this->belongsTo(RequestRelationsParent::class, 'parent_id');
    }

    public function cancel()
    {
        static::$calls++;

        return $this;
    }
}

class RequestRelationsExpansion
{
    public static function target(): string
    {
        return RequestRelationsParent::class;
    }

    public static function expandedChildren()
    {
        return function (): HasMany {
            /* @var RequestRelationsParent $this */
            return $this->hasMany(RequestRelationsChild::class, 'parent_id');
        };
    }

    public static function untypedExpandedChildren()
    {
        return function () {
            /* @var RequestRelationsParent $this */
            return $this->hasMany(RequestRelationsChild::class, 'parent_id');
        };
    }
}

beforeEach(function () {
    bind_test_container();

    $capsule = new Capsule(app());
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    RequestRelationsParent::expand(new RequestRelationsExpansion());
    RequestRelationsParent::$calls = 0;
    RequestRelationsChild::$calls  = 0;
});

afterEach(function () {
    EloquentModel::unsetConnectionResolver();
    EloquentModel::clearBootedModels();
});

test('request relations accept methods that declare a relation return type', function () {
    $model = new RequestRelationsParent();

    expect(RequestRelations::eagerLoads($model, ['children', 'nullable_owner', 'scopedOwner', 'subject']))
        ->toBe(['children', 'nullableOwner', 'scopedOwner', 'subject'])
        ->and(RequestRelations::eagerLoads($model, 'children, nullable_owner'))->toBe(['children', 'nullableOwner'])
        ->and(RequestRelations::eagerLoads($model, ['nullableowner', 'legacychildren']))->toBe(['nullableOwner', 'legacyChildren'])
        ->and(RequestRelations::counts($model, ['children', 'scoped_owner']))->toBe(['children', 'scopedOwner']);
});

test('request relations drop untyped methods unless the model lists them as withable', function () {
    $model = new RequestRelationsParent();

    expect(RequestRelations::eagerLoads($model, ['untypedChildren', 'legacy_children']))->toBe(['legacyChildren'])
        ->and(RequestRelations::counts($model, ['untypedChildren', 'legacyChildren']))->toBe(['legacyChildren']);
});

test('request relations never call a method that is not a relation', function () {
    $model     = new RequestRelationsParent();
    $requested = ['dispatch', 'save', 'push', 'touch', 'delete', 'fresh', 'replicate', 'toArray', 'getKey', 'children.cancel', 'children.parent.dispatch'];

    expect(RequestRelations::eagerLoads($model, $requested))->toBe(['children', 'children.parent'])
        ->and(RequestRelations::counts($model, $requested))->toBe([])
        ->and(RequestRelationsParent::$calls)->toBe(0)
        ->and(RequestRelationsChild::$calls)->toBe(0);
});

test('request relations drop static protected and argument taking methods', function () {
    expect(RequestRelations::eagerLoads(new RequestRelationsParent(), ['staticChildren', 'hiddenChildren', 'typedChildrenFor']))->toBe([]);
});

test('request relations drop a typed relation that cannot be built instead of failing the request', function () {
    expect(RequestRelations::eagerLoads(new RequestRelationsParent(), ['brokenChildren', 'children']))->toBe(['children'])
        ->and(RequestRelations::counts(new RequestRelationsParent(), ['brokenChildren']))->toBe([]);
});

test('request relations check each nested segment against the related model', function () {
    $model = new RequestRelationsParent();

    expect(RequestRelations::eagerLoads($model, ['children.parent.children', 'children.untyped_parent', 'children.missing.parent']))
        ->toBe(['children.parent.children', 'children'])
        ->and(RequestRelations::eagerLoads($model, ['children.parent.children.parent.children.parent.children']))
        ->toBe(['children.parent.children.parent.children'])
        ->and(RequestRelations::counts($model, ['children.parent']))->toBe([]);
});

test('request relations stop a path at a morph to and never count one', function () {
    $model = new RequestRelationsParent();

    expect(RequestRelations::eagerLoads($model, ['subject.dispatch', 'subject.children']))->toBe(['subject'])
        ->and(RequestRelations::counts($model, ['subject']))->toBe([]);
});

test('request relations accept expansion closures by the same rule', function () {
    $model = new RequestRelationsParent();

    expect(RequestRelations::eagerLoads($model, ['expandedChildren', 'untypedExpandedChildren', 'expanded_children.parent']))
        ->toBe(['expandedChildren', 'expandedChildren.parent']);
});

test('request relations ignore malformed input and cap the number of paths', function () {
    $model = new RequestRelationsParent();

    expect(RequestRelations::eagerLoads($model, ['children:id,name', 'children items', '', ' ', ['children'], 42, null, 'children..parent']))
        ->toBe(['children'])
        ->and(RequestRelations::eagerLoads($model, null))->toBe([])
        ->and(RequestRelations::eagerLoads($model, 42))->toBe([])
        ->and(RequestRelations::eagerLoads($model, array_merge(array_map(fn ($i) => "missing{$i}", range(1, RequestRelations::MAX_PATHS)), ['children'])))->toBe([]);
});

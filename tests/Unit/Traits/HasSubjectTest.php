<?php

use Fleetbase\Models\User;
use Fleetbase\Traits\HasSubject;
use Illuminate\Database\Eloquent\Model;

class HasSubjectTestModel extends Model
{
    use HasSubject;

    protected $guarded = [];

    public int $saves = 0;

    public function save(array $options = []): bool
    {
        $this->saves++;

        return true;
    }
}

test('has subject assigns morph columns and optionally persists the owner', function () {
    $subject = new User(['uuid' => 'user-subject-1']);
    $owner   = new HasSubjectTestModel();

    expect($owner->setSubject($subject))->toBe($owner)
        ->and($owner->subject_uuid)->toBe('user-subject-1')
        ->and($owner->subject_type)->toBe(User::class)
        ->and($owner->saves)->toBe(0);

    $owner->setSubject(new User(['uuid' => 'user-subject-2']), true);

    expect($owner->subject_uuid)->toBe('user-subject-2')
        ->and($owner->subject_type)->toBe(User::class)
        ->and($owner->saves)->toBe(1);
});

test('has subject exposes the expected polymorphic relationship keys', function () {
    bind_test_container([
        'database.default' => 'mysql',
    ]);

    $owner = new HasSubjectTestModel();

    expect($owner->subject()->getMorphType())->toBe('subject_type')
        ->and($owner->subject()->getForeignKeyName())->toBe('subject_uuid');
});

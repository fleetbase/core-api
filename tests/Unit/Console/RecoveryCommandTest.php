<?php

use Fleetbase\Console\Commands\Recovery;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Illuminate\Cache\CacheManager;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Mail;

class RecoveryTestCommand extends Recovery
{
    public array $messages = [];
    public array $alerts   = [];

    public function __construct(
        public ?User $promptedUser = null,
        public ?Company $promptedCompany = null,
        public array $anticipateAnswers = [],
        public array $secretAnswers = [],
        public array $confirmAnswers = [],
        public array $choiceAnswers = [],
    ) {
        parent::__construct();
    }

    public function info($string, $verbosity = null): void
    {
        $this->messages[] = ['info', $string];
    }

    public function error($string, $verbosity = null): void
    {
        $this->messages[] = ['error', $string];
    }

    public function warn($string, $verbosity = null): void
    {
        $this->messages[] = ['warn', $string];
    }

    public function alert($string, $verbosity = null): void
    {
        $this->alerts[] = $string;
    }

    public function promptForUser(string $prompt = 'Find user by searching for name, email or ID'): ?User
    {
        return $this->promptedUser;
    }

    public function promptForCompany($prompt = 'Find company by searching for name or ID'): ?Company
    {
        return $this->promptedCompany;
    }

    public function promptForUserCompany(User $user, $prompt = 'Select the users company'): ?Company
    {
        return $this->promptedCompany;
    }

    public function anticipate($question, $choices, $default = null)
    {
        return array_shift($this->anticipateAnswers) ?? $default;
    }

    public function secret($question, $fallback = true)
    {
        return array_shift($this->secretAnswers);
    }

    public function confirm($question, $default = false)
    {
        return array_shift($this->confirmAnswers) ?? $default;
    }

    public function choice($question, array $choices, $default = null, $attempts = null, $multiple = false)
    {
        return array_shift($this->choiceAnswers) ?? $default ?? $choices[0];
    }
}

class RecoveryAutocompleteTestCommand extends RecoveryTestCommand
{
    public array $anticipatedChoices = [];

    public function anticipate($question, $choices, $default = null)
    {
        $answer = array_shift($this->anticipateAnswers) ?? $default;

        if (is_callable($choices)) {
            $this->anticipatedChoices[] = $choices((string) $answer);
        }

        return $answer;
    }
}

function recovery_user(array $attributes = [], array $throwOn = []): User
{
    return new class(array_merge(['uuid' => 'user-uuid-1', 'name' => 'Ada Admin', 'email' => 'ada@example.test', 'type' => 'user'], $attributes), $throwOn) extends User {
        public array $calls = [];

        public function __construct(array $attributes = [], private array $throwOn = [])
        {
            parent::__construct($attributes);
            $this->exists = true;
        }

        public function setType(string $type): self
        {
            if (in_array('setType', $this->throwOn, true)) {
                throw new RuntimeException('set type failed');
            }

            $this->calls[] = ['setType', $type];
            $this->type    = $type;

            return $this;
        }

        public function changePassword($newPassword): self
        {
            if (in_array('changePassword', $this->throwOn, true)) {
                throw new RuntimeException('change password failed');
            }

            $this->calls[] = ['changePassword', $newPassword];

            return $this;
        }

        public function assignCompany(Company $company, string $role = 'Administrator'): self
        {
            if (in_array('assignCompany', $this->throwOn, true)) {
                throw new RuntimeException('assign company failed');
            }

            $this->calls[] = ['assignCompany', $company->public_id, $role];

            return $this;
        }

        public function setCompany(Company $company): self
        {
            $this->calls[] = ['setCompany', $company->public_id];

            return $this;
        }
    };
}

function recovery_company(array $attributes = [], ?CompanyUser $pivot = null, array $throwOn = []): Company
{
    return new class(array_merge(['uuid' => 'company-uuid-1', 'public_id' => 'company_1234567', 'name' => 'Acme Logistics'], $attributes), $pivot, $throwOn) extends Company {
        public array $calls = [];

        public function __construct(array $attributes = [], private ?CompanyUser $pivot = null, private array $throwOn = [])
        {
            parent::__construct($attributes);
            $this->exists = true;
        }

        public function setOwner(User $user, bool $completedOnboarding = false)
        {
            if (in_array('setOwner', $this->throwOn, true)) {
                throw new RuntimeException('set owner failed');
            }

            $this->calls[]    = ['setOwner', $user->email, $completedOnboarding];
            $this->owner_uuid = $user->uuid;

            return $this;
        }

        public function getCompanyUserPivot(string|User $user): ?CompanyUser
        {
            $this->calls[] = ['getCompanyUserPivot', $user instanceof User ? $user->uuid : $user];

            return $this->pivot;
        }
    };
}

function recovery_company_user(): CompanyUser
{
    return new class extends CompanyUser {
        public array $calls = [];

        public function assignSingleRole($role): CompanyUser
        {
            $this->calls[] = ['assignSingleRole', $role];

            return $this;
        }
    };
}

function recovery_failing_company_user(): CompanyUser
{
    return new class extends CompanyUser {
        public function assignSingleRole($role): CompanyUser
        {
            throw new RuntimeException('role assignment failed');
        }
    };
}

class RecoveryDispatchTestCommand extends RecoveryTestCommand
{
    public array $calledActions = [];

    public function setRoleForUser(?User $user = null, ?Company $company = null): void
    {
        $this->calledActions[] = 'setRoleForUser';
    }

    public function assignUserToCompany(?User $user = null, ?Company $company = null): void
    {
        $this->calledActions[] = 'assignUserToCompany';
    }

    public function assignOwnerToCompany(?User $user = null, ?Company $company = null): void
    {
        $this->calledActions[] = 'assignOwnerToCompany';
    }

    public function resetUserPassword(?User $user = null): void
    {
        $this->calledActions[] = 'resetUserPassword';
    }

    public function setUserAsSystemAdmin(?User $user = null): void
    {
        $this->calledActions[] = 'setUserAsSystemAdmin';
    }
}

class RecoveryFailingDispatchTestCommand extends RecoveryTestCommand
{
    public function resetUserPassword(?User $user = null): void
    {
        throw new RuntimeException('selected recovery action failed');
    }
}

class RecoveryMailFake
{
    public array $sent       = [];
    private mixed $recipient = null;

    public function to(mixed $recipient): self
    {
        $this->recipient = $recipient;

        return $this;
    }

    public function send(mixed $mailable): void
    {
        $this->sent[] = [$this->recipient, $mailable::class];
    }
}

class RecoveryPromptTestCommand extends Recovery
{
    public array $anticipateChoices = [];
    public array $choiceQuestions   = [];

    public function __construct(
        public array $anticipateInputs = [],
        public array $choiceAnswers = [],
    ) {
        parent::__construct();
    }

    public function anticipate($question, $choices, $default = null)
    {
        $input                     = array_shift($this->anticipateInputs) ?? $default;
        $this->anticipateChoices[] = is_callable($choices) ? $choices($input) : $choices;

        return $input;
    }

    public function choice($question, array $choices, $default = null, $attempts = null, $multiple = false)
    {
        $this->choiceQuestions[] = [$question, $choices];

        return array_shift($this->choiceAnswers) ?? $default ?? $choices[0] ?? null;
    }
}

function recovery_prompt_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'                  => 'mysql',
        'database.connections.mysql'        => $connection,
        'cache.default'                     => 'array',
        'cache.stores.array.driver'         => 'array',
        'auth.defaults.guard'               => 'sanctum',
        'auth.guards.sanctum'               => [
            'driver'   => 'session',
            'provider' => 'users',
        ],
        'permission.models.permission'                 => Fleetbase\Models\Permission::class,
        'permission.models.role'                       => Fleetbase\Models\Role::class,
        'permission.cache.key'                         => 'spatie.permission.cache',
        'permission.cache.expiration_time'             => DateInterval::createFromDateString('24 hours'),
        'permission.column_names.team_foreign_key'     => 'team_id',
        'permission.column_names.model_morph_key'      => 'model_uuid',
        'permission.table_names.permissions'           => 'permissions',
        'permission.table_names.roles'                 => 'roles',
        'permission.table_names.role_has_permissions'  => 'role_has_permissions',
        'permission.table_names.model_has_roles'       => 'model_has_roles',
        'permission.table_names.model_has_permissions' => 'model_has_permissions',
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $cache = new CacheManager($container);
    $container->instance('db', $databaseManager);
    $container->instance('cache', $cache);
    $container->instance(CacheManager::class, $cache);
    Facade::clearResolvedInstance('db');
    Facade::clearResolvedInstance('cache');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('username')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('company_users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('roles', function ($table) {
        $table->string('id')->primary();
        $table->string('name')->nullable();
        $table->string('guard_name')->nullable();
        $table->string('company_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('permissions', function ($table) {
        $table->string('id')->primary();
        $table->string('name')->nullable();
        $table->string('guard_name')->nullable();
    });
    $schema->create('role_has_permissions', function ($table) {
        $table->string('permission_id')->nullable();
        $table->string('role_id')->nullable();
    });
    $schema->create('model_has_roles', function ($table) {
        $table->string('role_id')->nullable();
        $table->string('model_type')->nullable();
        $table->string('model_uuid')->nullable();
    });
    $schema->create('model_has_permissions', function ($table) {
        $table->string('permission_id')->nullable();
        $table->string('model_type')->nullable();
        $table->string('model_uuid')->nullable();
    });

    return $capsule;
}

beforeEach(function () {
    bind_test_container();
    Facade::clearResolvedInstances();
});

afterEach(function () {
    EloquentModel::unsetEventDispatcher();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

it('stops recovery actions when required user or company input is missing', function () {
    $missingUser = new RecoveryTestCommand();
    $missingUser->setUserAsSystemAdmin();

    $missingAssignmentUser = new RecoveryTestCommand();
    $missingAssignmentUser->assignUserToCompany();

    $missingCompany = new RecoveryTestCommand(promptedUser: recovery_user());
    $missingCompany->assignUserToCompany();

    $missingRoleUser = new RecoveryTestCommand();
    $missingRoleUser->setRoleForUser();

    $missingRoleCompany = new RecoveryTestCommand(promptedUser: recovery_user());
    $missingRoleCompany->setRoleForUser();

    $missingOwnerUser = new RecoveryTestCommand();
    $missingOwnerUser->assignOwnerToCompany();

    $missingOwnerCompany = new RecoveryTestCommand(promptedUser: recovery_user());
    $missingOwnerCompany->assignOwnerToCompany();

    $missingPasswordUser = new RecoveryTestCommand();
    $missingPasswordUser->resetUserPassword();

    expect($missingUser->messages)->toBe([
        ['error', 'No user selected or found to make system admin.'],
    ])
        ->and($missingCompany->messages)->toBe([
            ['error', 'No company selected to assign user to.'],
        ])
        ->and($missingAssignmentUser->messages)->toBe([
            ['error', 'No user selected to assign to a company.'],
        ])
        ->and($missingRoleUser->messages)->toBe([
            ['error', 'No user selected to set role for.'],
        ])
        ->and($missingRoleCompany->messages)->toBe([
            ['error', 'No company selected to set role for.'],
        ])
        ->and($missingOwnerUser->messages)->toBe([
            ['error', 'No user selected to assign as owner of a company.'],
        ])
        ->and($missingOwnerCompany->messages)->toBe([
            ['error', 'No company selected to set owner for.'],
        ])
        ->and($missingPasswordUser->messages)->toBe([
            ['error', 'No user selected or found to reset password for.'],
        ]);
});

it('dispatches the selected recovery action and reports dispatcher errors', function () {
    $command = new RecoveryDispatchTestCommand(choiceAnswers: ['Assign Owner to Company']);

    expect($command->handle())->toBe(0)
        ->and($command->alerts)->toBe(['Recovery Action: Assign Owner to Company'])
        ->and($command->calledActions)->toBe(['assignOwnerToCompany']);

    $failingCommand = new RecoveryFailingDispatchTestCommand(choiceAnswers: ['Reset User Password']);

    expect($failingCommand->handle())->toBe(0)
        ->and($failingCommand->alerts)->toBe(['Recovery Action: Reset User Password'])
        ->and($failingCommand->messages)->toBe([
            ['error', 'selected recovery action failed'],
        ]);
});

it('requires confirmation before promoting a user to system admin', function () {
    $user    = recovery_user();
    $command = new RecoveryTestCommand(confirmAnswers: [false]);

    $command->setUserAsSystemAdmin($user);

    expect($user->calls)->toBe([])
        ->and($command->messages)->toBe([
            ['warn', 'WARNING: By making a user a system administrator they will gain complete system access rights, including sensitive configurations and secrets. Run this command at your own risk.'],
            ['info', 'Done'],
        ]);
});

it('promotes a confirmed user to system admin and reports the audited target', function () {
    $user    = recovery_user();
    $command = new RecoveryTestCommand(confirmAnswers: [true]);

    $command->setUserAsSystemAdmin($user);

    expect($user->calls)->toBe([
        ['setType', 'admin'],
    ])
        ->and($command->messages)->toBe([
            ['warn', 'WARNING: By making a user a system administrator they will gain complete system access rights, including sensitive configurations and secrets. Run this command at your own risk.'],
            ['info', 'User Ada Admin (ada@example.test) is now a system administrator.'],
            ['info', 'Done'],
        ]);
});

it('reports system admin promotion failures without changing command completion', function () {
    $user    = recovery_user(throwOn: ['setType']);
    $command = new RecoveryTestCommand(confirmAnswers: [true]);

    $command->setUserAsSystemAdmin($user);

    expect($command->messages)->toBe([
        ['warn', 'WARNING: By making a user a system administrator they will gain complete system access rights, including sensitive configurations and secrets. Run this command at your own risk.'],
        ['error', 'set type failed'],
        ['info', 'Done'],
    ]);
});

it('assigns a confirmed user to a company with the selected role', function () {
    $user    = recovery_user();
    $company = recovery_company();
    $command = new RecoveryTestCommand(
        anticipateAnswers: ['Dispatcher'],
        confirmAnswers: [true],
    );

    $command->assignUserToCompany($user, $company);

    expect($user->calls)->toBe([
        ['assignCompany', 'company_1234567', 'Dispatcher'],
        ['setCompany', 'company_1234567'],
    ])
        ->and($command->messages)->toBe([
            ['info', 'User (Ada Admin) assigned to company (Acme Logistics)'],
            ['info', 'Done'],
        ]);
});

it('does not assign a user to a company when confirmation is declined', function () {
    $user    = recovery_user();
    $company = recovery_company();
    $command = new RecoveryTestCommand(
        anticipateAnswers: ['Dispatcher'],
        confirmAnswers: [false],
    );

    $command->assignUserToCompany($user, $company);

    expect($user->calls)->toBe([])
        ->and($command->messages)->toBe([
            ['info', 'Done'],
        ]);
});

it('reports assignment failures without setting the active company', function () {
    $user    = recovery_user(throwOn: ['assignCompany']);
    $company = recovery_company();
    $command = new RecoveryTestCommand(
        anticipateAnswers: ['Dispatcher'],
        confirmAnswers: [true],
    );

    $command->assignUserToCompany($user, $company);

    expect($user->calls)->toBe([])
        ->and($command->messages)->toBe([
            ['error', 'assign company failed'],
            ['info', 'Done'],
        ]);
});

it('sets a confirmed owner and assigns administrator access to the company', function () {
    $user    = recovery_user();
    $company = recovery_company();
    $command = new RecoveryTestCommand(confirmAnswers: [true]);

    $command->assignOwnerToCompany($user, $company);

    expect($user->calls)->toBe([
        ['assignCompany', 'company_1234567', 'Administrator'],
    ])
        ->and($company->calls)->toBe([
            ['setOwner', 'ada@example.test', false],
        ])
        ->and($command->messages)->toBe([
            ['info', 'User (Ada Admin) made owner of the company (Acme Logistics)'],
            ['info', 'Done'],
        ]);
});

it('does not set company ownership when confirmation is declined', function () {
    $user    = recovery_user();
    $company = recovery_company();
    $command = new RecoveryTestCommand(confirmAnswers: [false]);

    $command->assignOwnerToCompany($user, $company);

    expect($user->calls)->toBe([])
        ->and($company->calls)->toBe([])
        ->and($command->messages)->toBe([
            ['info', 'Done'],
        ]);
});

it('reports owner assignment failures after company assignment is attempted', function () {
    $user    = recovery_user();
    $company = recovery_company(throwOn: ['setOwner']);
    $command = new RecoveryTestCommand(confirmAnswers: [true]);

    $command->assignOwnerToCompany($user, $company);

    expect($user->calls)->toBe([
        ['assignCompany', 'company_1234567', 'Administrator'],
    ])
        ->and($command->messages)->toBe([
            ['error', 'set owner failed'],
            ['info', 'Done'],
        ]);
});

it('resets a password only when passwords match and the reset is confirmed', function () {
    $user    = recovery_user();
    $command = new RecoveryTestCommand(
        secretAnswers: ['correct-horse', 'correct-horse'],
        confirmAnswers: [true, false],
    );

    $command->resetUserPassword($user);

    expect($user->calls)->toBe([
        ['changePassword', 'correct-horse'],
    ])
        ->and($command->messages)->toBe([
            ['info', 'Running password reset for user Ada Admin (ada@example.test)'],
            ['info', 'User Ada Admin (ada@example.test) password was changed.'],
            ['info', 'Done'],
        ]);
});

it('can retry a password reset after a mismatch and optionally email the replacement password', function () {
    $user = recovery_user();
    $mail = new RecoveryMailFake();
    Mail::swap($mail);
    $command = new RecoveryTestCommand(
        secretAnswers: ['first-password', 'second-password', 'final-password', 'final-password'],
        confirmAnswers: [true, true, true],
    );

    $command->resetUserPassword($user);

    expect($user->calls)->toBe([
        ['changePassword', 'final-password'],
    ])
        ->and($command->messages)->toBe([
            ['info', 'Running password reset for user Ada Admin (ada@example.test)'],
            ['error', 'Passwords do not match.'],
            ['info', 'Running password reset for user Ada Admin (ada@example.test)'],
            ['info', 'User Ada Admin (ada@example.test) password was changed.'],
            ['info', 'Done'],
        ]);

    expect($mail->sent)->toHaveCount(1)
        ->and($mail->sent[0][0])->toBe($user)
        ->and($mail->sent[0][1])->toBe(Fleetbase\Mail\UserCredentialsMail::class);
});

it('reports password reset failures after confirmation', function () {
    $user    = recovery_user(throwOn: ['changePassword']);
    $command = new RecoveryTestCommand(
        secretAnswers: ['correct-horse', 'correct-horse'],
        confirmAnswers: [true, false],
    );

    $command->resetUserPassword($user);

    expect($command->messages)->toBe([
        ['info', 'Running password reset for user Ada Admin (ada@example.test)'],
        ['error', 'change password failed'],
        ['info', 'Done'],
    ]);
});

it('does not reset a password when confirmation mismatches and retry is declined', function () {
    $user    = recovery_user();
    $command = new RecoveryTestCommand(
        secretAnswers: ['first-password', 'second-password'],
        confirmAnswers: [false],
    );

    $command->resetUserPassword($user);

    expect($user->calls)->toBe([])
        ->and($command->messages)->toBe([
            ['info', 'Running password reset for user Ada Admin (ada@example.test)'],
            ['error', 'Passwords do not match.'],
        ]);
});

it('assigns a selected role to an existing company membership', function () {
    $pivot   = recovery_company_user();
    $user    = recovery_user();
    $company = recovery_company(pivot: $pivot);
    $command = new RecoveryTestCommand(
        anticipateAnswers: ['Manager'],
        confirmAnswers: [true],
    );

    $command->setRoleForUser($user, $company);

    expect($company->calls)->toBe([
        ['getCompanyUserPivot', 'user-uuid-1'],
    ])
        ->and($pivot->calls)->toBe([
            ['assignSingleRole', 'Manager'],
        ])
        ->and($command->messages)->toBe([
            ['info', 'Role Manager assigned to user (Ada Admin) for the company (Acme Logistics)'],
            ['info', 'Done'],
        ]);
});

it('offers company assignment when setting a role for a user without membership', function () {
    $user    = recovery_user();
    $company = recovery_company();
    $command = new RecoveryTestCommand(confirmAnswers: [false]);

    $command->setRoleForUser($user, $company);

    expect($company->calls)->toBe([
        ['getCompanyUserPivot', 'user-uuid-1'],
    ])
        ->and($user->calls)->toBe([])
        ->and($command->messages)->toBe([
            ['error', 'User is not a member of the selected company.'],
        ]);
});

it('assigns the user to the selected company when role setup finds no membership and retry is confirmed', function () {
    $user    = recovery_user();
    $company = recovery_company();
    $command = new RecoveryTestCommand(
        anticipateAnswers: ['Administrator'],
        confirmAnswers: [true, true],
    );

    $command->setRoleForUser($user, $company);

    expect($company->calls)->toBe([
        ['getCompanyUserPivot', 'user-uuid-1'],
    ])
        ->and($user->calls)->toBe([
            ['assignCompany', 'company_1234567', 'Administrator'],
            ['setCompany', 'company_1234567'],
        ])
        ->and($command->messages)->toBe([
            ['error', 'User is not a member of the selected company.'],
            ['info', 'User (Ada Admin) assigned to company (Acme Logistics)'],
            ['info', 'Done'],
        ]);
});

it('leaves an existing role unchanged when assignment confirmation is declined', function () {
    $pivot   = recovery_company_user();
    $user    = recovery_user();
    $company = recovery_company(pivot: $pivot);
    $command = new RecoveryTestCommand(
        anticipateAnswers: ['Manager'],
        confirmAnswers: [false],
    );

    $command->setRoleForUser($user, $company);

    expect($pivot->calls)->toBe([])
        ->and($command->messages)->toBe([
            ['info', 'Done'],
        ]);
});

it('reports existing membership role assignment failures', function () {
    $pivot   = recovery_failing_company_user();
    $user    = recovery_user();
    $company = recovery_company(pivot: $pivot);
    $command = new RecoveryTestCommand(
        anticipateAnswers: ['Manager'],
        confirmAnswers: [true],
    );

    $command->setRoleForUser($user, $company);

    expect($command->messages)->toBe([
        ['error', 'role assignment failed'],
        ['info', 'Done'],
    ]);
});

it('builds role autocomplete suggestions for role recovery prompts', function () {
    $capsule = recovery_prompt_database();
    $db      = $capsule->getConnection('mysql');
    $db->table('roles')->insert([
        [
            'id'           => 'role-manager',
            'name'         => 'Manager',
            'guard_name'   => 'sanctum',
            'company_uuid' => null,
            'created_at'   => '2026-07-26 10:00:00',
            'updated_at'   => '2026-07-26 10:00:00',
        ],
        [
            'id'           => 'role-fleet-manager',
            'name'         => 'Fleet Manager',
            'guard_name'   => 'sanctum',
            'company_uuid' => null,
            'created_at'   => '2026-07-26 10:00:00',
            'updated_at'   => '2026-07-26 10:00:00',
        ],
        [
            'id'           => 'role-company-manager',
            'name'         => 'Company Manager',
            'guard_name'   => 'sanctum',
            'company_uuid' => 'company-uuid-1',
            'created_at'   => '2026-07-26 10:00:00',
            'updated_at'   => '2026-07-26 10:00:00',
        ],
    ]);

    $pivot          = recovery_company_user();
    $user           = recovery_user();
    $company        = recovery_company(pivot: $pivot);
    $setRoleCommand = new RecoveryAutocompleteTestCommand(
        anticipateAnswers: ['manager'],
        confirmAnswers: [false],
    );
    $assignCommand = new RecoveryAutocompleteTestCommand(
        anticipateAnswers: ['manager'],
        confirmAnswers: [false],
    );

    $setRoleCommand->setRoleForUser($user, $company);
    $assignCommand->assignUserToCompany($user, $company);

    expect($setRoleCommand->anticipatedChoices)->toBe([['Manager', 'Fleet Manager']])
        ->and($assignCommand->anticipatedChoices)->toBe([['Manager', 'Fleet Manager']])
        ->and($pivot->calls)->toBe([])
        ->and($user->calls)->toBe([]);
});

it('prompts for users by name username public id email and returns the selected record', function () {
    $capsule = recovery_prompt_database();
    $db      = $capsule->getConnection('mysql');

    $db->table('users')->insert([
        [
            'uuid'       => 'user-ada',
            'public_id'  => 'user_ada',
            'name'       => 'Ada Lovelace',
            'email'      => 'ada@example.test',
            'username'   => 'ada',
            'type'       => 'user',
            'status'     => 'active',
            'created_at' => '2026-07-18 00:00:00',
            'updated_at' => '2026-07-18 00:00:00',
        ],
        [
            'uuid'       => 'user-booker',
            'public_id'  => 'user_booker',
            'name'       => 'Operations Lead',
            'email'      => 'booker@example.test',
            'username'   => 'booker',
            'type'       => 'user',
            'status'     => 'active',
            'created_at' => '2026-07-18 00:00:00',
            'updated_at' => '2026-07-18 00:00:00',
        ],
        [
            'uuid'       => 'user-runner',
            'public_id'  => 'user_runner',
            'name'       => 'Dispatch Runner',
            'email'      => 'runner@example.test',
            'username'   => 'runner',
            'type'       => 'user',
            'status'     => 'active',
            'created_at' => '2026-07-18 00:00:00',
            'updated_at' => '2026-07-18 00:00:00',
        ],
    ]);

    $byName = new RecoveryPromptTestCommand(
        anticipateInputs: ['ada'],
        choiceAnswers: ['Ada Lovelace - ada@example.test - user_ada'],
    );
    $byUsername = new RecoveryPromptTestCommand(
        anticipateInputs: ['book'],
        choiceAnswers: ['Operations Lead - booker@example.test - user_booker'],
    );
    $byPublicId = new RecoveryPromptTestCommand(
        anticipateInputs: ['user_run'],
        choiceAnswers: ['Dispatch Runner - runner@example.test - user_runner'],
    );
    $byEmail = new RecoveryPromptTestCommand(
        anticipateInputs: ['runner@example'],
        choiceAnswers: ['Dispatch Runner - runner@example.test - user_runner'],
    );

    expect($byName->promptForUser()->uuid)->toBe('user-ada')
        ->and($byName->anticipateChoices)->toBe([['Ada Lovelace']])
        ->and($byUsername->promptForUser()->uuid)->toBe('user-booker')
        ->and($byUsername->anticipateChoices)->toBe([['booker']])
        ->and($byPublicId->promptForUser()->uuid)->toBe('user-runner')
        ->and($byPublicId->anticipateChoices)->toBe([['user_runner']])
        ->and($byEmail->promptForUser()->uuid)->toBe('user-runner')
        ->and($byEmail->anticipateChoices)->toBe([['runner@example.test']]);
});

it('prompts for companies by name public id uuid and returns the selected record', function () {
    $capsule = recovery_prompt_database();
    $db      = $capsule->getConnection('mysql');

    $db->table('companies')->insert([
        [
            'uuid'       => 'company-acme',
            'public_id'  => 'company_acme',
            'name'       => 'Acme Logistics',
            'created_at' => '2026-07-18 00:00:00',
            'updated_at' => '2026-07-18 00:00:00',
        ],
        [
            'uuid'       => 'company-fleet',
            'public_id'  => 'company_fleet',
            'name'       => 'City Dispatch',
            'created_at' => '2026-07-18 00:00:00',
            'updated_at' => '2026-07-18 00:00:00',
        ],
    ]);

    $byName = new RecoveryPromptTestCommand(
        anticipateInputs: ['acme'],
        choiceAnswers: ['Acme Logistics - company_acme'],
    );
    $byPublicId = new RecoveryPromptTestCommand(
        anticipateInputs: ['company_fleet'],
        choiceAnswers: ['City Dispatch - company_fleet'],
    );
    $byUuid = new RecoveryPromptTestCommand(
        anticipateInputs: ['company-fleet'],
        choiceAnswers: ['City Dispatch - company_fleet'],
    );

    expect($byName->promptForCompany()->uuid)->toBe('company-acme')
        ->and($byName->anticipateChoices)->toBe([['Acme Logistics']])
        ->and($byPublicId->promptForCompany()->uuid)->toBe('company-fleet')
        ->and($byPublicId->anticipateChoices)->toBe([['company_fleet']])
        ->and($byUuid->promptForCompany()->uuid)->toBe('company-fleet')
        ->and($byUuid->choiceQuestions[0][0])->toBe('Found user, select the company below:');
});

it('prompts for one of a users companies and returns the selected membership company', function () {
    $capsule = recovery_prompt_database();
    $db      = $capsule->getConnection('mysql');

    $db->table('users')->insert([
        'uuid'       => 'user-ada',
        'public_id'  => 'user_ada',
        'name'       => 'Ada Lovelace',
        'email'      => 'ada@example.test',
        'username'   => 'ada',
        'created_at' => '2026-07-18 00:00:00',
        'updated_at' => '2026-07-18 00:00:00',
    ]);
    $db->table('companies')->insert([
        [
            'uuid'       => 'company-acme',
            'public_id'  => 'company_acme',
            'name'       => 'Acme Logistics',
            'created_at' => '2026-07-18 00:00:00',
            'updated_at' => '2026-07-18 00:00:00',
        ],
        [
            'uuid'       => 'company-fleet',
            'public_id'  => 'company_fleet',
            'name'       => 'City Dispatch',
            'created_at' => '2026-07-18 00:00:00',
            'updated_at' => '2026-07-18 00:00:00',
        ],
    ]);
    $db->table('company_users')->insert([
        [
            'uuid'         => 'company-user-acme',
            'company_uuid' => 'company-acme',
            'user_uuid'    => 'user-ada',
            'status'       => 'active',
            'created_at'   => '2026-07-18 00:00:00',
            'updated_at'   => '2026-07-18 00:00:00',
        ],
        [
            'uuid'         => 'company-user-fleet',
            'company_uuid' => 'company-fleet',
            'user_uuid'    => 'user-ada',
            'status'       => 'active',
            'created_at'   => '2026-07-18 00:00:00',
            'updated_at'   => '2026-07-18 00:00:00',
        ],
    ]);

    $user    = User::where('public_id', 'user_ada')->first();
    $command = new RecoveryPromptTestCommand(
        choiceAnswers: ['City Dispatch - company_fleet'],
    );

    expect($command->promptForUserCompany($user, 'Select company for role repair')->uuid)->toBe('company-fleet')
        ->and($command->choiceQuestions[0])->toBe([
            'Found users, Select company for role repair',
            [
                'Acme Logistics - company_acme',
                'City Dispatch - company_fleet',
            ],
        ]);
});

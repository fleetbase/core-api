<?php

use Fleetbase\Console\Commands\Recovery;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Illuminate\Support\Facades\Facade;

class RecoveryTestCommand extends Recovery
{
    public array $messages = [];

    public function __construct(
        public ?User $promptedUser = null,
        public ?Company $promptedCompany = null,
        public array $anticipateAnswers = [],
        public array $secretAnswers = [],
        public array $confirmAnswers = [],
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

    public function promptForUser(string $prompt = 'Find user by searching for name, email or ID'): ?User
    {
        return $this->promptedUser;
    }

    public function promptForCompany($prompt = 'Find company by searching for name or ID'): ?Company
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
}

function recovery_user(array $attributes = []): User
{
    return new class(array_merge(['uuid' => 'user-uuid-1', 'name' => 'Ada Admin', 'email' => 'ada@example.test', 'type' => 'user'], $attributes)) extends User {
        public array $calls = [];

        public function __construct(array $attributes = [])
        {
            parent::__construct($attributes);
            $this->exists = true;
        }

        public function setType(string $type): self
        {
            $this->calls[] = ['setType', $type];
            $this->type    = $type;

            return $this;
        }

        public function changePassword($newPassword): self
        {
            $this->calls[] = ['changePassword', $newPassword];

            return $this;
        }

        public function assignCompany(Company $company, string $role = 'Administrator'): self
        {
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

function recovery_company(array $attributes = [], ?CompanyUser $pivot = null): Company
{
    return new class(array_merge(['uuid' => 'company-uuid-1', 'public_id' => 'company_1234567', 'name' => 'Acme Logistics'], $attributes), $pivot) extends Company {
        public array $calls = [];

        public function __construct(array $attributes = [], private ?CompanyUser $pivot = null)
        {
            parent::__construct($attributes);
            $this->exists = true;
        }

        public function setOwner(User $user, bool $completedOnboarding = false)
        {
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

beforeEach(function () {
    bind_test_container();
    Facade::clearResolvedInstances();
});

afterEach(function () {
    Facade::clearResolvedInstances();
});

it('stops recovery actions when required user or company input is missing', function () {
    $missingUser = new RecoveryTestCommand();
    $missingUser->setUserAsSystemAdmin();

    $missingCompany = new RecoveryTestCommand(promptedUser: recovery_user());
    $missingCompany->assignUserToCompany();

    expect($missingUser->messages)->toBe([
        ['error', 'No user selected or found to make system admin.'],
    ])
        ->and($missingCompany->messages)->toBe([
            ['error', 'No company selected to assign user to.'],
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

<?php

namespace Illuminate\Validation\Rules {
    if (!class_exists(Password::class)) {
        class Password
        {
            public static function min(int $size): self
            {
                return new self();
            }

            public function mixedCase(): self
            {
                return $this;
            }

            public function letters(): self
            {
                return $this;
            }

            public function numbers(): self
            {
                return $this;
            }

            public function symbols(): self
            {
                return $this;
            }

            public function uncompromised(): self
            {
                return $this;
            }

            public function __toString(): string
            {
                return 'password';
            }
        }
    }
}

namespace Illuminate\Validation {
    if (!class_exists(Rule::class)) {
        class Rule
        {
            public static function unique(string $table, string $column = 'NULL'): UniqueRule
            {
                return new UniqueRule($table, $column);
            }

            public static function requiredIf(bool|callable $condition): RequiredIfRule
            {
                return new RequiredIfRule($condition);
            }
        }

        class UniqueRule
        {
            private mixed $ignore     = null;
            private ?string $idColumn = null;
            private array $whereNull  = [];

            public function __construct(private string $table, private string $column)
            {
            }

            public function ignore(mixed $id, ?string $idColumn = null): self
            {
                $this->ignore   = $id;
                $this->idColumn = $idColumn;

                return $this;
            }

            public function whereNull(string $column): self
            {
                $this->whereNull[] = $column;

                return $this;
            }

            public function __toString(): string
            {
                $rule = 'unique:' . $this->table . ',' . $this->column;

                if ($this->ignore !== null) {
                    $rule .= ',' . $this->ignore . ',' . ($this->idColumn ?? 'id');
                }

                foreach ($this->whereNull as $column) {
                    $rule .= ',' . $column . ',"NULL"';
                }

                return $rule;
            }
        }

        class RequiredIfRule
        {
            private mixed $condition;

            public function __construct(bool|callable $condition)
            {
                $this->condition = $condition;
            }

            public function __toString(): string
            {
                $condition = is_callable($this->condition) ? (bool) call_user_func($this->condition) : $this->condition;

                return $condition ? 'required' : '';
            }
        }
    }
}

namespace {
    use Fleetbase\Http\Requests\AdminRequest;
    use Fleetbase\Http\Requests\ChangePasswordRequest;
    use Fleetbase\Http\Requests\CreateChatChannelRequest;
    use Fleetbase\Http\Requests\CreateCommentRequest;
    use Fleetbase\Http\Requests\CreateReportRequest;
    use Fleetbase\Http\Requests\CreateUserRequest;
    use Fleetbase\Http\Requests\ExecuteReportQueryRequest;
    use Fleetbase\Http\Requests\ExportReportRequest;
    use Fleetbase\Http\Requests\ExportRequest;
    use Fleetbase\Http\Requests\OnboardRequest;
    use Fleetbase\Http\Requests\SignUpRequest;
    use Fleetbase\Http\Requests\SwitchOrganizationRequest;
    use Fleetbase\Http\Requests\TwoFaValidationRequest;
    use Fleetbase\Http\Requests\UpdateReportRequest;
    use Fleetbase\Http\Requests\UpdateUserRequest;
    use Fleetbase\Http\Requests\ValidateReportQueryRequest;
    use Illuminate\Container\Container;
    use Illuminate\Session\ArraySessionHandler;
    use Illuminate\Session\Store;

    if (!function_exists('base_path')) {
        function base_path(string $path = ''): string
        {
            $path = ltrim($path, DIRECTORY_SEPARATOR);

            if (str_starts_with($path, 'vendor/fleetbase/core-api/')) {
                $path = substr($path, strlen('vendor/fleetbase/core-api/'));
            }

            return $path === '' ? getcwd() : getcwd() . DIRECTORY_SEPARATOR . $path;
        }
    }

    function request_rule_strings(mixed $rules): array
    {
        return array_map(
            fn ($rule) => is_string($rule)
                ? $rule
                : (method_exists($rule, '__toString') ? (string) $rule : $rule::class),
            is_array($rules) ? $rules : [$rules]
        );
    }

    function request_with_session(string $class, string $method = 'GET', array $input = [], array $session = []): mixed
    {
        $request = $class::create('/int/v1/test', $method, $input);
        $store   = new Store('testing', new ArraySessionHandler(120));

        foreach ($session as $key => $value) {
            $store->put($key, $value);
        }

        $request->setLaravelSession($store);
        Container::getInstance()->instance('request', $request);

        return $request;
    }

    function request_with_route_parameter(string $class, string $key, mixed $value): mixed
    {
        $request = $class::create('/int/v1/users/' . $value, 'PATCH');
        $request->setRouteResolver(fn () => new class($key, $value) {
            public function __construct(private string $key, private mixed $value)
            {
            }

            public function parameter(string $key, mixed $default = null): mixed
            {
                return $key === $this->key ? $this->value : $default;
            }
        });

        return $request;
    }

    function bind_active_request(mixed $request): mixed
    {
        Container::getInstance()->instance('request', $request);

        return $request;
    }

    function route_request(string $class, string $uri): mixed
    {
        $request = $class::create($uri, 'POST');
        $request->setRouteResolver(fn () => new class($uri) {
            public array $action = [];

            public function __construct(private string $uri)
            {
            }

            public function uri(): string
            {
                return ltrim($this->uri, '/');
            }
        });
        Container::getInstance()->instance('request', $request);

        return $request;
    }

    it('keeps authentication request validation contracts security-safe', function () {
        $loginRules = (new Fleetbase\Http\Requests\LoginRequest())->rules();
        $twoFaRules = (new TwoFaValidationRequest())->rules();

        expect($loginRules['identity'])->toBe(['required'])
            ->and($loginRules['identity'])->not->toContain('exists:users,email')
            ->and($loginRules['password'])->toBe(['required'])
            ->and((new Fleetbase\Http\Requests\LoginRequest())->messages()['identity.required'])
            ->toBe('An email address or phone number is required.')
            ->and($twoFaRules['token'])->toBe('required')
            ->and($twoFaRules['identity'])->toBe('required|email|exists:users,email')
            ->and((new TwoFaValidationRequest())->messages()['identity.exists'])->toBe('No user found by this email');
    });

    it('keeps signup onboarding and password rules strict', function () {
        $signupRules  = (new SignUpRequest())->rules();
        $onboardRules = (new OnboardRequest())->rules();
        $changeRules  = (new ChangePasswordRequest())->rules();

        expect((new SignUpRequest())->authorize())->toBeTrue()
            ->and((new OnboardRequest())->authorize())->toBeTrue()
            ->and((new ChangePasswordRequest())->authorize())->toBeTrue()
            ->and($signupRules['user.name'])->toBe(['required'])
            ->and($signupRules['user.email'])->toBe(['required', 'email'])
            ->and(request_rule_strings($signupRules['user.password']))->toContain('required', 'confirmed', 'string')
            ->and($signupRules['company.name'])->toBe(['required'])
            ->and((new SignUpRequest())->attributes())->toMatchArray([
                'user.name'                  => 'user name',
                'user.password_confirmation' => 'user password confirmation',
                'company.name'               => 'company name',
            ])
            ->and(request_rule_strings($onboardRules['email']))->toContain('required', 'email')
            ->and(request_rule_strings($onboardRules['phone']))->toContain('required')
            ->and(request_rule_strings($onboardRules['organization_name']))->toContain('required', 'min:4', 'max:100')
            ->and(request_rule_strings($changeRules['password']))->toContain('required', 'confirmed', 'string')
            ->and($changeRules['password_confirmation'])->toBe(['sometimes', 'min:4', 'max:64'])
            ->and((new ChangePasswordRequest())->messages()['password.symbols'])->toBe('Password must contain at least 1 symbol.');
    });

    it('preserves user create and update validation boundaries', function () {
        session()->flush();
        expect((new CreateUserRequest())->authorize())->toBeNull()
            ->and((new UpdateUserRequest())->authorize())->toBeNull();

        session(['company' => 'company-1']);

        $createRules = (new CreateUserRequest())->rules();
        $updateRules = request_with_route_parameter(UpdateUserRequest::class, 'user', 'user-1')->rules();

        expect((new CreateUserRequest())->authorize())->toBe('company-1')
            ->and((new UpdateUserRequest())->authorize())->toBe('company-1')
            ->and(request_rule_strings($createRules['email']))->toContain('required', 'email')
            ->and(request_rule_strings($createRules['email']))->not->toContain('unique:users,email')
            ->and(request_rule_strings($createRules['phone']))->toContain('sometimes', 'nullable')
            ->and(request_rule_strings($createRules['password']))->toContain('sometimes', 'confirmed', 'string')
            ->and(request_rule_strings($updateRules['name']))->toBe(['sometimes', 'required', 'string', 'min:2', 'max:100'])
            ->and(request_rule_strings($updateRules['phone']))->toContain('sometimes', 'nullable')
            ->and(request_rule_strings($updateRules['phone']))->toContain('unique:users,phone,user-1,uuid,deleted_at,"NULL"')
            ->and((new UpdateUserRequest())->messages()['phone.unique'])->toBe('An account with this phone number already exists.');
    });

    it('enforces platform session authorization on chat and import export style requests', function () {
        $unauthorizedCreateChat = request_with_session(CreateChatChannelRequest::class, 'POST');
        $authorizedCreateChat   = request_with_session(CreateChatChannelRequest::class, 'POST', [], ['api_credential' => 'cred-1']);
        $updateChat             = request_with_session(CreateChatChannelRequest::class, 'PUT', [], ['api_credential' => 'cred-1']);
        $createComment          = request_with_session(CreateCommentRequest::class, 'POST', ['content' => 'hello'], ['api_credential' => 'cred-1']);
        $nestedComment          = request_with_session(CreateCommentRequest::class, 'POST', ['content' => 'hello', 'parent_comment_uuid' => 'comment-1'], ['api_credential' => 'cred-1']);
        $export                 = request_with_session(ExportRequest::class, 'GET', [], ['user' => 'user-1']);

        expect(bind_active_request($unauthorizedCreateChat)->authorize())->toBeFalse()
            ->and(bind_active_request($authorizedCreateChat)->authorize())->toBeTrue()
            ->and(request_rule_strings($authorizedCreateChat->rules()['name']))->toBe(['required'])
            ->and(request_rule_strings($updateChat->rules()['name']))->toBe([''])
            ->and(bind_active_request($createComment)->authorize())->toBeTrue()
            ->and(request_rule_strings($createComment->rules()['subject']))->toBe(['required'])
            ->and(request_rule_strings($createComment->rules()['subject_id']))->toBe(['required'])
            ->and(request_rule_strings($createComment->rules()['content']))->toBe(['required'])
            ->and(request_rule_strings($nestedComment->rules()['subject']))->toBe([''])
            ->and(bind_active_request($export)->authorize())->toBeTrue()
            ->and($export->rules()['format'])->toBe('in:csv,xlsx,xls,html,pdf');
    });

    it('keeps admin and organization switch authorization contracts explicit', function () {
        $admin = AdminRequest::create('/int/v1/admin', 'GET');
        $admin->setUserResolver(fn () => new class {
            public function isAdmin(): bool
            {
                return true;
            }
        });

        $nonAdmin = AdminRequest::create('/int/v1/admin', 'GET');
        $nonAdmin->setUserResolver(fn () => new class {
            public function isAdmin(): bool
            {
                return false;
            }
        });

        $internalSwitch = route_request(SwitchOrganizationRequest::class, '/int/v1/organizations/switch');
        $publicSwitch   = route_request(SwitchOrganizationRequest::class, '/v1/organizations/switch');

        expect((new AdminRequest())->authorize())->toBeFalse()
            ->and($admin->authorize())->toBeTrue()
            ->and($nonAdmin->authorize())->toBeFalse()
            ->and($admin->rules())->toBe([])
            ->and(bind_active_request($internalSwitch)->rules()['next'])->toBe(['required', 'exists:companies,uuid'])
            ->and(bind_active_request($publicSwitch)->rules()['next'])->toBe(['required', 'exists:companies,public_id']);
    });

    it('keeps report request query limits formats and status transitions stable', function () {
        $createRules   = (new CreateReportRequest())->rules();
        $updateRules   = (new UpdateReportRequest())->rules();
        $validateRules = (new ValidateReportQueryRequest())->rules();
        $executeRules  = (new ExecuteReportQueryRequest())->rules();
        $exportRules   = (new ExportReportRequest())->rules();

        expect((new CreateReportRequest())->authorize())->toBeTrue()
            ->and((new UpdateReportRequest())->authorize())->toBeTrue()
            ->and((new ValidateReportQueryRequest())->authorize())->toBeTrue()
            ->and((new ExecuteReportQueryRequest())->authorize())->toBeTrue()
            ->and((new ExportReportRequest())->authorize())->toBeTrue()
            ->and($createRules['title'])->toBe('required|string|max:255')
            ->and($createRules['query_config.select'])->toBe('required_with:query_config|array|min:1')
            ->and($createRules['query_config.limit'])->toBe('nullable|integer|min:1|max:10000')
            ->and($updateRules['title'])->toBe('sometimes|required|string|max:255')
            ->and($updateRules['status'])->toBe('sometimes|string|in:pending,generating,complete,failed')
            ->and($validateRules['query_config.limit'])->toBe('nullable|integer|min:1|max:10000')
            ->and($executeRules['limit'])->toBe('nullable|integer|min:1|max:1000')
            ->and($executeRules['offset'])->toBe('nullable|integer|min:0')
            ->and($exportRules['format'])->toBe('required|string|in:json,csv,xlsx')
            ->and((new ExportReportRequest())->messages()['format.in'])->toBe('Export format must be one of: json, csv, xlsx');
    });
}

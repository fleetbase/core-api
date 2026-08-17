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
    if (!class_exists(ValidationException::class)) {
        class ValidationException extends \Exception
        {
            public mixed $response;

            public function __construct(mixed $validator, mixed $response = null)
            {
                parent::__construct('The given data was invalid.');
                $this->response = $response;
            }
        }
    }

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
    use Fleetbase\Http\Requests\DownloadFileRequest as PublicDownloadFileRequest;
    use Fleetbase\Http\Requests\ExecuteReportQueryRequest;
    use Fleetbase\Http\Requests\ExportReportRequest;
    use Fleetbase\Http\Requests\ExportRequest;
    use Fleetbase\Http\Requests\FleetbaseRequest;
    use Fleetbase\Http\Requests\ImportRequest;
    use Fleetbase\Http\Requests\Internal\BulkActionRequest;
    use Fleetbase\Http\Requests\Internal\BulkDeleteRequest;
    use Fleetbase\Http\Requests\Internal\ChangeCurrentUserEmailRequest;
    use Fleetbase\Http\Requests\Internal\ChangeUserEmailRequest;
    use Fleetbase\Http\Requests\Internal\ConfirmCurrentPassword;
    use Fleetbase\Http\Requests\Internal\CreateCategoryRequest;
    use Fleetbase\Http\Requests\Internal\CreateCustomFieldRequest;
    use Fleetbase\Http\Requests\Internal\CreateTemplateRequest;
    use Fleetbase\Http\Requests\Internal\DownloadFileRequest;
    use Fleetbase\Http\Requests\Internal\InviteUserRequest;
    use Fleetbase\Http\Requests\Internal\ResendUserInvite;
    use Fleetbase\Http\Requests\Internal\ResetPasswordRequest;
    use Fleetbase\Http\Requests\Internal\UpdatePasswordRequest;
    use Fleetbase\Http\Requests\Internal\UploadBase64FileRequest;
    use Fleetbase\Http\Requests\Internal\UploadFileRequest;
    use Fleetbase\Http\Requests\Internal\ValidatePasswordRequest;
    use Fleetbase\Http\Requests\JoinOrganizationRequest;
    use Fleetbase\Http\Requests\OnboardRequest;
    use Fleetbase\Http\Requests\SignUpRequest;
    use Fleetbase\Http\Requests\SwitchOrganizationRequest;
    use Fleetbase\Http\Requests\TwoFaValidationRequest;
    use Fleetbase\Http\Requests\UpdateReportRequest;
    use Fleetbase\Http\Requests\UpdateUserRequest;
    use Fleetbase\Http\Requests\ValidateReportQueryRequest;
    use Illuminate\Container\Container;
    use Illuminate\Contracts\Validation\Validator as ValidatorContract;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\Eloquent\Model as EloquentModel;
    use Illuminate\Events\Dispatcher;
    use Illuminate\Session\ArraySessionHandler;
    use Illuminate\Session\Store;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Support\MessageBag;
    use Illuminate\Validation\ValidationException;

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

    function request_contract_files_database(): Capsule
    {
        EloquentModel::clearBootedModels();

        $connection = [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ];

        $container = bind_test_container([
            'database.default'           => 'mysql',
            'database.connections.mysql' => $connection,
            'fleetbase.connection.db'    => 'mysql',
        ]);

        $capsule = new Capsule($container);
        $capsule->addConnection($connection, 'mysql');
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $capsule->getDatabaseManager()->setDefaultConnection('mysql');
        $container->instance('db', $capsule->getDatabaseManager());
        Facade::clearResolvedInstance('db');

        $capsule->getConnection('mysql')->getSchemaBuilder()->create('files', function ($table) {
            $table->string('uuid')->primary();
            $table->string('path')->nullable();
            $table->string('original_filename')->nullable();
            $table->softDeletes();
        });

        return $capsule;
    }

    class RequestContractsValidatorFake implements ValidatorContract
    {
        public function __construct(private array $messages)
        {
        }

        public function validate()
        {
            return [];
        }

        public function validated()
        {
            return [];
        }

        public function fails()
        {
            return true;
        }

        public function failed()
        {
            return [];
        }

        public function sometimes($attribute, $rules, callable $callback)
        {
            return $this;
        }

        public function after($callback)
        {
            return $this;
        }

        public function errors()
        {
            return $this->getMessageBag();
        }

        public function getMessageBag()
        {
            return new MessageBag($this->messages);
        }

        public function getTranslator()
        {
            // The real Illuminate\Validation\ValidationException::summarize() asks the
            // validator for a translator when building its message.
            return new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en');
        }
    }

    class RequestContractsFleetbaseRequestProbe extends FleetbaseRequest
    {
        public function triggerValidationResponse(ValidatorContract $validator): never
        {
            $this->responseWithErrors($validator);
        }
    }

    class RequestContractsLoginRequestProbe extends Fleetbase\Http\Requests\LoginRequest
    {
        public function triggerFailedValidation(ValidatorContract $validator): mixed
        {
            return $this->failedValidation($validator);
        }
    }

    class RequestContractsSignUpRequestProbe extends SignUpRequest
    {
        public function triggerFailedValidation(ValidatorContract $validator): mixed
        {
            return $this->failedValidation($validator);
        }
    }

    class RequestContractsTwoFaValidationRequestProbe extends TwoFaValidationRequest
    {
        public function triggerFailedValidation(ValidatorContract $validator): mixed
        {
            return $this->failedValidation($validator);
        }
    }

    it('keeps authentication request validation contracts security-safe', function () {
        $loginRules = (new Fleetbase\Http\Requests\LoginRequest())->rules();
        $twoFaRules = (new TwoFaValidationRequest())->rules();

        expect($loginRules['identity'])->toBe(['required'])
            ->and($loginRules['identity'])->not->toContain('exists:users,email')
            ->and($loginRules['password'])->toBe(['required'])
            ->and((new Fleetbase\Http\Requests\LoginRequest())->authorize())->toBeTrue()
            ->and((new Fleetbase\Http\Requests\LoginRequest())->messages()['identity.required'])
            ->toBe('An email address or phone number is required.')
            ->and($twoFaRules['token'])->toBe('required')
            ->and($twoFaRules['identity'])->toBe('required|email|exists:users,email')
            ->and((new TwoFaValidationRequest())->authorize())->toBeTrue()
            ->and((new TwoFaValidationRequest())->messages()['identity.exists'])->toBe('No user found by this email');
    });

    it('keeps login validation errors generic and correctly shaped', function () {
        $login = new RequestContractsLoginRequestProbe();

        $singleError = $login->triggerFailedValidation(new RequestContractsValidatorFake([
            'identity' => ['An email address or phone number is required.'],
        ]));

        $multipleErrors = $login->triggerFailedValidation(new RequestContractsValidatorFake([
            'identity' => ['An email address or phone number is required.'],
            'password' => ['A password is required.'],
        ]));

        expect($singleError->getStatusCode())->toBe(422)
            ->and($singleError->getData(true))->toBe([
                'errors' => ['An email address or phone number is required.'],
            ])
            ->and($multipleErrors->getStatusCode())->toBe(422)
            ->and($multipleErrors->getData(true))->toBe([
                'errors' => [
                    'An email address or phone number is required.',
                    'A password is required.',
                ],
            ]);
    });

    it('keeps signup onboarding and password rules strict', function () {
        $signup       = new SignUpRequest();
        $signupRules  = $signup->rules();
        $signupErrors = $signup->messages();
        $onboard      = new OnboardRequest();
        $onboardRules = $onboard->rules();
        $messages     = $onboard->messages();
        $changeRules  = (new ChangePasswordRequest())->rules();

        expect($signup->authorize())->toBeTrue()
            ->and($onboard->authorize())->toBeTrue()
            ->and((new ChangePasswordRequest())->authorize())->toBeTrue()
            ->and($signupRules['user.name'])->toBe(['required'])
            ->and($signupRules['user.email'])->toBe(['required', 'email'])
            ->and(request_rule_strings($signupRules['user.password']))->toContain('required', 'confirmed', 'string')
            ->and($signupRules['company.name'])->toBe(['required'])
            ->and($signup->attributes())->toMatchArray([
                'user.name'                  => 'user name',
                'user.password_confirmation' => 'user password confirmation',
                'company.name'               => 'company name',
            ])
            ->and(request_rule_strings($onboardRules['email']))->toContain('required', 'email')
            ->and(request_rule_strings($onboardRules['phone']))->toContain('required')
            ->and(request_rule_strings($onboardRules['name']))->toContain('required', 'min:2', 'max:50')
            ->and(request_rule_strings($onboardRules['organization_name']))->toContain('required', 'min:4', 'max:100')
            ->and($messages['*.required'])->toBe('Your :attribute is required to signup')
            ->and($messages['email'])->toBe('You must enter a valid :attribute to signup')
            ->and($messages['email.unique'])->toBe('An account with this email address already exists')
            ->and($messages['phone.unique'])->toBe('An account with this phone number already exists')
            ->and($messages['password.required'])->toBe('You must enter a password.')
            ->and($messages['password.mixed'])->toBe('Password must contain both uppercase and lowercase letters.')
            ->and($messages['password.letters'])->toBe('Password must contain at least 1 letter.')
            ->and($messages['password.numbers'])->toBe('Password must contain at least 1 number.')
            ->and($messages['password.symbols'])->toBe('Password must contain at least 1 symbol.')
            ->and($messages['password.uncompromised'])->toBe('The password you entered has appeared in a data breach. Please choose a different one.')
            ->and($signupErrors['*.required'])->toBe('Your :attribute is required to signup')
            ->and($signupErrors['user.email'])->toBe('You must enter a valid :attribute to signup')
            ->and($signupErrors['user.email.unique'])->toBe('An account with this email address already exists')
            ->and($signupErrors['user.password.required'])->toBe('You must enter a password to signup')
            ->and($signupErrors['user.password.min'])->toBe('Password must be at least 8 characters.')
            ->and($signupErrors['user.password.mixed'])->toBe('Password must contain both uppercase and lowercase letters.')
            ->and($signupErrors['user.password.letters'])->toBe('Password must contain at least one letter.')
            ->and($signupErrors['user.password.numbers'])->toBe('Password must contain at least one number.')
            ->and($signupErrors['user.password.symbols'])->toBe('Password must contain at least one symbol.')
            ->and($signupErrors['user.password.uncompromised'])->toBe('This password has appeared in a data breach. Please choose a different one.')
            ->and(request_rule_strings($changeRules['password']))->toContain('required', 'confirmed', 'string')
            ->and($changeRules['password_confirmation'])->toBe(['sometimes', 'min:4', 'max:64'])
            ->and((new ChangePasswordRequest())->messages()['password.symbols'])->toBe('Password must contain at least 1 symbol.');
    });

    it('keeps signup and two factor validation error responses stable', function () {
        $signupSingle = (new RequestContractsSignUpRequestProbe())->triggerFailedValidation(new RequestContractsValidatorFake([
            'user.email' => ['You must enter a valid user email to signup'],
        ]));

        $signupMultiple = (new RequestContractsSignUpRequestProbe())->triggerFailedValidation(new RequestContractsValidatorFake([
            'user.email'   => ['You must enter a valid user email to signup'],
            'company.name' => ['Your company name is required to signup'],
        ]));

        $twoFaSingle = (new RequestContractsTwoFaValidationRequestProbe())->triggerFailedValidation(new RequestContractsValidatorFake([
            'token' => ['A two factor session token is required'],
        ]));

        $twoFaMultiple = (new RequestContractsTwoFaValidationRequestProbe())->triggerFailedValidation(new RequestContractsValidatorFake([
            'identity' => ['No user found by this email'],
            'token'    => ['A two factor session token is required'],
        ]));

        expect($signupSingle->getStatusCode())->toBe(422)
            ->and($signupSingle->getData(true))->toBe([
                'errors' => ['You must enter a valid user email to signup'],
            ])
            ->and($signupMultiple->getStatusCode())->toBe(422)
            ->and($signupMultiple->getData(true))->toBe([
                'errors' => [
                    'You must enter a valid user email to signup',
                    'Your company name is required to signup',
                ],
            ])
            ->and($twoFaSingle->getStatusCode())->toBe(422)
            ->and($twoFaSingle->getData(true))->toBe([
                'errors' => ['A two factor session token is required'],
            ])
            ->and($twoFaMultiple->getStatusCode())->toBe(422)
            ->and($twoFaMultiple->getData(true))->toBe([
                'errors' => [
                    'No user found by this email',
                    'A two factor session token is required',
                ],
            ]);
    });

    it('preserves user create and update validation boundaries', function () {
        session()->flush();
        expect((new CreateUserRequest())->authorize())->toBeNull()
            ->and((new UpdateUserRequest())->authorize())->toBeNull();

        session(['company' => 'company-1']);

        $createRules = (new CreateUserRequest())->rules();
        $updateRules = request_with_route_parameter(UpdateUserRequest::class, 'user', 'user-1')->rules();
        $messages    = (new CreateUserRequest())->messages();

        expect((new CreateUserRequest())->authorize())->toBe('company-1')
            ->and((new UpdateUserRequest())->authorize())->toBe('company-1')
            ->and(request_rule_strings($createRules['email']))->toContain('required', 'email')
            ->and(request_rule_strings($createRules['email']))->not->toContain('unique:users,email')
            ->and(request_rule_strings($createRules['phone']))->toContain('sometimes', 'nullable')
            ->and(request_rule_strings($createRules['password']))->toContain('sometimes', 'confirmed', 'string')
            ->and(request_rule_strings($updateRules['name']))->toBe(['sometimes', 'required', 'string', 'min:2', 'max:100'])
            ->and(request_rule_strings($updateRules['phone']))->toContain('sometimes', 'nullable')
            ->and(request_rule_strings($updateRules['phone']))->toContain('unique:users,phone,"user-1",uuid,deleted_at,"NULL"')
            ->and($messages['*.required'])->toBe('Your :attribute is required')
            ->and($messages['email'])->toBe('You must enter a valid :attribute')
            ->and($messages['phone.unique'])->toBe('An account with this phone number already exists')
            ->and($messages['password.required'])->toBe('You must enter a password.')
            ->and($messages['password.mixed'])->toBe('Password must contain both uppercase and lowercase letters.')
            ->and($messages['password.letters'])->toBe('Password must contain at least 1 letter.')
            ->and($messages['password.numbers'])->toBe('Password must contain at least 1 number.')
            ->and($messages['password.symbols'])->toBe('Password must contain at least 1 symbol.')
            ->and($messages['password.uncompromised'])->toBe('The password you entered has appeared in a data breach. Please choose a different one.')
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
            ->and(request_rule_strings($createComment->rules()['subject_uuid']))->toBe(['required'])
            ->and(request_rule_strings($createComment->rules()['subject_type']))->toBe(['required'])
            ->and(request_rule_strings($createComment->rules()['parent']))->toBe(['required'])
            ->and(request_rule_strings($createComment->rules()['parent_comment_uuid']))->toBe(['required'])
            ->and(request_rule_strings($createComment->rules()['content']))->toBe(['required'])
            ->and(request_rule_strings($nestedComment->rules()['subject']))->toBe([''])
            ->and(request_rule_strings($nestedComment->rules()['subject_id']))->toBe([''])
            ->and(request_rule_strings($nestedComment->rules()['subject_uuid']))->toBe([''])
            ->and(request_rule_strings($nestedComment->rules()['subject_type']))->toBe([''])
            ->and(request_rule_strings($nestedComment->rules()['parent']))->toBe([''])
            ->and(request_rule_strings($nestedComment->rules()['parent_comment_uuid']))->toBe(['required'])
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

        $internalSwitch     = route_request(SwitchOrganizationRequest::class, '/int/v1/organizations/switch');
        $publicSwitch       = route_request(SwitchOrganizationRequest::class, '/v1/organizations/switch');
        $anonymousSwitch    = request_with_session(SwitchOrganizationRequest::class, 'POST');
        $authorizedSwitch   = request_with_session(SwitchOrganizationRequest::class, 'POST', [], ['user' => 'user-1']);

        expect((new AdminRequest())->authorize())->toBeFalse()
            ->and($admin->authorize())->toBeTrue()
            ->and($nonAdmin->authorize())->toBeFalse()
            ->and($admin->rules())->toBe([])
            ->and(bind_active_request($anonymousSwitch)->authorize())->toBeFalse()
            ->and(bind_active_request($authorizedSwitch)->authorize())->toBeTrue()
            ->and(bind_active_request($internalSwitch)->rules()['next'])->toBe(['required', 'exists:companies,uuid'])
            ->and(bind_active_request($publicSwitch)->rules()['next'])->toBe(['required', 'exists:companies,public_id']);
    });

    it('keeps organization join and resend invitation request contracts session scoped', function () {
        $anonymousJoin      = request_with_session(JoinOrganizationRequest::class, 'POST');
        $authorizedJoin     = request_with_session(JoinOrganizationRequest::class, 'POST', [], ['user' => 'user-1']);
        $unauthorizedResend = request_with_session(ResendUserInvite::class, 'POST');
        $authorizedResend   = request_with_session(ResendUserInvite::class, 'POST', [], ['company' => 'company-1']);

        expect(bind_active_request($anonymousJoin)->authorize())->toBeFalse()
            ->and(bind_active_request($authorizedJoin)->authorize())->toBeTrue()
            ->and($authorizedJoin->rules())->toBe(['next' => 'required|exists:companies,public_id'])
            ->and(bind_active_request($unauthorizedResend)->authorize())->toBeFalse()
            ->and(bind_active_request($authorizedResend)->authorize())->toBeTrue()
            ->and($authorizedResend->rules())->toBe([
                'user' => ['required', 'exists:users,uuid'],
            ]);
    });

    it('keeps report request query limits formats and status transitions stable', function () {
        $createRules      = (new CreateReportRequest())->rules();
        $createMessages   = (new CreateReportRequest())->messages();
        $updateRules      = (new UpdateReportRequest())->rules();
        $validateRules    = (new ValidateReportQueryRequest())->rules();
        $validateMessages = (new ValidateReportQueryRequest())->messages();
        $executeRules     = (new ExecuteReportQueryRequest())->rules();
        $executeMessages  = (new ExecuteReportQueryRequest())->messages();
        $exportRules      = (new ExportReportRequest())->rules();

        expect((new CreateReportRequest())->authorize())->toBeTrue()
            ->and((new UpdateReportRequest())->authorize())->toBeTrue()
            ->and((new ValidateReportQueryRequest())->authorize())->toBeTrue()
            ->and((new ExecuteReportQueryRequest())->authorize())->toBeTrue()
            ->and((new ExportReportRequest())->authorize())->toBeTrue()
            ->and($createRules['title'])->toBe('required|string|max:255')
            ->and($createRules['query_config.select'])->toBe('required_with:query_config|array|min:1')
            ->and($createRules['query_config.limit'])->toBe('nullable|integer|min:1|max:10000')
            ->and($createMessages)->toBe([
                'title.required'                         => 'Report title is required',
                'type.required'                          => 'Report type is required',
                'query_config.select.required_with'      => 'At least one column must be selected',
                'query_config.from.required_with'        => 'Primary table must be specified',
                'query_config.joins.*.type.in'           => 'Join type must be one of: inner, left, right, full',
                'query_config.where.*.operator.required' => 'Where condition operator is required',
                'query_config.orderBy.*.direction.in'    => 'Order direction must be asc or desc',
                'query_config.limit.max'                 => 'Query limit cannot exceed 10,000 rows',
            ])
            ->and($updateRules['title'])->toBe('sometimes|required|string|max:255')
            ->and($updateRules['status'])->toBe('sometimes|string|in:pending,generating,complete,failed')
            ->and($validateRules['query_config.limit'])->toBe('nullable|integer|min:1|max:10000')
            ->and($validateMessages)->toBe([
                'query_config.required'                  => 'Query configuration is required',
                'query_config.select.required'           => 'At least one column must be selected',
                'query_config.from.required'             => 'Primary table must be specified',
                'query_config.joins.*.type.in'           => 'Join type must be one of: inner, left, right, full',
                'query_config.where.*.operator.required' => 'Where condition operator is required',
                'query_config.orderBy.*.direction.in'    => 'Order direction must be asc or desc',
                'query_config.limit.max'                 => 'Query limit cannot exceed 10,000 rows',
            ])
            ->and($executeRules['limit'])->toBe('nullable|integer|min:1|max:1000')
            ->and($executeRules['offset'])->toBe('nullable|integer|min:0')
            ->and($executeMessages)->toBe([
                'query_config.required'        => 'Query configuration is required',
                'query_config.select.required' => 'At least one column must be selected',
                'query_config.from.required'   => 'Primary table must be specified',
                'limit.max'                    => 'Query limit cannot exceed 1,000 rows for execution',
            ])
            ->and($exportRules['format'])->toBe('required|string|in:json,csv,xlsx')
            ->and((new ExportReportRequest())->messages()['format.in'])->toBe('Export format must be one of: json, csv, xlsx');
    });

    it('lets the public download request take a public_id', function () {
        // The internal request requires a uuid because the console works in uuids. The
        // public API addresses resources by public_id and rejects uuids, and an upload
        // returns file_xxxxxxxx — so validating the public route with the internal rules
        // meant a consumer could not download the file it had just uploaded.
        $public   = request_with_session(PublicDownloadFileRequest::class, 'GET');
        $internal = request_with_session(DownloadFileRequest::class, 'GET', [], ['user' => 'user-1']);

        $publicRules   = $public->rules();
        $internalRules = $internal->rules();

        expect(request_rule_strings($publicRules['id']))->toContain('required_without:file', 'string')
            ->and(request_rule_strings($publicRules['id']))->not->toContain('uuid')
            ->and(request_rule_strings($publicRules['file']))->not->toContain('uuid')
            // Existence stays with the controller: findRecordOrFail answers 404 for an
            // unknown file, which is the right status. An exists rule would say 422.
            ->and(request_rule_strings($publicRules['id']))->not->toContain('exists:files,uuid')
            ->and($publicRules['disk'])->toBe(['sometimes', 'string'])
            // the internal contract is deliberately unchanged
            ->and(request_rule_strings($internalRules['id']))->toContain('uuid', 'exists:files,uuid');
    });

    it('authorizes the public download request without a session user', function () {
        // The route is behind fleetbase.api, which authenticates the API credential.
        // The internal request checks for a session user, which is the wrong notion of
        // identity for a key-authenticated request and would 403 every API consumer.
        $public = request_with_session(PublicDownloadFileRequest::class, 'GET');

        expect(bind_active_request($public)->authorize())->toBeTrue();
    });

    it('merges the route id into the public download request', function () {
        $routeDownload = request_with_route_parameter(PublicDownloadFileRequest::class, 'id', 'file_abc123xyz');
        $prepare       = new ReflectionMethod(PublicDownloadFileRequest::class, 'prepareForValidation');

        $prepare->setAccessible(true);
        $prepare->invoke($routeDownload);

        expect($routeDownload->input('id'))->toBe('file_abc123xyz')
            ->and((new PublicDownloadFileRequest())->messages()['id.required_without'])->toBe('Please provide a file identifier.')
            ->and((new PublicDownloadFileRequest())->messages()['id.string'])->toBe('The file identifier must be a string.')
            ->and((new PublicDownloadFileRequest())->messages()['disk.string'])->toBe('The storage disk must be a valid string.');
    });

    it('keeps internal file upload and download request contracts stable', function () {
        $unauthorizedUpload = request_with_session(UploadFileRequest::class, 'POST');
        $authorizedUpload   = request_with_session(UploadFileRequest::class, 'POST', [], ['user' => 'user-1']);
        $base64Upload       = request_with_session(UploadBase64FileRequest::class, 'POST', [], ['user' => 'user-1']);
        $download           = request_with_session(DownloadFileRequest::class, 'GET', [], ['user' => 'user-1']);
        $routeDownload      = request_with_route_parameter(DownloadFileRequest::class, 'id', '11111111-1111-4111-8111-111111111111');
        $prepareDownload    = new ReflectionMethod(DownloadFileRequest::class, 'prepareForValidation');

        $prepareDownload->setAccessible(true);
        $prepareDownload->invoke($routeDownload);

        $uploadRules   = $authorizedUpload->rules();
        $base64Rules   = $base64Upload->rules();
        $downloadRules = $download->rules();

        expect(bind_active_request($unauthorizedUpload)->authorize())->toBeFalse()
            ->and(bind_active_request($authorizedUpload)->authorize())->toBeTrue()
            ->and(request_rule_strings($uploadRules['file']))->toContain('required', 'file', 'max:104857600')
            ->and(request_rule_strings($uploadRules['file'])[3])->toContain('image/jpeg')
            ->and(request_rule_strings($uploadRules['file'])[3])->toContain('application/pdf')
            ->and($uploadRules['resize'])->toBe('nullable|string|in:thumb,sm,md,lg,xl,2xl')
            ->and($uploadRules['resize_width'])->toBe('nullable|integer|min:1|max:10000')
            ->and($uploadRules['resize_upscale'])->toBe('nullable|boolean')
            ->and($authorizedUpload->messages()['file.required'])->toBe('Please select a file to upload.')
            ->and($authorizedUpload->messages()['resize_mode.in'])->toContain('fit, crop, stretch, contain')
            ->and(bind_active_request($base64Upload)->authorize())->toBeTrue()
            ->and($base64Rules['data'])->toBe(['required'])
            ->and($base64Rules['file_name'])->toBe(['required'])
            ->and($base64Rules['subject_uuid'])->toBe(['nullable', 'string'])
            ->and($base64Rules['resize_format'])->toBe('nullable|string|in:jpg,jpeg,png,webp,gif,bmp,avif')
            ->and($base64Upload->messages()['data.required'])->toBe('Please provide a base64 encoded file.')
            ->and(bind_active_request($download)->authorize())->toBeTrue()
            ->and($downloadRules['file'])->toBe(['required_without:id', 'uuid', 'exists:files,uuid'])
            ->and($downloadRules['id'])->toBe(['required_without:file', 'uuid', 'exists:files,uuid'])
            ->and($downloadRules['disk'])->toBe(['sometimes', 'string'])
            ->and($routeDownload->input('id'))->toBe('11111111-1111-4111-8111-111111111111')
            ->and($download->messages()['file.exists'])->toBe('The requested file does not exist.');
    });

    it('keeps internal password validation and reset request contracts stable', function () {
        $user = new class {
            public array $checked = [];

            public function checkPassword(string $password): bool
            {
                $this->checked[] = $password;

                return $password === 'CurrentPass1!';
            }
        };
        $validate = ValidatePasswordRequest::create('/int/v1/auth/validate-password', 'POST');
        $validate->setUserResolver(fn () => $user);

        $validateRules = $validate->rules();
        $resetRules    = (new ResetPasswordRequest())->rules();
        $confirmRule   = new ConfirmCurrentPassword($user);

        expect((new ValidatePasswordRequest())->authorize())->toBeTrue()
            ->and(request_rule_strings($validateRules['password']))->toContain('required', 'string', 'confirmed')
            ->and($validateRules['password'][4])->toBeInstanceOf(ConfirmCurrentPassword::class)
            ->and($validateRules['password_confirmation'])->toBe(['required', 'string'])
            ->and($confirmRule->passes('password', 'CurrentPass1!'))->toBeTrue()
            ->and($confirmRule->passes('password', 'wrong'))->toBeFalse()
            ->and((new ConfirmCurrentPassword(null))->passes('password', 'CurrentPass1!'))->toBeFalse()
            ->and($confirmRule->message())->toBe('The current password provided is invalid.')
            ->and($validate->messages()['password.uncompromised'])->toContain('data breach')
            ->and((new ResetPasswordRequest())->authorize())->toBeTrue()
            ->and($resetRules['code'])->toBe(['required', 'exists:verification_codes,code'])
            ->and($resetRules['link'])->toBe(['required', 'exists:verification_codes,uuid'])
            ->and(request_rule_strings($resetRules['password']))->toContain('required', 'confirmed', 'string')
            ->and($resetRules['password_confirmation'])->toBe(['required', 'string'])
            ->and((new ResetPasswordRequest())->messages()['code'])->toBe('Invalid password reset request!')
            ->and((new ResetPasswordRequest())->messages()['password.required'])->toBe('You must enter a password.');
    });

    it('keeps current user credential change request contracts strict', function () {
        $user = new class {
            public string $uuid   = 'user-1';
            public array $checked = [];

            public function checkPassword(string $password): bool
            {
                $this->checked[] = $password;

                return $password === 'CurrentPass1!';
            }
        };

        $emailRequest = ChangeCurrentUserEmailRequest::create('/int/v1/auth/change-email', 'POST', [
            'email'    => 'new@example.test',
            'password' => 'wrong',
        ]);
        $emailRequest->setUserResolver(fn () => $user);

        $validator = new class {
            public array $callbacks = [];
            public array $errors    = [];

            public function after(callable $callback): void
            {
                $this->callbacks[] = $callback;
            }

            public function errors(): object
            {
                return new class($this) {
                    public function __construct(private object $validator)
                    {
                    }

                    public function add(string $key, string $message): void
                    {
                        $this->validator->errors[$key][] = $message;
                    }
                };
            }
        };

        $emailRequest->withValidator($validator);
        $validator->callbacks[0]($validator);

        $emailRules     = $emailRequest->rules();
        $passwordRules  = (new UpdatePasswordRequest())->rules();
        $emailMessages  = $emailRequest->messages();
        $passwordErrors = (new UpdatePasswordRequest())->messages();

        expect($emailRequest->authorize())->toBe($user)
            ->and(request_rule_strings($emailRules['email']))->toContain('required', 'email')
            ->and(request_rule_strings($emailRules['email']))->toContain('unique:users,email,"user-1",uuid,deleted_at,"NULL"')
            ->and($emailRules['password'])->toBe(['required', 'string'])
            ->and($validator->errors['password'][0])->toBe('The current password provided is invalid.')
            ->and($user->checked)->toBe(['wrong'])
            ->and($emailMessages['email.required'])->toBe('A new email address is required.')
            ->and($emailMessages['email.unique'])->toBe('An account with this email address already exists.')
            ->and((new ChangeCurrentUserEmailRequest())->authorize())->toBeNull()
            ->and((new UpdatePasswordRequest())->authorize())->toBeNull()
            ->and(request_rule_strings($passwordRules['password']))->toContain('required', 'confirmed', 'string')
            ->and($passwordRules['password_confirmation'])->toBe(['required', 'string'])
            ->and($passwordErrors['password.symbols'])->toBe('Password must contain at least one symbol.')
            ->and($passwordErrors['password.uncompromised'])->toContain('data breach');
    });

    it('keeps administrative user email and invite request contracts stable', function () {
        session()->flush();

        $unauthorizedEmailChange       = new ChangeUserEmailRequest();
        $unauthorizedEmailChangeResult = $unauthorizedEmailChange->authorize();
        $authorizedEmailChange         = new ChangeUserEmailRequest();
        session(['company' => 'company-1']);

        $inviteUnauthorized = request_with_session(InviteUserRequest::class, 'POST');
        $inviteAuthorized   = request_with_session(InviteUserRequest::class, 'POST', [], ['company' => 'company-1']);
        $emailRules         = $authorizedEmailChange->rules();
        $emailRuleText      = implode('|', request_rule_strings($emailRules['email']));
        $inviteRules        = $inviteAuthorized->rules();

        expect($unauthorizedEmailChangeResult)->toBeNull()
            ->and($authorizedEmailChange->authorize())->toBe('company-1')
            ->and(request_rule_strings($emailRules['email']))->toContain('required', 'email')
            ->and($emailRuleText)->toContain('unique:users,email')
            ->and($emailRuleText)->toContain('deleted_at')
            ->and($authorizedEmailChange->messages()['email.required'])->toBe('A new email address is required.')
            ->and($authorizedEmailChange->messages()['email.email'])->toBe('You must enter a valid email address.')
            ->and($authorizedEmailChange->messages()['email.unique'])->toBe('An account with this email address already exists.')
            ->and(bind_active_request($inviteUnauthorized)->authorize())->toBeFalse()
            ->and(bind_active_request($inviteAuthorized)->authorize())->toBeTrue()
            ->and($inviteRules['user.email'])->toBe('required|email')
            ->and($inviteRules['user.name'])->toBe('required')
            ->and($inviteAuthorized->attributes())->toBe([
                'user.email' => 'email address',
                'user.name'  => 'name',
            ]);
    });

    it('keeps bulk action delete and category request contracts stable', function () {
        $bulkUnauthorized     = request_with_session(BulkActionRequest::class, 'POST');
        $bulkAuthorized       = request_with_session(BulkActionRequest::class, 'POST', [], ['user' => 'user-1']);
        $deleteUnauthorized   = request_with_session(BulkDeleteRequest::class, 'DELETE');
        $deleteAuthorized     = request_with_session(BulkDeleteRequest::class, 'DELETE', [], ['user' => 'user-1']);
        $categoryUnauthorized = request_with_session(CreateCategoryRequest::class, 'POST');
        $categoryAuthorized   = request_with_session(CreateCategoryRequest::class, 'POST', [], ['company' => 'company-1']);

        expect(bind_active_request($bulkUnauthorized)->authorize())->toBeFalse()
            ->and(bind_active_request($bulkAuthorized)->authorize())->toBeTrue()
            ->and($bulkAuthorized->rules())->toBe(['ids' => ['required', 'array']])
            ->and($bulkAuthorized->messages())->toBe([
                'ids.required' => 'Please provide a resource ID.',
                'ids.array'    => 'Please provide multiple resource ID\'s.',
            ])
            ->and(bind_active_request($deleteUnauthorized)->authorize())->toBeFalse()
            ->and(bind_active_request($deleteAuthorized)->authorize())->toBeTrue()
            ->and($deleteAuthorized->rules())->toBe(['ids' => ['required', 'array']])
            ->and($deleteAuthorized->messages())->toBe($bulkAuthorized->messages())
            ->and(bind_active_request($categoryUnauthorized)->authorize())->toBeFalse()
            ->and(bind_active_request($categoryAuthorized)->authorize())->toBeTrue()
            ->and($categoryAuthorized->rules())->toBe(['name' => 'required|min:3'])
            ->and($categoryAuthorized->messages())->toBe([
                'name.required' => 'The category name is required.',
                'name.min'      => 'The category name must be at least 3 characters.',
            ]);
    });

    it('keeps internal custom field request authorization and rules explicit', function () {
        $unauthorized = request_with_session(CreateCustomFieldRequest::class, 'POST');
        $authorized   = request_with_session(CreateCustomFieldRequest::class, 'POST', [], ['company' => 'company-1']);
        $rules        = $authorized->rules();

        expect(bind_active_request($unauthorized)->authorize())->toBeFalse()
            ->and(bind_active_request($authorized)->authorize())->toBeTrue()
            ->and($rules['company_uuid'])->toBe(['nullable', 'uuid', 'exists:companies,uuid'])
            ->and($rules['category_uuid'])->toBe(['nullable', 'uuid', 'exists:categories,uuid'])
            ->and($rules['label'])->toBe(['required', 'string', 'max:255'])
            ->and($rules['type'])->toBe(['required', 'string', 'max:50'])
            ->and($rules['options'])->toBe(['nullable', 'array'])
            ->and($rules['required'])->toBe(['sometimes', 'boolean'])
            ->and($rules['validation_rules'])->toBe(['nullable', 'array'])
            ->and($rules['order'])->toBe(['nullable', 'integer'])
            ->and($authorized->messages()['type.required'])->toBe('A custom field type is required (e.g., text, number, date, etc.).')
            ->and($authorized->messages()['type.string'])->toBe('The custom field type must be a valid string.');
    });

    it('keeps import and template request validation contracts explicit', function () {
        $capsule = request_contract_files_database();
        $capsule->getConnection('mysql')->table('files')->insert([
            ['uuid' => 'file-csv', 'path' => 'imports/orders.csv', 'original_filename' => 'orders.csv', 'deleted_at' => null],
            ['uuid' => 'file-pdf', 'path' => 'imports/orders.pdf', 'original_filename' => 'orders.pdf', 'deleted_at' => null],
        ]);

        $unauthorizedImport = request_with_session(ImportRequest::class, 'POST');
        $authorizedImport   = request_with_session(ImportRequest::class, 'POST', [], ['user' => 'user-1']);
        $importRules        = $authorizedImport->rules();
        $fileRule           = $importRules['files'][3];
        $errors             = [];

        $fileRule('files', ['missing-file'], function (string $message) use (&$errors) {
            $errors[] = $message;
        });
        $fileRule('files', ['file-pdf'], function (string $message) use (&$errors) {
            $errors[] = $message;
        });
        $fileRule('files', ['file-csv'], function (string $message) use (&$errors) {
            $errors[] = $message;
        });

        $templateAuthorized   = request_with_session(CreateTemplateRequest::class, 'POST', [], ['company' => 'company-1']);
        $templateUnauthorized = request_with_session(CreateTemplateRequest::class, 'POST');
        $templateRules        = $templateAuthorized->rules();
        $templateMessages     = $templateAuthorized->messages();

        expect(bind_active_request($unauthorizedImport)->authorize())->toBeFalse()
            ->and(bind_active_request($authorizedImport)->authorize())->toBeTrue()
            ->and($importRules['files'][0])->toBe('required')
            ->and($importRules['files'][1])->toBe('array')
            ->and($importRules['files'][2])->toBe('exists:files,uuid')
            ->and($importRules['files'][3])->toBeInstanceOf(Closure::class)
            ->and($errors)->toBe([
                'One of the files sent for import is invalid.',
                'The file (orders.pdf) format with the extension pdf is not valid for import.',
            ])
            ->and(bind_active_request($templateUnauthorized)->authorize())->toBeFalse()
            ->and(bind_active_request($templateAuthorized)->authorize())->toBeTrue()
            ->and($templateRules['name'])->toBe('required|min:2|max:191')
            ->and($templateRules['context_type'])->toBe('required|string|max:191')
            ->and($templateRules['orientation'])->toBe('nullable|in:portrait,landscape')
            ->and($templateRules['unit'])->toBe('nullable|in:mm,px,in')
            ->and($templateRules['width'])->toBe('nullable|numeric|min:1')
            ->and($templateMessages['name.required'])->toBe('A template name is required.')
            ->and($templateMessages['context_type.required'])->toBe('A context type is required to determine which variables are available.');
    });

    it('formats fleetbase validation failures differently for internal and public routes', function () {
        $internal = RequestContractsFleetbaseRequestProbe::create('/int/v1/test', 'POST');
        $internal->setRouteResolver(fn () => new class {
            public array $action = [];

            public function uri(): string
            {
                return 'int/v1/test';
            }
        });
        Container::getInstance()->instance('request', $internal);

        try {
            $internal->triggerValidationResponse(new RequestContractsValidatorFake([
                'name'  => ['Name is required.'],
                'email' => ['Email is invalid.'],
            ]));
        } catch (ValidationException $exception) {
            $internalResponse = $exception->response;
        }

        $public = RequestContractsFleetbaseRequestProbe::create('/v1/test', 'POST');
        $public->setRouteResolver(fn () => new class {
            public array $action = [];

            public function uri(): string
            {
                return 'v1/test';
            }
        });
        Container::getInstance()->instance('request', $public);

        try {
            $public->triggerValidationResponse(new RequestContractsValidatorFake([
                'name'  => ['Name is required.'],
                'email' => ['Email is invalid.'],
            ]));
        } catch (ValidationException $exception) {
            $publicResponse = $exception->response;
        }

        expect($internalResponse->getStatusCode())->toBe(422)
            ->and($internalResponse->getData(true))->toBe([
                'errors' => ['Name is required.', 'Email is invalid.'],
            ])
            ->and($publicResponse->getStatusCode())->toBe(422)
            ->and($publicResponse->getData(true))->toBe([
                'error'  => 'Name is required.',
                'errors' => ['Name is required.', 'Email is invalid.'],
            ]);
    });
}

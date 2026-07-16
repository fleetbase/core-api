<?php

namespace Illuminate\Foundation\Auth\Access {
    trait AuthorizesRequests
    {
    }
}

namespace Illuminate\Foundation\Bus {
    trait DispatchesJobs
    {
    }
}

namespace Illuminate\Foundation\Validation {
    trait ValidatesRequests
    {
    }
}

namespace Illuminate\Foundation\Http {
    class FormRequest extends \Illuminate\Http\Request
    {
    }
}

namespace Fleetbase\Tests\SecurityFixtures {
    class TemplateQueryTenantModel extends \Fleetbase\Models\Model
    {
        protected $table = 'template_query_tenant_models';
    }
}

namespace {
    use Fleetbase\Http\Controllers\Internal\v1\ApiCredentialController;
    use Fleetbase\Http\Controllers\Internal\v1\ChatChannelController;
    use Fleetbase\Http\Controllers\Internal\v1\CompanyController;
    use Fleetbase\Http\Controllers\Internal\v1\FileController;
    use Fleetbase\Http\Controllers\Internal\v1\NotificationController;
    use Fleetbase\Http\Controllers\Internal\v1\PolicyController;
    use Fleetbase\Http\Controllers\Internal\v1\ReportController;
    use Fleetbase\Http\Controllers\Internal\v1\RoleController;
    use Fleetbase\Http\Controllers\Internal\v1\ScheduleExceptionController;
    use Fleetbase\Http\Controllers\Internal\v1\ScheduleTemplateController;
    use Fleetbase\Http\Controllers\Internal\v1\TemplateController;
    use Fleetbase\Http\Controllers\Internal\v1\UserController;
    use Fleetbase\Http\Requests\Internal\UserForgotPasswordRequest;
    use Fleetbase\Http\Requests\Internal\WebhookEndpointRequest;
    use Fleetbase\Models\Company;
    use Fleetbase\Models\TemplateQuery;
    use Fleetbase\Rules\PublicWebhookUrl;
    use Fleetbase\Services\TemplateRenderService;
    use Fleetbase\Tests\SecurityFixtures\TemplateQueryTenantModel;
    use Illuminate\Config\Repository;
    use Illuminate\Container\Container;

    function controller_source(string $class): string
    {
        $reflection = new ReflectionClass($class);

        return file_get_contents($reflection->getFileName());
    }

    function bind_security_findings_config(array $values = []): void
    {
        Container::getInstance()->instance('config', new Repository($values));
    }

    beforeEach(function () {
        bind_security_findings_config([
            'fleetbase.template_query_models'        => [],
            'fleetbase.template_global_query_models' => [],
        ]);
    });

    test('forgot password request does not expose a registered email oracle', function () {
        $rules = (new UserForgotPasswordRequest())->rules();

        expect($rules['email'])->toBe(['required', 'email'])
            ->and($rules['email'])->not->toContain('exists:users,email');
    });

    test('company controller owns generic company rest operations', function () {
        $reflection = new ReflectionClass(CompanyController::class);

        expect($reflection->getMethod('findRecord')->getDeclaringClass()->getName())->toBe(CompanyController::class)
            ->and($reflection->getMethod('updateRecord')->getDeclaringClass()->getName())->toBe(CompanyController::class)
            ->and($reflection->getMethod('deleteRecord')->getDeclaringClass()->getName())->toBe(CompanyController::class);
    });

    test('api credential roll resolves credentials inside the active company', function () {
        $source = controller_source(ApiCredentialController::class);

        expect($source)->toContain("ApiCredential::where('uuid', \$id)")
            ->and($source)->toContain("->where('company_uuid', session('company'))")
            ->and($source)->not->toContain('ApiCredential::find($id)');
    });

    test('policy deletion resolves only organization managed policies inside the active company', function () {
        $source = controller_source(PolicyController::class);

        expect($source)->toContain("Policy::where('id', \$id)")
            ->and($source)->toContain("->where('company_uuid', session('company'))")
            ->and($source)->not->toContain('Policy::find($id)');
    });

    test('notification mutations are scoped to the authenticated user', function () {
        $source = controller_source(NotificationController::class);

        expect($source)->toContain("Notification::where('notifiable_id', session('user'))")
            ->and($source)->toContain("->where('notifiable_type', User::class)")
            ->and($source)->toContain('public function deleteRecord($id, Request $request)')
            ->and($source)->not->toContain("Notification::where('id', \$id)->first()")
            ->and($source)->not->toContain('Notification::find($notificationId)')
            ->and($source)->not->toContain('Notification::whereIn(\'id\', $notifications)->delete()');
    });

    test('company ownership transfer and leave bind to session company and authenticated user', function () {
        $source = controller_source(CompanyController::class);

        expect($source)->toContain("\$sessionCompany = session('company')")
            ->and($source)->toContain("Company::where('uuid', \$sessionCompany)->first()")
            ->and($source)->toContain('Only the organization owner can transfer ownership.')
            ->and($source)->toContain('Select a different organization member before leaving.')
            ->and($source)->toContain('Transfer ownership before leaving the organization.')
            ->and($source)->not->toContain('Str::isUuid($currentUserId) ? User::where')
            ->and($source)->not->toContain("Company::where('uuid', \$companyId)->first()");
    });

    test('user management mutations resolve users and iam records inside the active company', function () {
        $source = controller_source(UserController::class);

        expect($source)->toContain('$this->resolveVisibleUser($id, request())')
            ->and($source)->toContain('$this->resolveAssignableRole(')
            ->and($source)->toContain('$this->getAssignablePolicies(')
            ->and($source)->toContain('$userCompanies->first()?->company_uuid === $company->uuid')
            ->and($source)->not->toContain('$user = User::where(\'uuid\', $id)->first();');
    });

    test('role policy attachment is limited to active company or managed policies', function () {
        $source = controller_source(RoleController::class);

        expect($source)->toContain('$this->getAssignablePolicies(')
            ->and($source)->toContain("->where('company_uuid', session('company'))")
            ->and($source)->toContain('->orWhereNull(\'company_uuid\')')
            ->and($source)->not->toContain('Policy::whereIn(\'id\', $request->array(\'role.policies\'))->get()');
    });

    test('file download resolves files inside the active company and uses stored disk', function () {
        $source = controller_source(FileController::class);

        expect($source)->toContain("->where('company_uuid', session('company'))")
            ->and($source)->toContain('$disk     = $file->disk ?: config(\'filesystems.default\');')
            ->and($source)->not->toContain("\$disk     = \$request->input('disk'");
    });

    test('scheduling custom actions are scoped to the active company', function () {
        $exceptionSource = controller_source(ScheduleExceptionController::class);
        $templateSource  = controller_source(ScheduleTemplateController::class);

        expect($exceptionSource)->toContain('scheduleExceptionQuery')
            ->and($exceptionSource)->toContain("ScheduleException::where('company_uuid', session('company'))")
            ->and($templateSource)->toContain('scheduleTemplateQuery')
            ->and($templateSource)->toContain('scheduleQuery')
            ->and($templateSource)->toContain("ScheduleTemplate::where('company_uuid', session('company'))")
            ->and($templateSource)->toContain("Schedule::where('company_uuid', session('company'))");
    });

    test('template and report custom actions are scoped to active company subjects', function () {
        $templateSource = controller_source(TemplateController::class);
        $reportSource   = controller_source(ReportController::class);

        expect($templateSource)->toContain('templateQuery')
            ->and($templateSource)->toContain("Template::where('company_uuid', session('company'))")
            ->and($templateSource)->toContain('resolveTemplateSubject')
            ->and($templateSource)->toContain('isAllowedSubjectModel')
            ->and($templateSource)->toContain('modelHasCompanyColumn')
            ->and($templateSource)->not->toContain('$subjectType::where(\'uuid\', $subjectId)')
            ->and($reportSource)->toContain('reportQuery')
            ->and($reportSource)->toContain("->where('company_uuid', session('company'))");
    });

    test('template query model allowlist excludes company by default', function () {
        TemplateRenderService::registerContextType('security_fixture_tenant_model', [
            'label'       => 'Security Fixture',
            'description' => 'Security fixture for template query allowlist tests.',
            'model'       => TemplateQueryTenantModel::class,
            'variables'   => [],
        ]);

        expect(TemplateRenderService::isTemplateQueryModelAllowed(TemplateQueryTenantModel::class))->toBeTrue()
            ->and(TemplateRenderService::isTemplateQueryModelAllowed(Company::class))->toBeFalse();
    });

    test('template query execution rejects the reported company model vector', function () {
        $query = new TemplateQuery();
        $query->fill([
            'company_uuid'    => 'company-current',
            'variable_name'   => 'orgs',
            'model_type'      => Company::class,
            'conditions'      => [],
            'limit'           => 500,
        ]);

        expect($query->execute())->toBeEmpty();
    });

    test('template query execution enforces allowlist and schema backed tenant scoping', function () {
        $source = file_get_contents((new ReflectionClass(TemplateQuery::class))->getFileName());

        expect($source)->toContain('TemplateRenderService::isTemplateQueryModelAllowed($modelClass)')
            ->and($source)->toContain('modelHasCompanyColumn')
            ->and($source)->toContain("\$companyUuid = \$this->company_uuid ?: session('company')")
            ->and($source)->toContain("\$model->qualifyColumn('company_uuid')")
            ->and($source)->toContain('TemplateRenderService::isTemplateQueryModelGloballyQueryable($modelClass)')
            ->and($source)->not->toContain('getFillable()');
    });

    test('unsaved template preview binds transient queries to the active company', function () {
        $templateSource = controller_source(TemplateController::class);

        expect($templateSource)->toContain("'company_uuid'   => session('company')")
            ->and($templateSource)->toContain("'model_type'")
            ->and($templateSource)->toContain("data_get(\$q, 'model_type')");
    });

    test('chat custom actions bind users and channels to active company membership', function () {
        $source = controller_source(ChatChannelController::class);

        expect($source)->toContain('companyUserQuery')
            ->and($source)->toContain('companyChatChannelQuery')
            ->and($source)->toContain("ChatChannel::where('company_uuid', session('company'))")
            ->and($source)->toContain("->whereHas('participants'")
            ->and($source)->not->toContain("User::where('uuid', \$userId)->orWhere('public_id', \$userId)->first()")
            ->and($source)->not->toContain("ChatChannel::where('uuid', \$channelId)->first()");
    });

    test('webhook endpoint request validates webhook urls on create and update', function () {
        $createRequest = WebhookEndpointRequest::create('/int/v1/webhook-endpoints', 'POST');
        $updateRequest = WebhookEndpointRequest::create('/int/v1/webhook-endpoints/webhook_123', 'PUT');

        expect($createRequest->rules()['url'][0])->toBe('required')
            ->and($updateRequest->rules()['url'][0])->toBe('sometimes');
    });

    test('public webhook url rule accepts public http and https urls', function () {
        $rule = new PublicWebhookUrl();

        expect($rule->passes('url', 'https://93.184.216.34/hooks/fleetbase'))->toBeTrue()
            ->and($rule->passes('url', 'http://93.184.216.34/hooks/fleetbase'))->toBeTrue();
    });

    test('public webhook url rule rejects private and metadata urls', function (string $url) {
        $rule = new PublicWebhookUrl();

        expect($rule->passes('url', $url))->toBeFalse();
    })->with([
        'aws metadata' => ['http://169.254.169.254/latest/meta-data/'],
        'localhost'    => ['http://localhost/hook'],
        'loopback v4'  => ['http://127.0.0.1/hook'],
        'loopback v6'  => ['http://[::1]/hook'],
        'private 10'   => ['https://10.0.0.1/hook'],
        'private 172'  => ['https://172.16.0.1/hook'],
        'private 192'  => ['https://192.168.0.1/hook'],
        'link local'   => ['https://169.254.10.20/hook'],
    ]);
}

<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Exports\ApiCredentialExport;
use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class ApiCredentialController extends FleetbaseController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'api_credential';

    /**
     * The service which this controller belongs to.
     *
     * @var string
     */
    public $service = 'developers';

    /**
     * Create a new API credential record.
     *
     * Overrides the generic createRecord to ensure that when a test/sandbox key
     * is being created, the current user and company are mirrored into the sandbox
     * database first. Without this, the foreign key constraint on `api_credentials.user_uuid`
     * (which references `users.uuid` in the sandbox DB) will fail because the user
     * and company rows only exist in the production database.
     *
     * @return \Illuminate\Http\Response
     */
    public function createRecord(Request $request)
    {
        // Determine if this is a sandbox/test key creation request.
        $isSandbox = \Fleetbase\Support\Utils::isTrue($request->header('Access-Console-Sandbox'));

        if ($isSandbox) {
            // Ensure the current user and company exist in the sandbox DB before
            // attempting the insert, so the FK constraints are satisfied.
            $this->syncCurrentSessionToSandbox($request);
        }

        return parent::createRecord($request);
    }

    /**
     * Mirrors the currently authenticated user, their company, and the company–user
     * membership record into the sandbox database.
     *
     * This is a targeted, on-demand version of the `sandbox:sync` Artisan command,
     * scoped only to the records needed to satisfy the foreign key constraints when
     * inserting a new test-mode `api_credentials` row.
     *
     * @param Request $request
     *
     * @return void
     */
    protected function syncCurrentSessionToSandbox(Request $request): void
    {
        $userUuid    = session('user');
        $companyUuid = session('company');

        if (!$userUuid || !$companyUuid) {
            return;
        }

        // Temporarily disable FK checks so we can upsert in any order.
        Schema::connection('sandbox')->disableForeignKeyConstraints();

        try {
            // --- Sync User ---
            $user = User::on('mysql')->withoutGlobalScopes()->where('uuid', $userUuid)->first();
            if ($user) {
                $this->upsertModelToSandbox($user);
            }

            // --- Sync Company ---
            $company = Company::on('mysql')->withoutGlobalScopes()->where('uuid', $companyUuid)->first();
            if ($company) {
                $this->upsertModelToSandbox($company);
            }

            // --- Sync CompanyUser pivot ---
            $companyUser = CompanyUser::on('mysql')
                ->withoutGlobalScopes()
                ->where('user_uuid', $userUuid)
                ->where('company_uuid', $companyUuid)
                ->first();
            if ($companyUser) {
                $this->upsertModelToSandbox($companyUser);
            }
        } finally {
            Schema::connection('sandbox')->enableForeignKeyConstraints();
        }
    }

    /**
     * Upserts a single Eloquent model record into the sandbox database.
     *
     * Mirrors the approach used in the `sandbox:sync` Artisan command:
     * reduces the record to its fillable attributes, normalises datetime
     * fields to strings, JSON-encodes any Json-cast columns, then performs
     * an `updateOrInsert` keyed on `uuid`.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     *
     * @return void
     */
    protected function upsertModelToSandbox(\Illuminate\Database\Eloquent\Model $model): void
    {
        $clone = collect($model->toArray())
            ->only($model->getFillable())
            ->toArray();

        if (!isset($clone['uuid']) || !is_string($clone['uuid'])) {
            return;
        }

        // Normalise datetime columns to plain strings.
        foreach ($clone as $key => $value) {
            if (isset($clone[$key]) && Str::endsWith($key, '_at')) {
                try {
                    $clone[$key] = Carbon::parse($clone[$key])->toDateTimeString();
                } catch (\Exception $e) {
                    $clone[$key] = null;
                }
            }
        }

        // JSON-encode any Json-cast columns that are still arrays/objects.
        $jsonColumns = collect($model->getCasts())
            ->filter(fn ($cast) => Str::contains($cast, 'Json'))
            ->keys()
            ->toArray();

        foreach ($clone as $key => $value) {
            if (in_array($key, $jsonColumns) && (is_object($value) || is_array($value))) {
                $clone[$key] = json_encode($value);
            }
        }

        DB::connection('sandbox')
            ->table($model->getTable())
            ->updateOrInsert(['uuid' => $clone['uuid']], $clone);
    }

    /**
     * Export the companies/users api credentials to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public static function export(ExportRequest $request)
    {
        $format   = $request->input('format', 'xlsx');
        $fileName = trim(Str::slug('api-credentials-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new ApiCredentialExport(), $fileName);
    }

    /**
     * Rolls an API key.
     *
     * @return \Illuminate\Http\Response
     */
    public static function roll($id, Request $request)
    {
        // get incoming params
        $password   = $request->input('password');
        $expiration = $request->input('expiration');
        $user       = $request->user();

        // authenticate the users request
        if (!$user || !Auth::validate(['email' => $user->email, 'password' => $password, 'request' => $request])) {
            return response()->error('Authentication required to roll key failed.', 401);
        }

        // get the api key to roll
        $apiCredential = ApiCredential::find($id);

        // if no api key respond with error
        if (!$apiCredential) {
            return response()->error('API credential attempted to roll could not be found.');
        }

        // create api credentials seed
        $seed = array_map('intval', str_split(time() . $apiCredential->id));

        // regenerate api key
        $newCredentials = ApiCredential::generateKeys($seed, $apiCredential->test_mode);

        // store the previous key
        $previousApiKey = $apiCredential->key;

        // update credentials
        $apiCredential->key    = data_get($newCredentials, 'key');
        $apiCredential->secret = data_get($newCredentials, 'secret');

        // update expiration if applicable
        if ($expiration) {
            $apiCredential->expires_at = $expiration;
        }

        try {
            $apiCredential->save();
        } catch (\Exception|\Illuminate\Database\QueryException $e) {
            return response()->error('Attempt to roll key failed.');
        }

        // update all resources
        $tables = DB::connection('sandbox')
            ->getDoctrineSchemaManager()
            ->listTableNames();

        // replace all resources created with this key with the new api key
        foreach ($tables as $table) {
            if (in_array($table, ApiCredential::$skipTables) || Str::startsWith($table, 'telescope')) {
                continue;
            }
            DB::connection('sandbox')
                ->table($table)
                ->where('_key', $previousApiKey)
                ->update(['_key' => $apiCredential->key]);
        }

        return response()->json(
            [
                'apiCredential' => $apiCredential,
            ]
        );
    }
}

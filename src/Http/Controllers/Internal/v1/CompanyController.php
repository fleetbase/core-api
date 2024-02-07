<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Http\Resources\Organization;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\Invite;
use Fleetbase\Models\User;
use Fleetbase\Support\Auth;
use Fleetbase\Support\TwoFactorAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CompanyController extends FleetbaseController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'company';

    /**
     * Find company by public_id or invitation code.
     *
     * @return \Illuminate\Http\Response
     */
    public function findCompany(string $id)
    {
        $id         = trim($id);
        $isPublicId = Str::startsWith($id, ['company_']);

        if ($isPublicId) {
            $company = Company::where('public_id', $id)->first();
        } else {
            $invite = Invite::where(['uri' => $id, 'reason' => 'join_company'])->with(['subject'])->first();

            if ($invite) {
                $company = $invite->subject;
            }
        }

        return new Organization($company);
    }

    /**
     * Get the current organization's two factor authentication settings.
     *
     * @return \Illuminate\Http\Response
     */
    public function getTwoFactorSettings()
    {
        $company = Auth::getCompany();

        if (!$company) {
            return response()->error('No company session found', 401);
        }

        $twoFaSettings = TwoFactorAuth::getTwoFaSettingsForCompany($company);

        return response()->json($twoFaSettings->value);
    }

    /**
     * Save the two factor authentication settings for the current company.
     *
     * @param Request $request the HTTP request
     *
     * @return \Illuminate\Http\Response
     */
    public function saveTwoFactorSettings(Request $request)
    {
        $twoFaSettings = $request->array('twoFaSettings');
        $company       = Auth::getCompany();

        if (!$company) {
            return response()->error('No company session found', 401);
        }
        if (isset($twoFaSettings['enabled']) && $twoFaSettings['enabled'] === false) {
            $twoFaSettings['enforced'] = false;
        }
        TwoFactorAuth::saveTwoFaSettingsForCompany($company, $twoFaSettings);

        return response()->json(['message' => 'Two-Factor Authentication saved successfully']);
    }

    /**
     * Get all users for a company.
     *
     * @param string $id The company id
     *
     * @return \Illuminate\Http\Response
     */
    public function users(string $id, Request $request)
    {
        $searchQuery = $request->searchQuery();
        $limit       = $request->input(['limit', 'nestedLimit'], 20);
        // $page = $request->or(['page', 'nestedPage'], 1);
        $paginate = $request->boolean('paginate');

        // Start user query
        $usersQuery = CompanyUser::whereHas('company', function ($query) use ($id) {
            $query->where('public_id', $id);
            $query->orWhere('uuid', $id);
        })->with(['user']);

        // Search query
        if ($searchQuery) {
            $usersQuery->whereHas('user', function ($query) use ($searchQuery) {
                $query->search($searchQuery);
            });
        }

        // Sort query
        $usersQuery->applySortFromRequest($request);

        // paginate results
        if ($paginate) {
            $users = $usersQuery->fastPaginate($limit);

            // fix results
            $transformedItems = $users->getCollection()->map(function ($companyUser) {
                return $companyUser->user;
            });

            // replace in pagination
            $users->setCollection($transformedItems);

            return response()->json([
                'users' => $users->getCollection(),
                'meta'  => [
                    'current_page' => $users->currentPage(),
                    'from'         => $users->firstItem(),
                    'last_page'    => $users->lastPage(),
                    'path'         => $users->path(),
                    'per_page'     => $users->perPage(),
                    'to'           => $users->lastItem(),
                    'total'        => $users->total(),
                ],
            ]);
        }

        // get users
        $users = $usersQuery->get();
        // fix results
        $users = $users->map(function ($companyUser) {
            return $companyUser->user;
        });

        return response()->json(['users' => $users]);
    }
}

<?php

namespace Fleetbase\Http\Filter;

use Fleetbase\Support\Utils;

class UserFilter extends Filter
{
    public function queryForInternal()
    {
        $companyUuid = $this->session->get('company');

        $this->builder->where(
            function ($query) use ($companyUuid) {
                // Include users who are already members of the company.
                $query->whereHas(
                    'companyUsers',
                    function ($q) use ($companyUuid) {
                        $q->where('company_uuid', $companyUuid);
                    }
                )
                // Also include users who have a pending invite to join the company
                // but have not yet accepted (no CompanyUser row exists yet).
                // The invites table has no user_uuid; the link is via the user's
                // email stored in the JSON recipients column.
                ->orWhereExists(function ($q) use ($companyUuid) {
                    $q->selectRaw(1)
                      ->from('invites')
                      ->whereRaw('JSON_CONTAINS(invites.recipients, JSON_QUOTE(users.email))')
                      ->where('invites.company_uuid', $companyUuid)
                      ->where('invites.reason', 'join_company')
                      ->whereNull('invites.deleted_at');
                });
            }
        );
    }

    public function queryForPublic()
    {
        $this->queryForInternal();
    }

    public function isNotAdmin()
    {
        $this->builder->where('type', '!=', 'admin');
    }

    public function emailVerified($verified)
    {
        Utils::isTrue($verified) ? $this->builder->whereNotNull('email_verified_at') : $this->builder->whereNull('email_verified_at');
    }

    public function phoneVerified($verified)
    {
        Utils::isTrue($verified) ? $this->builder->whereNotNull('phone_verified_at') : $this->builder->whereNull('phone_verified_at');
    }

    public function country(?string $country)
    {
        $this->builder->where('country', $country);
    }

    public function timezone(?string $timezone)
    {
        $this->builder->where('timezone', $timezone);
    }

    public function isUser()
    {
        $this->builder->whereIn('type', ['user', 'admin']);
    }

    public function query(?string $query)
    {
        $this->builder->search($query);
    }

    public function name(?string $name)
    {
        $this->builder->searchWhere('name', $name);
    }

    public function phone(?string $phone)
    {
        $this->builder->searchWhere('phone', $phone);
    }

    public function email(?string $email)
    {
        $this->builder->searchWhere('email', $email);
    }

    public function role(?string $roleId)
    {
        $this->builder->whereHas('companyUsers', function ($query) use ($roleId) {
            $query->where('company_uuid', session('company'));
            $query->whereHas('roles', function ($query) use ($roleId) {
                $query->where('id', $roleId);
            });
        });
    }
}

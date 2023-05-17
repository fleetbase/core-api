<?php

namespace Fleetbase\Http\Filter;

class CompanyFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where(
            function ($query) {
                $query
                    ->where('owner_uuid', $this->session->get('user'))
                    ->orWhereHas(
                        'users',
                        function ($query) {
                            $query->where('users.uuid', $this->session->get('user'));
                        }
                    );
            }
        );
    }
}

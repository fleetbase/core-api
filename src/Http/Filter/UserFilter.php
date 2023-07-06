<?php

namespace Fleetbase\Http\Filter;

class UserFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where(
            function ($query) {
                $query
                    ->where('company_uuid', $this->session->get('company'))
                    ->orWhereHas(
                        'companies',
                        function ($query) {
                            $query->where('company_uuid', $this->session->get('company'));
                        }
                    );
            }
        );
    }

    public function query(?string $query)
    {
        $this->builder->search($query);
    }
}

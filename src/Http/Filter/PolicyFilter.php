<?php

namespace Fleetbase\Http\Filter;

class PolicyFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where(
            function ($query) {
                $query->where('company_uuid', $this->session->get('company'))->orWhereNull('company_uuid');
            }
        );
    }

    public function query(?string $query)
    {
        $this->builder->search($query);
    }
}

<?php

namespace Fleetbase\Http\Filter;

class ApiEventFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }
}

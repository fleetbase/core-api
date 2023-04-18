<?php

namespace Fleetbase\Http\Filter;

class ApiRequestLogFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }
}

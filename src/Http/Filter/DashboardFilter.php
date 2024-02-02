<?php

namespace Fleetbase\Http\Filter;

class DashboardFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where('owner_uuid', $this->session->get('user'));
    }
}

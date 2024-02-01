<?php

namespace Fleetbase\Http\Filter;

class DashboardFilter extends Filter
{
    public function queryForInternal()
    {
        $userUuid = $this->session->get('user');

        $this->builder->where('owner_uuid', $userUuid);

        $userDashboards = $this->builder->get();

        if ($userDashboards->isEmpty()) {
            $this->builder->orWhere('owner_uuid', 'system');
        }
    }
}

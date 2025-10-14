<?php

namespace Fleetbase\Http\Filter;

use Fleetbase\Support\Utils;

class ActivityFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where('company_id', $this->session->get('company'));
    }

    public function queryForPublic()
    {
        $this->queryForInternal();
    }

    public function createdAt($createdAt)
    {
        $createdAt = Utils::dateRange($createdAt);

        if (is_array($createdAt)) {
            $this->builder->whereBetween('created_at', $createdAt);
        } else {
            $this->builder->whereDate('created_at', $createdAt);
        }
    }
}

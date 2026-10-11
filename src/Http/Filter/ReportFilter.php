<?php

namespace Fleetbase\Http\Filter;

class ReportFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where($this->builder->qualifyColumn('company_uuid'), $this->session->get('company'));
    }

    public function queryForPublic()
    {
        $this->queryForInternal();
    }
}

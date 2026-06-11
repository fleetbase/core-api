<?php

namespace Fleetbase\Http\Filter;

use Fleetbase\Support\Utils;

class ActivityFilter extends Filter
{
    public function queryForInternal()
    {
        if ($this->request->filled('company_uuid') && $this->request->user()?->isAdmin()) {
            return;
        }

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

    public function companyUuid($companyUuid)
    {
        $user = $this->request->user();

        $this->builder->where('company_id', $user?->isAdmin() ? $companyUuid : $this->session->get('company'));
    }

    public function subjectId($subjectId)
    {
        $this->builder->where('subject_id', $subjectId);
    }

    public function causerId($causerId)
    {
        $this->builder->where('causer_id', $causerId);
    }
}

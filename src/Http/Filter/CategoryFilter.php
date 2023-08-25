<?php

namespace Fleetbase\Http\Filter;

use Illuminate\Support\Str;

class CategoryFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function parentsOnly()
    {
        if ($this->request->boolean('parents_only')) {
            $this->builder->whereNull('parent_uuid');
        }
    }

    public function parent(?string $id)
    {
        if (Str::isUuid($id)) {
            $this->builder->where('parent_uuid', $id);
        } else {
            $this->builder->whereHas(
                'parent',
                function ($query) use ($id) {
                    $query->where('public_id', $id);
                }
            );
        }
    }
}

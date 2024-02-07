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

    public function name(?string $name)
    {
        $this->builder->searchWhere('name', $name);
    }

    public function phone(?string $phone)
    {
        $this->builder->searchWhere('phone', $phone);
    }

    public function email(?string $email)
    {
        $this->builder->searchWhere('email', $email);
    }
}

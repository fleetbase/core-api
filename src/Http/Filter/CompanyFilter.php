<?php

namespace Fleetbase\Http\Filter;

class CompanyFilter extends Filter
{
    public function queryForInternal()
    {
        // If admin query then do not filter
        $isAdminQuery = $this->request->input('view') === 'admin' && $this->request->user()->isAdmin();
        if ($isAdminQuery) {
            return;
        }

        // Otherwise filter so that user only see's their own companies
        $this->builder->where(
            function ($query) {
                $query
                    ->where('owner_uuid', $this->session->get('user'))
                    ->orWhereHas(
                        'users',
                        function ($query) {
                            $query->where('users.uuid', $this->session->get('user'));
                        }
                    );
            }
        );
    }

    public function query(?string $searchQuery)
    {
        $this->builder->searchWhere('name', $searchQuery);
    }

    public function name(?string $name)
    {
        $this->builder->searchWhere('name', $name);
    }

    public function country(?string $country)
    {
        $this->builder->searchWhere('country', $country);
    }

    public function status(?string $status)
    {
        $this->builder->searchWhere('status', $status);
    }

    public function ownerEmail(?string $email)
    {
        $this->builder->whereHas('owner', function ($query) use ($email) {
            $query->searchWhere('email', $email);
        });
    }

    public function onboardingCompleted($completed)
    {
        if ($completed === null || $completed === '') {
            return;
        }

        $isCompleted = filter_var($completed, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($isCompleted === true) {
            $this->builder->whereNotNull('onboarding_completed_at');
        } elseif ($isCompleted === false) {
            $this->builder->whereNull('onboarding_completed_at');
        }
    }

    public function billingStatus(?string $status)
    {
        if (!$status) {
            return;
        }

        if (class_exists('\\Fleetbase\\Billing\\Models\\Subscription')) {
            $this->builder->whereHas('billingSubscriptions', function ($query) use ($status) {
                $query->where('payment_gateway_status', $status);
            });
        } elseif ($status === 'legacy') {
            $this->builder->whereNotNull('plan');
        }
    }

    public function createdAt(?string $date)
    {
        if (!$date) {
            return;
        }

        $this->builder->whereDate('created_at', $date);
    }
}

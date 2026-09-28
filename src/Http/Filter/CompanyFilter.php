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
        $this->builder->where(function ($query) use ($searchQuery) {
            foreach (['name', 'description', 'phone', 'website_url', 'public_id', 'slug', 'country', 'timezone'] as $column) {
                $query->orWhereRaw('LOWER(companies.' . $column . ') LIKE ?', ['%' . mb_strtolower(trim($searchQuery ?? '')) . '%']);
            }
            $query->orWhereHas('owner', function ($owner) use ($searchQuery) {
                $owner->where(function ($query) use ($searchQuery) {
                    foreach (['name', 'email', 'phone', 'ip_address'] as $column) {
                        $query->orWhereRaw('LOWER(users.' . $column . ') LIKE ?', ['%' . mb_strtolower(trim($searchQuery ?? '')) . '%']);
                    }
                });
            });
        });
    }

    public function name(?string $name)
    {
        $this->builder->searchWhere('name', $name);
    }

    public function country(?string $country)
    {
        $this->builder->where('country', strtoupper($country ?? ''));
    }

    public function status(?string $status)
    {
        if ($status === 'active') {
            $this->builder->where(function ($query) {
                $query->whereNull('status')->orWhere('status', 'active');
            });

            return;
        }

        $this->builder->where('status', $status);
    }

    public function timezone(?string $timezone)
    {
        $this->builder->where('timezone', $timezone);
    }

    public function type(?string $type)
    {
        $this->builder->where('type', $type);
    }

    public function ipAddress(?string $ipAddress)
    {
        $this->builder->whereHas('owner', fn ($query) => $query->where('ip_address', $ipAddress));
    }

    public function ownerName(?string $name)
    {
        $this->builder->whereHas('owner', fn ($query) => $query->searchWhere('name', $name));
    }

    public function ownerPhone(?string $phone)
    {
        $this->builder->whereHas('owner', fn ($query) => $query->searchWhere('phone', $phone));
    }

    public function ownerEmail(?string $email)
    {
        $this->builder->whereHas('owner', function ($query) use ($email) {
            $query->searchWhere('email', $email);
        });
    }

    public function needsAttention($value)
    {
        if (!filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $this->builder->where(function ($query) {
            $query->whereNull('onboarding_completed_at')
                ->orWhereNull('owner_uuid')
                ->orWhere(function ($query) {
                    $query->whereNotNull('status')->where('status', '!=', 'active');
                });
        });
    }

    public function missingOwner($value)
    {
        if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            $this->builder->whereNull('owner_uuid');
        }
    }

    public function inactiveStatus($value)
    {
        if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            $this->builder->whereNotNull('status')->where('status', '!=', 'active');
        }
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

    public function createdAtBetween(?string $from, ?string $to)
    {
        $this->dateRange('created_at', $from, $to);
    }

    public function updatedAtBetween(?string $from, ?string $to)
    {
        $this->dateRange('updated_at', $from, $to);
    }

    private function dateRange(string $column, ?string $from, ?string $to): void
    {
        if ($from) {
            $this->builder->whereDate($column, '>=', $from);
        }
        if ($to) {
            $this->builder->whereDate($column, '<=', $to);
        }
    }
}

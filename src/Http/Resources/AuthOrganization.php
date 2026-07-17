<?php

namespace Fleetbase\Http\Resources;

use Fleetbase\Models\Setting;
use Fleetbase\Support\Utils;

class AuthOrganization extends FleetbaseResource
{
    protected function getBillingStatus(): ?string
    {
        if (!class_exists('\\Fleetbase\\Billing\\Models\\Subscription')) {
            return $this->plan ? 'legacy' : null;
        }

        $subscriptionClass = '\\Fleetbase\\Billing\\Models\\Subscription';
        $subscription      = $subscriptionClass::where('company_uuid', $this->uuid)->latest('created_at')->first();

        return $subscription?->payment_gateway_status ?? ($this->plan ? 'legacy' : null);
    }

    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'                   => $this->id,
            'uuid'                 => $this->uuid,
            'public_id'            => $this->public_id,
            'name'                 => $this->name,
            'description'          => $this->description,
            'phone'                => $this->phone,
            'type'                 => $this->type,
            'users_count'          => $this->users_count,
            'timezone'             => $this->timezone,
            'country'              => $this->country,
            'currency'             => $this->currency,
            'plan'                 => $this->plan,
            'trial_ends_at'        => $this->trial_ends_at,
            'logo_url'             => $this->logo_url,
            'backdrop_url'         => $this->backdrop_url,
            'branding'             => Setting::getBranding(),
            'options'              => $this->options ?? Utils::createObject([]),
            'owner'                => $this->owner ? [
                'uuid'  => $this->owner->uuid,
                'name'  => $this->owner->name,
                'email' => $this->owner->email,
            ] : null,
            'slug'                 => $this->slug,
            'status'               => $this->status,
            'billing_status'       => $this->getBillingStatus(),
            'onboarding_completed' => $this->onboarding_completed_at !== null,
            'joined_at'            => $this->joined_at,
            'updated_at'           => $this->updated_at,
            'created_at'           => $this->created_at,
        ];
    }
}

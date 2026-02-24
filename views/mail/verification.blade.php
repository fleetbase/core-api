@component('mail::message')
# @if($currentHour < 12)Good Morning, {{ \Fleetbase\Support\Utils::delinkify($user->name) }}!@elseif($currentHour < 18)Good Afternoon, {{ \Fleetbase\Support\Utils::delinkify($user->name) }}!@elseGood Evening, {{ \Fleetbase\Support\Utils::delinkify($user->name) }}!@endif

@if($content)
{!! $content !!}
@else
Welcome to {{ $appName }}, use the code below to verify your email address and complete registration to {{ $appName }}.

**Your verification code:** `{{ $code }}`
@endif

@if($type === 'email_verification')
@component('mail::button', ['url' => \Fleetbase\Support\Utils::consoleUrl('onboard', ['step' => 'verify-email', 'session' => base64_encode($user->uuid), 'code' => $code ])])
Verify Email
@endcomponent
@endif

© {{ date('Y') }} {{ $appName }}. All Rights Reserved.
@endcomponent

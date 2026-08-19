<x-mail-layout>
<h2 style="font-size: 18px; font-weight: 600;">
@php
    // Blade only treats `@` as a directive when the preceding character is NOT a word
    // character — the rule that keeps `foo@bar.com` from compiling. `Good Morning@if(...)`
    // therefore stayed literal text while its `@endif` still compiled, leaving an
    // unmatched endif that broke the enclosing if/elseif/else. The whole view failed to
    // parse, so every mail using it threw instead of sending.
    //
    // Build the greeting in one expression instead, so no directive ever sits against a
    // word. delinkify() returns '' for a null or empty name, which is what makes the
    // no-name case fall through to the bare greeting.
    $greeting  = $currentHour < 12 ? 'Good Morning' : ($currentHour < 18 ? 'Good Afternoon' : 'Good Evening');
    $recipient = \Fleetbase\Support\Utils::delinkify($user?->name);
@endphp
{{ $recipient === '' ? $greeting : $greeting . ', ' . $recipient }}!
</h2>

@if($content)
{!! $content !!}
@else
Welcome to {{ $appName }}, use the code below to verify your email address and complete registration to {{ $appName }}.
<br />
<br />
Your verification code: <code>{{ $code }}</code>
<br />
@endif

@if($type === 'email_verification')
@component('mail::button', ['url' => \Fleetbase\Support\Utils::consoleUrl('onboard', ['step' => 'verify-email', 'session' => base64_encode($user->uuid), 'code' => $code ])])
Verify Email
@endcomponent
@endif

</x-mail-layout>

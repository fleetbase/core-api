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

Your login credentials:
<br />
<br />
Your Email: {{ $user->email }}
<br />
Your Password: {{ $plaintextPassword }}
<br />
Console URL: {{ \Fleetbase\Support\Utils::consoleUrl() }}
</x-mail-layout>

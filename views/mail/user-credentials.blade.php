<x-mail-layout>
<h2 style="font-size: 18px; font-weight: 600;">
@if($currentHour < 12)
    Good Morning@if($user->name), {{ \Fleetbase\Support\Utils::delinkify($user->name) }}@endif!
@elseif($currentHour < 18)
    Good Afternoon@if($user->name), {{ \Fleetbase\Support\Utils::delinkify($user->name) }}@endif!
@else
    Good Evening@if($user->name), {{ \Fleetbase\Support\Utils::delinkify($user->name) }}@endif!
@endif
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

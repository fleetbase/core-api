@section('greeting')
    @if($currentHour < 12)
        Good Morning, {{ $user->name }}!
    @elseif($currentHour < 18)
        Good Afternoon, {{ $user->name }}!
    @else
        Good Evening, {{ $user->name }}!
    @endif
@endsection

@section('content')
    @if($content)
        {!! $content !!}
    @else
        <p>Welcome to {{ $appName }}, use the code below to verify your email address and complete registration to {{ $appName }}.</p>
        <br>
        <p style="font-family: monospace;">Your verification code: <strong>{{ $code }}</strong></p>
        <br>
    @endif
@endsection

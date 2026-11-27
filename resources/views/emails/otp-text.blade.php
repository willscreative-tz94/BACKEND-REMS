{{ $brand->name }} — {{ $heading }}

Hi {{ $user->name }},

{{ $intro }}

    {{ $code }}

The code expires in {{ $minutes }} {{ \Illuminate\Support\Str::plural('minute', $minutes) }} and can only be used once.

Never share this code. {{ $brand->name }} staff will never ask for it. If you didn't request it, ignore this email and consider changing your password.

Requested: {{ $requestedAt }}
@if ($ipAddress)
From IP: {{ $ipAddress }}
@endif

— {{ $brand->platformName }} · Powered by {{ $brand->poweredBy }}

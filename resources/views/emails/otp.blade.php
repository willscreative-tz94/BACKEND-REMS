@extends('emails.layout', ['title' => $heading, 'badge' => 'Security'])

@section('content')
    <p class="muted" style="margin:0 0 6px;font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:{{ $brand->primary }};">🔐 One-time passcode</p>
    <h1 class="text hero-title" style="margin:0 0 14px;font-size:26px;line-height:1.25;font-weight:800;color:#0f1f3a;">{{ $heading }}</h1>
    <p class="text" style="margin:0 0 24px;font-size:15px;line-height:1.65;color:#36435a;">Hi {{ \Illuminate\Support\Str::before(trim($user->name), ' ') ?: $user->name }}, {{ lcfirst($intro) }}</p>

    {{-- The code --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td align="center" class="panel" style="background:{{ $brand->tint(0.93) }};border:1px dashed {{ $brand->tint(0.55) }};border-radius:16px;padding:26px 16px;">
                <div class="muted" style="font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#6a778d;margin-bottom:10px;">Your verification code</div>
                <div class="code" style="font-family:'SFMono-Regular',Menlo,Consolas,'Liberation Mono',monospace;font-size:38px;font-weight:800;letter-spacing:12px;color:{{ $brand->primary }};padding-left:12px;">{{ $code }}</div>
                <div class="muted" style="margin-top:12px;font-size:13px;color:#6a778d;">⏱ Expires in <strong>{{ $minutes }} {{ \Illuminate\Support\Str::plural('minute', $minutes) }}</strong> · single use</div>
            </td>
        </tr>
    </table>

    {{-- Security panel --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:24px;">
        <tr>
            <td class="panel" style="border-left:4px solid {{ $brand->accent }};background:#fff8f6;border-radius:10px;padding:14px 16px;">
                <p class="text" style="margin:0 0 4px;font-size:14px;font-weight:700;color:#0f1f3a;">Never share this code</p>
                <p class="muted" style="margin:0;font-size:13px;line-height:1.6;color:#5b6880;">{{ $brand->name }} staff will never ask for it by phone, SMS or chat. If you didn't try to sign in, ignore this email and consider changing your password.</p>
            </td>
        </tr>
    </table>

    {{-- Request details --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="divider" style="margin-top:24px;border-top:1px solid #e6ebf2;">
        <tr>
            <td style="padding-top:16px;font-size:12px;line-height:1.8;color:#7a879c;" class="muted">
                <strong>Requested:</strong> {{ $requestedAt }}<br>
                <strong>Account:</strong> {{ $user->email }}
                @if ($ipAddress)<br><strong>From IP:</strong> {{ $ipAddress }}@endif
            </td>
        </tr>
    </table>
@endsection

@section('footer-note', 'This security email was sent because a sign-in or verification was requested for your account.')

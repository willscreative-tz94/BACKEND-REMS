@extends('emails.layout', ['title' => $title, 'badge' => $category])

@section('content')
    {{-- Category chip --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:18px;">
        <tr>
            <td width="48" height="48" align="center" valign="middle" class="panel" style="width:48px;height:48px;border-radius:14px;background:{{ $brand->tint(0.9) }};font-size:24px;line-height:48px;">{{ $emoji }}</td>
            <td style="padding-left:12px;">
                <div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:{{ $brand->primary }};">{{ $category }}</div>
                <div class="muted" style="font-size:12px;color:#7a879c;">{{ $sentAt }}</div>
            </td>
        </tr>
    </table>

    <p class="text" style="margin:0 0 6px;font-size:15px;color:#36435a;">Hi {{ $firstName }},</p>
    <h1 class="text hero-title" style="margin:0 0 14px;font-size:24px;line-height:1.3;font-weight:800;color:#0f1f3a;">{{ $title }}</h1>

    @if ($body)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
                <td class="panel" style="background:#f6f8fb;border:1px solid #e6ebf2;border-left:4px solid {{ $brand->primary }};border-radius:12px;padding:16px 18px;">
                    <p class="text" style="margin:0;font-size:15px;line-height:1.7;color:#36435a;">{!! nl2br(e($body)) !!}</p>
                </td>
            </tr>
        </table>
    @endif

    @if ($url)
        {{-- Bulletproof button --}}
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:28px 0 8px;">
            <tr>
                <td align="center" bgcolor="{{ $brand->primary }}" style="border-radius:12px;background:{{ $brand->primary }};background-image:linear-gradient(135deg, {{ $brand->primary }}, {{ $brand->accent }});box-shadow:0 8px 18px rgba(15,35,70,.18);">
                    <a href="{{ $url }}" target="_blank" class="btn" style="display:inline-block;padding:14px 26px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:12px;">{{ $cta }} &rarr;</a>
                </td>
            </tr>
        </table>
        <p class="muted" style="margin:10px 0 0;font-size:12px;line-height:1.6;color:#7a879c;">Button not working? Paste this link into your browser:<br><a href="{{ $url }}" style="color:{{ $brand->primary }};word-break:break-all;">{{ $url }}</a></p>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="divider" style="margin-top:26px;border-top:1px solid #e6ebf2;">
        <tr>
            <td style="padding-top:16px;font-size:13px;line-height:1.6;color:#7a879c;" class="muted">
                You'll also find this in your notifications inside {{ $brand->name }}.
                @if ($brand->replyTo) Questions? Just reply to this email. @endif
            </td>
        </tr>
    </table>
@endsection

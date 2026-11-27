{{-- Shared branded email shell. Table layout + inline styles so it renders in Gmail, Outlook and Apple Mail. --}}
@php
    /** @var \App\Mail\EmailBrand $brand */
    $primary = $brand->primary;
    $accent = $brand->accent;
    $tint = $brand->tint(0.92);
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ $title ?? $brand->name }}</title>
    <style>
        body { margin: 0; padding: 0; width: 100% !important; background: #eef2f7; }
        a { color: {{ $primary }}; }
        .btn:hover { opacity: .92; }
        @media (max-width: 620px) {
            .container { width: 100% !important; }
            .px { padding-left: 22px !important; padding-right: 22px !important; }
            .hero-title { font-size: 22px !important; }
            .code { font-size: 30px !important; letter-spacing: 8px !important; }
        }
        @media (prefers-color-scheme: dark) {
            .bg-page { background: #0b1220 !important; }
            .card { background: #121b2d !important; }
            .text { color: #e5ebf5 !important; }
            .muted { color: #9aa8bf !important; }
            .panel { background: #1a2640 !important; border-color: #263552 !important; }
            .divider { border-color: #263552 !important; }
        }
    </style>
</head>
<body class="bg-page" style="margin:0;padding:0;background:#eef2f7;">
    {{-- Inbox preview text --}}
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;mso-hide:all;">
        {{ $preheader ?? '' }}&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="bg-page" style="background:#eef2f7;">
        <tr>
            <td align="center" style="padding:32px 12px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" class="container" style="width:600px;max-width:600px;">

                    {{-- Header band with brand gradient --}}
                    <tr>
                        <td style="border-radius:18px 18px 0 0;background:{{ $primary }};background-image:linear-gradient(135deg, {{ $primary }} 0%, {{ $primary }} 55%, {{ $accent }} 140%);padding:26px 32px;" class="px">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td valign="middle" width="52">
                                        @if ($brand->logoUrl)
                                            <img src="{{ $brand->logoUrl }}" width="44" height="44" alt="{{ $brand->name }}" style="display:block;width:44px;height:44px;border-radius:12px;background:#ffffff;object-fit:cover;border:0;">
                                        @else
                                            <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
                                                <td width="44" height="44" align="center" valign="middle" style="width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.35);font:800 16px/44px 'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#ffffff;">{{ $brand->initials() }}</td>
                                            </tr></table>
                                        @endif
                                    </td>
                                    <td valign="middle" style="padding-left:12px;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                                        <div style="font-size:17px;font-weight:800;color:#ffffff;line-height:1.2;">{{ $brand->name }}</div>
                                        @if ($brand->tagline)
                                            <div style="font-size:12px;color:rgba(255,255,255,.78);line-height:1.4;">{{ $brand->tagline }}</div>
                                        @endif
                                    </td>
                                    @isset($badge)
                                        <td valign="middle" align="right" style="font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                                            <span style="display:inline-block;padding:6px 12px;border-radius:999px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.3);color:#ffffff;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;white-space:nowrap;">{{ $badge }}</span>
                                        </td>
                                    @endisset
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Accent rule --}}
                    <tr><td height="4" style="height:4px;line-height:4px;font-size:0;background:{{ $accent }};">&nbsp;</td></tr>

                    {{-- Body card --}}
                    <tr>
                        <td class="card px" style="background:#ffffff;padding:36px 40px 32px;border-radius:0 0 18px 18px;box-shadow:0 12px 32px rgba(15,35,70,.08);font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                            @yield('content')
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td align="center" class="px" style="padding:24px 32px 8px;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                            <p class="muted" style="margin:0 0 6px;font-size:12px;line-height:1.6;color:#7a879c;">
                                @yield('footer-note', 'You are receiving this because you have an account with '.$brand->name.'.')
                            </p>
                            <p class="muted" style="margin:0;font-size:11px;line-height:1.6;color:#98a3b5;">
                                {{ $brand->platformName }} · Real Estate Management System · Powered by <strong style="color:{{ $primary }};">{{ $brand->poweredBy }}</strong>
                            </p>
                            <p class="muted" style="margin:6px 0 0;font-size:11px;color:#98a3b5;">&copy; {{ date('Y') }} {{ $brand->name }}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

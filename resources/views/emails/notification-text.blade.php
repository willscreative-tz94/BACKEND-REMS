{{ $brand->name }} · {{ $category }}

Hi {{ $firstName }},

{{ $title }}

@if ($body)
{{ $body }}

@endif
@if ($url)
{{ $cta }}: {{ $url }}

@endif
You'll also find this in your notifications inside {{ $brand->name }}.

— {{ $brand->platformName }} · Powered by {{ $brand->poweredBy }}

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if (isset($webcontent_seo_head))
        <title>{{ $webcontent_seo_head['title'] ?? '' }}</title>
        @if (isset($webcontent_seo_head['description']))
            <meta name="description" content="{{ $webcontent_seo_head['description'] }}">
        @endif
    @endif
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>

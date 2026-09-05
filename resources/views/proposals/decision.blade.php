<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>WebContent — proposal {{ $ok ? 'applied' : 'notice' }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: -apple-system, "Segoe UI", Roboto, sans-serif; margin: 4rem auto; max-width: 560px; padding: 0 1rem; color: #1f2937; text-align: center; }
        .card { border: 1px solid #e5e7eb; border-radius: .5rem; padding: 2rem; }
        h1 { font-size: 1.2rem; }
        a { color: #2563eb; }
    </style>
</head>
<body>
<div class="card">
    <h1>{{ $ok ? '✅ Done' : '⚠️ Not applied' }}</h1>
    <p>{{ $message }}</p>
    <p class="muted" style="color:#6b7280;font-size:.85rem">
        {{ strtoupper($proposal->action) }} · {{ $proposal->slug }} · now <b>{{ $proposal->refresh()->status }}</b>
    </p>
    <p><a href="{{ route('webcontent.proposals.index') }}">Back to all proposals</a></p>
</div>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>WebContent — agent proposals</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: -apple-system, "Segoe UI", Roboto, sans-serif; margin: 2rem auto; max-width: 960px; padding: 0 1rem; color: #1f2937; }
        h1 { font-size: 1.4rem; }
        table { border-collapse: collapse; width: 100%; font-size: .9rem; }
        th, td { border: 1px solid #e5e7eb; padding: .5rem .6rem; text-align: left; vertical-align: top; }
        th { background: #f9fafb; }
        .badge { display: inline-block; padding: .1rem .45rem; border-radius: .3rem; font-size: .75rem; font-weight: 600; }
        .add { background: #d1fae5; color: #065f46; }
        .update { background: #dbeafe; color: #1e40af; }
        .remove { background: #fee2e2; color: #991b1b; }
        .pending { color: #92400e; }
        .applied { color: #065f46; }
        .rejected { color: #6b7280; }
        a.btn, button { display: inline-block; padding: .3rem .7rem; border-radius: .35rem; text-decoration: none; font-size: .8rem; border: 0; cursor: pointer; }
        .approve { background: #059669; color: #fff; }
        .discard { background: #9ca3af; color: #fff; }
        .alert { padding: .6rem .8rem; border-radius: .35rem; margin-bottom: 1rem; }
        .alert-success { background: #d1fae5; } .alert-error { background: #fee2e2; }
        .muted { color: #6b7280; font-size: .8rem; }
    </style>
</head>
<body>
<h1>🤖 WebContent — agent proposals</h1>

@if ($success ?? false)<div class="alert alert-success">{{ $success }}</div>@endif
@if ($error ?? false)<div class="alert alert-error">{{ $error }}</div>@endif

<table>
    <thead>
    <tr>
        <th>When</th><th>Action</th><th>Slug</th><th>Rationale</th>
        <th>Conf.</th><th>Sources</th><th>Status</th><th></th>
    </tr>
    </thead>
    <tbody>
    @forelse ($proposals as $proposal)
        <tr>
            <td class="muted">{{ $proposal->created_at?->format('Y-m-d H:i') }}</td>
            <td><span class="badge {{ $proposal->action }}">{{ strtoupper($proposal->action) }}</span></td>
            <td>{{ $proposal->slug }}<br><span class="muted">{{ $proposal->title }}</span></td>
            <td>{{ Str::limit($proposal->rationale, 220) }}
                @if ($proposal->sources)
                    <br><span class="muted">{{ collect($proposal->sources)->pluck('url')->implode(', ') }}</span>
                @endif
            </td>
            <td>{{ $proposal->confidence !== null ? round((float) $proposal->confidence, 2) : '—' }}</td>
            <td>{{ collect($proposal->sources ?? [])->count() }}</td>
            <td class="{{ $proposal->status }}">{{ $proposal->status }}</td>
            <td>
                @if ($proposal->status === 'pending')
                    <a class="btn approve" href="{{ ($approveUrl)($proposal) }}">Approve</a>
                    <a class="btn discard" href="{{ ($discardUrl)($proposal) }}">Discard</a>
                @elseif ($proposal->applied_at)
                    <span class="muted">{{ $proposal->applied_at->format('Y-m-d H:i') }}</span>
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="8">No proposals yet. The agent runs via <code>webcontent:research</code>.</td></tr>
    @endforelse
    </tbody>
</table>

{{ $proposals->links() }}
</body>
</html>

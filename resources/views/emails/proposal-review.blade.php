@component('mail::message')
# 🤖 The content agent reviewed your website

It researched current data and proposes **{{ $proposals->count() }} change(s)**.
**Nothing has been changed yet** — each change below needs your approval.

@foreach ($proposals as $proposal)
@component('mail::panel')
**{{ strtoupper($proposal->action) }} — {{ $proposal->slug }}** (confidence {{ $proposal->confidence !== null ? round((float) $proposal->confidence, 2) : 'n/a' }})

{{ Str::limit($proposal->rationale, 400) }}

@if ($proposal->sources)
*Sources:* {{ collect($proposal->sources)->pluck('url')->take(3)->implode(' · ') }}
@endif

[✅ Approve]({{ app(\Kit\WebContent\Services\ProposalNotifier::class)->approveUrl($proposal) }}) · [🗑 Discard]({{ app(\Kit\WebContent\Services\ProposalNotifier::class)->discardUrl($proposal) }})
@endcomponent
@endforeach

Links expire in {{ config('webcontent.notify.links_expire_days', 7) }} day(s).

@component('mail::button', ['url' => $reviewUrl])
Review all proposals
@endcomponent

Thanks,<br>
{{ config('app.name', 'Laravel') }}
@endcomponent

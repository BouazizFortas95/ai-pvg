<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AI-PVG Studio</title>
<style>
:root { --primary:#00d4ff; --secondary:#1e293b; --accent:#64748b; --bg:#0f172a; --text:#f8fafc; --radius:8px; --mode:dark; }
* { box-sizing:border-box; }
body { margin:0; font-family:'Segoe UI',system-ui,sans-serif; background:var(--bg); color:var(--text); min-height:100vh; }
.studio-header { display:flex; align-items:center; justify-content:space-between; padding:1.5rem 2rem; border-bottom:1px solid rgba(255,255,255,0.05); background:linear-gradient(135deg, rgba(0,212,255,0.08), rgba(0,0,0,0.4)); }
.studio-logo { font-weight:800; font-size:1.5rem; letter-spacing:-0.02em; color:var(--primary); }
.studio-grid { display:grid; grid-template-columns: repeat(auto-fit,minmax(260px,1fr)); gap:1.25rem; padding:2rem; }
.studio-card { background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.06); border-radius:var(--radius); padding:1.5rem; backdrop-filter:blur(10px); }
.studio-card h3 { margin:0 0 .75rem; font-size:1.1rem; color:var(--primary); }
.studio-card .stat { font-size:2rem; font-weight:700; }
.studio-card .label { color:rgba(255,255,255,0.5); font-size:.85rem; }
.studio-actions { padding:0 2rem 2rem; }
.btn { background:var(--primary); color:#0f172a; border:none; padding:.6rem 1.25rem; border-radius:var(--radius); cursor:pointer; font-weight:600; }
.btn:disabled { opacity:.5; cursor:not-allowed; }
.triage-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(340px,1fr)); gap:1.25rem; padding:0 2rem 2rem; }
.triage-row { margin-top:.75rem; padding:1rem; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.06); border-radius:var(--radius); }
.triage-row ul { margin:.5rem 0; padding-left:1.1rem; color:rgba(255,255,255,0.72); font-size:.82rem; }
.triage-row li { margin:.2rem 0; }
.triage-meta { color:rgba(255,255,255,0.58); font-size:.8rem; margin-top:.35rem; }
.triage-badge { display:inline-block; padding:.15rem .5rem; border-radius:999px; font-size:.72rem; font-weight:700; background:rgba(0,212,255,0.14); color:var(--primary); }
.triage-badge.warning { background:rgba(245,158,11,0.16); color:#fbbf24; }
.triage-badge.muted { background:rgba(148,163,184,0.16); color:#cbd5e1; }
.triage-note { margin:0 2rem 1rem; color:rgba(255,255,255,0.58); font-size:.85rem; }
.triage-message { margin:0 2rem 1rem; padding:.75rem 1rem; border:1px solid rgba(0,212,255,0.28); background:rgba(0,212,255,0.08); border-radius:var(--radius); font-size:.88rem; }
pre.breakdown { white-space:pre-wrap; word-break:break-word; max-height:220px; overflow:auto; background:rgba(0,0,0,0.28); border-radius:6px; padding:.6rem; font-size:.72rem; color:#cbd5e1; }
.theme-form { display:flex; gap:.75rem; flex-wrap:wrap; align-items:center; margin-top:1rem; }
.theme-form label { font-size:.85rem; color:rgba(255,255,255,0.7); }
.theme-form input[type="color"], .theme-form input[type="text"], .theme-form select { background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:var(--text); padding:.35rem .6rem; border-radius:var(--radius); }
.script-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(420px,1fr)); gap:1.25rem; padding:0 2rem 2rem; }
.script-card { background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.06); border-radius:var(--radius); padding:1.25rem; }
.script-card h4 { margin:0 0 .35rem; font-size:1rem; color:var(--primary); }
.script-card h5 { margin:1rem 0 .4rem; font-size:.85rem; color:rgba(255,255,255,0.8); text-transform:uppercase; letter-spacing:.04em; }
.hook-row { margin-top:.6rem; padding:.75rem; background:rgba(0,0,0,0.2); border:1px solid rgba(255,255,255,0.06); border-radius:6px; }
.hook-row.selected { border-color:rgba(0,212,255,0.5); background:rgba(0,212,255,0.07); }
.hook-content { margin:.35rem 0; font-size:.9rem; }
.hook-meta { color:rgba(255,255,255,0.55); font-size:.75rem; }
.btn.small { padding:.3rem .7rem; font-size:.75rem; margin-top:.4rem; }
.btn.secondary { background:rgba(255,255,255,0.1); color:var(--text); border:1px solid rgba(255,255,255,0.14); }
.script-badge { display:inline-block; padding:.12rem .5rem; border-radius:999px; font-size:.7rem; font-weight:700; background:rgba(148,163,184,0.16); color:#cbd5e1; }
.script-badge.selected { background:rgba(34,197,94,0.16); color:#4ade80; }
.script-badge.approved { background:rgba(34,197,94,0.16); color:#4ade80; }
.verdict-badge { display:inline-block; padding:.12rem .5rem; border-radius:999px; font-size:.7rem; font-weight:700; }
.verdict-badge.supported { background:rgba(34,197,94,0.16); color:#4ade80; }
.verdict-badge.unsupported { background:rgba(239,68,68,0.18); color:#f87171; }
.verdict-badge.uncertain { background:rgba(245,158,11,0.18); color:#fbbf24; }
.fact-row { margin-top:.55rem; padding:.6rem .7rem; border-left:3px solid rgba(148,163,184,0.4); background:rgba(0,0,0,0.18); border-radius:4px; font-size:.8rem; }
.fact-row.unsupported { border-left-color:#f87171; }
.fact-row.uncertain { border-left-color:#fbbf24; }
.directive-table { width:100%; border-collapse:collapse; font-size:.72rem; margin-top:.35rem; }
.directive-table th { text-align:left; color:rgba(255,255,255,0.5); font-weight:600; padding:.3rem .35rem; border-bottom:1px solid rgba(255,255,255,0.08); }
.directive-table td { padding:.3rem .35rem; border-bottom:1px solid rgba(255,255,255,0.05); vertical-align:top; color:#cbd5e1; }
</style>
</head>
<body>
<div id="studio-app">
<header class="studio-header">
<div class="studio-logo">AI-PVG Studio</div>
<div>
@auth
@php($adminUrl = \Illuminate\Support\Facades\Route::has('filament.admin.pages.dashboard') ? route('filament.admin.pages.dashboard') : '/auth')
<a href="{{ $adminUrl }}" style="color:var(--accent); text-decoration:none; font-weight:500;">Admin Panel</a>
@else
<a href="/auth/login" style="color:var(--accent); text-decoration:none; font-weight:500;">Login</a>
@endauth
</div>
</header>

<div class="studio-grid">
<div class="studio-card"><h3>News Articles</h3><div class="stat">{{ $stats['news_count'] }}</div><div class="label">Total articles from all sources</div></div>
<div class="studio-card"><h3>Video Scripts</h3><div class="stat">{{ $stats['script_total'] }}</div><div class="label">Total generated or drafted scripts</div></div>
<div class="studio-card"><h3>Generated</h3><div class="stat">{{ $stats['script_generated'] }}</div><div class="label">Scripts with generated narration and scenes</div></div>
<div class="studio-card"><h3>Approved</h3><div class="stat">{{ $stats['script_approved'] }}</div><div class="label">Administrator-approved video scripts</div></div>
<div class="studio-card"><h3>Pending</h3><div class="stat">{{ $stats['script_pending'] }}</div><div class="label">Draft scripts awaiting generation</div></div>
</div>

<div style="padding:0 2rem;">
<h2>News Triage</h2>
</div>
<p class="triage-note">Read-only score and cluster inspection. The only action here creates at most one draft script; dry-run records a plan without touching production scripts. No publishing controls are exposed.</p>
@if($triageMessage !== '')
<div class="triage-message">{{ $triageMessage }}</div>
@endif
@if(! $triageEnabled)
<p class="triage-note">Triage is currently disabled. Enable <code>automation.triage.enabled</code> or the <code>triage.news_scoring_enabled</code> feature flag to run the action.</p>
@endif
<div class="triage-grid">
<div class="studio-card">
<h3>Top Scored Stories</h3>
@forelse($triageScores as $score)
<div class="triage-row">
<strong>{{ $score->news?->title ?? 'News #'.$score->news_id }}</strong>
<span class="triage-badge {{ $score->band?->value === 'review' ? 'warning' : ($score->band?->value === 'below_threshold' ? 'muted' : '') }}">{{ $score->band?->getLabel() ?? 'Unscored' }}</span>
<div class="triage-meta">Composite {{ number_format((float) $score->composite_score, 2) }} · scored {{ $score->scored_at?->diffForHumans() ?? '—' }}</div>
<div class="triage-meta">Velocity {{ number_format((float) $score->velocity_score, 2) }} · Heat {{ number_format((float) $score->keyword_heat_score, 2) }} · Novelty {{ number_format((float) $score->novelty_score, 2) }} · Diversity {{ number_format((float) $score->source_diversity_score, 2) }} · Recency {{ number_format((float) $score->recency_score, 2) }}</div>
<details style="margin-top:.5rem;"><summary style="cursor:pointer; color:var(--primary); font-size:.82rem;">Score breakdown</summary><pre class="breakdown">{{ (string) json_encode($score->breakdown ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
</div>
@empty
<p style="color:rgba(255,255,255,0.5);">No scored stories yet.</p>
@endforelse
</div>

<div class="studio-card">
<h3>Story Clusters</h3>
@forelse($triageClusters as $cluster)
<div class="triage-row">
<strong>{{ $cluster->canonical_title }}</strong>
<div class="triage-meta">{{ $cluster->source_count }} sources · max score {{ number_format($cluster->maxCompositeScore(), 2) }} · confidence {{ number_format((float) $cluster->confidence, 2) }}</div>
<ul>
@foreach($cluster->news as $member)
<li>#{{ $member->id }} {{ $member->title }} — {{ $member->source?->name ?? $member->source_name ?? 'unknown source' }}@if($member->pivot?->is_primary) <strong>(primary)</strong>@endif</li>
@endforeach
</ul>
<details style="margin-top:.5rem;"><summary style="cursor:pointer; color:var(--primary); font-size:.82rem;">Grounded fact sheet</summary><pre class="breakdown">{{ (string) json_encode($cluster->fact_sheet ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
@if($triageEnabled)
<button class="btn" type="button" wire:click="generateScript({{ $cluster->id }})" wire:loading.attr="disabled" style="margin-top:.6rem;">Generate script</button>
@endif
</div>
@empty
<p style="color:rgba(255,255,255,0.5);">No 3–5 source clusters yet.</p>
@endforelse
</div>
</div>

<div style="padding:0 2rem;">
<h2>Script Agents</h2>
</div>
<p class="triage-note">Read-only hook and fact-check review. Selecting a hook updates only which generated variant is marked for review; approved scripts are protected and no publishing controls are exposed.</p>
@if($scriptMessage !== '')
<div class="triage-message">{{ $scriptMessage }}</div>
@endif
@if(! $scriptTablesAvailable)
<p class="triage-note">Script agent tables are not available. Run the additive G-03 migrations to enable review.</p>
@elseif($scriptPreviews === [])
<p class="triage-note">No script variants yet.</p>
@else
@if(! $scriptAgentsEnabled)
<p class="triage-note">Script agents are currently disabled. Enable <code>automation.script_agents.enabled</code> and the <code>script_agents.enabled</code> feature flag to allow hook selection.</p>
@endif
<div class="script-grid">
@foreach($scriptPreviews as $preview)
@php($script = $preview['script'])
<div class="script-card">
<h4>{{ $script->title }}</h4>
<div class="hook-meta">
Script #{{ $script->id }} · {{ $script->language }} · {{ $script->target_duration }}s ·
<span class="script-badge">{{ $script->status?->getLabel() ?? '—' }}</span>
@if($preview['approved'])
<span class="script-badge approved">Approved · protected</span>
@endif
</div>

<h5>Grounded hooks</h5>
@foreach($preview['hooks'] as $hook)
<div class="hook-row {{ $hook->selected ? 'selected' : '' }}">
<div class="hook-meta">
#{{ $hook->hook_index }} · {{ $hook->hook_type?->getLabel() ?? 'Hook' }} · score {{ number_format((float) $hook->score, 2) }}
@if($hook->selected)<span class="script-badge selected">Selected</span>@endif
</div>
<div class="hook-content">{{ $hook->content }}</div>
<div class="hook-meta">Sources: {{ is_array($hook->source_news_ids) && $hook->source_news_ids !== [] ? '#'.implode(', #', $hook->source_news_ids) : '—' }}</div>
@if($isAdmin)
<button class="btn small" type="button" wire:click="selectHook({{ $hook->id }})" wire:loading.attr="disabled" @if(! $scriptAgentsEnabled || $preview['approved']) disabled @endif>Select hook</button>
<button class="btn small secondary" type="button" wire:click="previewHook({{ $hook->id }})" wire:loading.attr="disabled">Preview directives</button>
@endif
</div>
@endforeach

@if($preview['preview'] !== null)
@php($directivePreview = $preview['preview'])
<h5>Scene directive preview — hook #{{ $directivePreview['hook_index'] }}</h5>
@if(! $directivePreview['available'])
<p class="triage-meta">Preview unavailable: {{ $directivePreview['error'] }}</p>
@else
<table class="directive-table">
<thead><tr><th>#</th><th>Type</th><th>Headline</th><th>Sec</th><th>Camera</th><th>B-roll</th><th>Highlight</th><th>SFX</th><th>Tone</th></tr></thead>
<tbody>
@foreach($directivePreview['scenes'] as $scene)
<tr>
<td>{{ $scene['order'] ?? '—' }}</td>
<td>{{ $scene['type'] ?? '—' }}</td>
<td>{{ $scene['headline'] ?? '—' }}</td>
<td>{{ $scene['duration'] ?? '—' }}</td>
<td>{{ $scene['directives']['camera_motion'] ?? '—' }}</td>
<td>{{ $scene['directives']['b_roll_query'] ?? '—' }}</td>
<td>{{ $scene['directives']['highlight_text'] ?? '—' }}</td>
<td>{{ $scene['directives']['sfx_cue'] ?? '—' }}</td>
<td>{{ $scene['directives']['emotion_tone'] ?? '—' }}</td>
</tr>
@endforeach
</tbody>
</table>
@if($directivePreview['diff'] === [])
<p class="triage-meta">No directive differences between the preview and the persisted scenes.</p>
@else
<h5>Directive diff vs persisted script</h5>
<table class="directive-table">
<thead><tr><th>Scene</th><th>Field</th><th>Persisted</th><th>Preview</th></tr></thead>
<tbody>
@foreach($directivePreview['diff'] as $row)
<tr><td>{{ $row['scene'] }}</td><td>{{ $row['field'] }}</td><td>{{ $row['persisted'] }}</td><td>{{ $row['preview'] }}</td></tr>
@endforeach
</tbody>
</table>
@endif
@endif
@endif

@if($preview['fact_checks']->isNotEmpty())
<h5>Fact checks</h5>
@foreach($preview['fact_checks'] as $check)
@php($verdict = $check->verdict?->value ?? 'uncertain')
<div class="fact-row {{ $verdict }}">
<span class="verdict-badge {{ $verdict }}">{{ $check->verdict?->getLabel() ?? 'Uncertain' }}</span>
<span style="margin-left:.4rem;">{{ $check->claim }}</span>
<div class="hook-meta">Sources: {{ is_array($check->source_news_ids) && $check->source_news_ids !== [] ? '#'.implode(', #', $check->source_news_ids) : '—' }} · critic {{ number_format((float) $check->critic_score, 2) }}</div>
<div class="hook-meta">{{ $check->evidence }}</div>
</div>
@endforeach
@endif
</div>
@endforeach
</div>
@endif

<div style="padding:0 2rem;">
<h2>Publishing &amp; Co-Pilot</h2>
</div>
<p class="triage-note">Read-only Phase H oversight. Publishing a video publicly requires two distinct administrators and is never automated. This panel exposes no publish, upload, approve, or reject controls.</p>
@if(! $phaseH['tables_available'])
<p class="triage-note">Phase H tables are not fully available. Run the additive publishing and Co-Pilot migrations to enable oversight.</p>
@else
<div class="studio-grid">
<div class="studio-card"><h3>Publishing Gate</h3><div class="stat">{{ $phaseH['publishing_enabled'] ? 'ON' : 'OFF' }}</div><div class="label">Config + feature flag (<code>{{ $phaseH['publishing_flag'] }}</code>); OFF is the safe default.</div></div>
<div class="studio-card"><h3>Approval Sessions</h3><div class="stat">{{ $phaseH['approval_total'] }}</div><div class="label">{{ $phaseH['approval_pending'] }} pending · {{ $phaseH['approval_approved'] }} approved</div></div>
<div class="studio-card"><h3>Dual-Admin Approved</h3><div class="stat">{{ $phaseH['distinct_approved'] }}</div><div class="label">Approved sessions with two distinct approver IDs. Publishing remains manual.</div></div>
<div class="studio-card"><h3>Co-Pilot Reviews</h3><div class="stat">{{ $phaseH['co_pilot_total'] }}</div><div class="label">{{ $phaseH['co_pilot_approved'] }} approved · {{ $phaseH['co_pilot_rejected'] }} rejected</div></div>
</div>

<div class="triage-grid">
<div class="studio-card">
<h3>Recent Publishing Approvals</h3>
@if($phaseH['sessions']->isEmpty())
<p style="color:rgba(255,255,255,0.5);">No publishing approval sessions yet.</p>
@else
<table class="directive-table">
<thead><tr><th>#</th><th>Script</th><th>Status</th><th>First admin</th><th>Second admin</th><th>Created</th></tr></thead>
<tbody>
@foreach($phaseH['sessions'] as $session)
<tr>
<td>{{ $session->id }}</td>
<td>{{ $session->videoScript?->title ?? '—' }}</td>
<td><span class="script-badge {{ $session->status === 'approved' ? 'approved' : '' }}">{{ ucfirst($session->status) }}</span></td>
<td>{{ $session->firstApprover?->name ?? '—' }}</td>
<td>{{ $session->secondApprover?->name ?? '—' }}</td>
<td>{{ $session->created_at?->diffForHumans() ?? '—' }}</td>
</tr>
@endforeach
</tbody>
</table>
@endif
</div>

<div class="studio-card">
<h3>Recent Co-Pilot Reviews</h3>
@if($phaseH['reviews']->isEmpty())
<p style="color:rgba(255,255,255,0.5);">No Co-Pilot review records yet.</p>
@else
<table class="directive-table">
<thead><tr><th>#</th><th>Reviewer</th><th>Target</th><th>Action</th><th>Status</th><th>When</th></tr></thead>
<tbody>
@foreach($phaseH['reviews'] as $review)
<tr>
<td>{{ $review->id }}</td>
<td>{{ $review->user?->name ?? '—' }}</td>
<td>{{ ucfirst($review->target_type) }} #{{ $review->target_id }}</td>
<td>{{ ucfirst($review->action) }}</td>
<td>{{ ucfirst($review->status) }}</td>
<td>{{ $review->created_at?->diffForHumans() ?? '—' }}</td>
</tr>
@endforeach
</tbody>
</table>
@endif
</div>
</div>
@endif

<div class="studio-actions">
<h2>Theme Customization</h2>
<form wire:submit.prevent="saveTheme" class="theme-form">
<label>Theme Name</label>
<input type="text" wire:model="themeName" value="Cyber Dark">
<label>Primary</label>
<input type="color" wire:model="primaryColor" value="#00d4ff">
<label>Mode</label>
<select wire:model="mode"><option value="dark">Dark</option><option value="light">Light</option></select>
<button class="btn" type="submit">Save Theme</button>
</form>
<p style="margin-top:.75rem; color:rgba(255,255,255,0.5); font-size:.85rem;">Theme preferences persist per user. Changes reflect immediately using CSS variables.</p>
</div>

<hr style="margin:0 2rem; border-color:rgba(255,255,255,0.05);">

<div style="padding:2rem;">
<h2>Recent Scripts</h2>
@if($scripts->isEmpty())
<p style="color:rgba(255,255,255,0.5);">No video scripts found.</p>
@else
@foreach($scripts as $script)
<div style="margin-top:.75rem; padding:1rem; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.06); border-radius:var(--radius);">
<strong>{{ $script->title }}</strong> — {{ $script->language }} — {{ $script->target_duration }}s — <span style="color:#00d4ff;">{{ $script->status }}</span>
</div>
@endforeach
@endif
</div>
</div>
</body>
</html>

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
.theme-form { display:flex; gap:.75rem; flex-wrap:wrap; align-items:center; margin-top:1rem; }
.theme-form label { font-size:.85rem; color:rgba(255,255,255,0.7); }
.theme-form input[type="color"], .theme-form input[type="text"], .theme-form select { background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:var(--text); padding:.35rem .6rem; border-radius:var(--radius); }
</style>
</head>
<body>
<header class="studio-header">
<div class="studio-logo">AI-PVG Studio</div>
<div>
@auth
<a href="{{ route('filament.admin.pages.dashboard') }}" style="color:var(--accent); text-decoration:none; font-weight:500;">Admin Panel</a>
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
</body>
</html>

<x-filament-panels::page>
@php
    $stats = $this->stats();
    $completedCount = count($completedSteps);
    $percent = (int) round(($completedCount / 6) * 100);
    $clusters = $this->clusterOptions();
    $scripts = $this->scriptOptions();
    $m1 = $this->step1Metrics();
    $m2 = $this->step2Metrics();
    $m3 = $this->step3Metrics();
    $m4 = $this->step4Metrics();
    $m5 = $this->step5Metrics();
    $m6 = $this->step6Metrics();
@endphp

<style>
/* ── AI-PVG Pipeline Studio Scoped Design System ──────────────────────── */
.pvg-studio {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
    color: #e2e8f0;
    font-family: inherit;
}

/* Command Center Top Hero */
.pvg-hero-card {
    background: linear-gradient(135deg, #0b1120 0%, #1e293b 100%);
    border: 1px solid #334155;
    border-radius: 1rem;
    padding: 1.5rem;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.4), 0 8px 10px -6px rgba(0, 0, 0, 0.3);
}

.pvg-hero-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.25rem;
}

.pvg-hero-title-group {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.pvg-hero-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #f8fafc;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin: 0;
    letter-spacing: -0.01em;
}

.pvg-hero-subtitle {
    font-size: 0.8125rem;
    color: #94a3b8;
    margin: 0;
}

.pvg-hero-actions {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
}

/* 6-Node Stepper Bar */
.pvg-stepper-track {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 0.5rem;
    margin-bottom: 1.25rem;
}

@media (max-width: 768px) {
    .pvg-stepper-track {
        grid-template-columns: repeat(3, 1fr);
    }
}

.pvg-stepper-node {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.375rem;
    padding: 0.625rem 0.5rem;
    border-radius: 0.5rem;
    font-size: 0.75rem;
    font-weight: 600;
    transition: all 0.25s ease;
    text-align: center;
    white-space: nowrap;
}

.pvg-stepper-node.is-completed {
    background: rgba(16, 185, 129, 0.15);
    border: 1px solid rgba(16, 185, 129, 0.45);
    color: #34d399;
}

.pvg-stepper-node.is-active {
    background: rgba(14, 165, 233, 0.2);
    border: 1px solid #0ea5e9;
    color: #38bdf8;
    box-shadow: 0 0 14px rgba(14, 165, 233, 0.35);
}

.pvg-stepper-node.is-locked {
    background: rgba(30, 41, 59, 0.6);
    border: 1px solid #334155;
    color: #64748b;
}

/* Progress bar */
.pvg-progress-container {
    margin-bottom: 1.25rem;
}

.pvg-progress-bar-bg {
    width: 100%;
    height: 0.5rem;
    background: #1e293b;
    border-radius: 9999px;
    overflow: hidden;
    border: 1px solid #334155;
}

.pvg-progress-bar-fill {
    height: 100%;
    border-radius: 9999px;
    background: linear-gradient(90deg, #0ea5e9 0%, #10b981 100%);
    transition: width 0.5s cubic-bezier(0.4, 0, 0.2, 1);
}

/* 6-Card KPI Strip */
.pvg-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: 0.75rem;
}

.pvg-kpi-card {
    background: rgba(15, 23, 42, 0.75);
    border: 1px solid #334155;
    border-top: 2px solid #0ea5e9;
    border-radius: 0.5rem;
    padding: 0.625rem 0.875rem;
    display: flex;
    flex-direction: column;
}

.pvg-kpi-label {
    font-size: 0.6875rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #94a3b8;
    margin-bottom: 0.25rem;
}

.pvg-kpi-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: #f1f5f9;
    font-variant-numeric: tabular-nums;
}

/* Execution Preloader Banner */
.pvg-preloader-banner {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    background: linear-gradient(90deg, rgba(14, 165, 233, 0.15) 0%, rgba(99, 102, 241, 0.15) 100%);
    border: 1px solid #0ea5e9;
    border-radius: 0.75rem;
    padding: 1rem 1.25rem;
    color: #38bdf8;
    font-size: 0.875rem;
    font-weight: 500;
    box-shadow: 0 0 15px rgba(14, 165, 233, 0.2);
    animation: pvg-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

@keyframes pvg-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.75; }
}

.pvg-spinner {
    width: 1.25rem;
    height: 1.25rem;
    border: 2px solid rgba(56, 189, 248, 0.25);
    border-top-color: #38bdf8;
    border-radius: 50%;
    animation: pvg-spin 0.8s linear infinite;
    flex-shrink: 0;
}

@keyframes pvg-spin {
    to { transform: rotate(360deg); }
}

/* Timeline Cards */
.pvg-timeline {
    display: flex;
    flex-direction: column;
    gap: 1.125rem;
}

.pvg-step-card {
    border-radius: 0.875rem;
    padding: 1.25rem;
    background: #0f172a;
    border: 1px solid #334155;
    transition: all 0.2s ease-in-out;
}

.pvg-step-card.is-active {
    background: linear-gradient(180deg, rgba(14, 165, 233, 0.08) 0%, rgba(15, 23, 42, 0.95) 100%);
    border: 1px solid #0ea5e9;
    box-shadow: 0 6px 24px -2px rgba(14, 165, 233, 0.25);
}

.pvg-step-card.is-completed {
    background: rgba(15, 23, 42, 0.85);
    border: 1px solid rgba(16, 185, 129, 0.45);
}

.pvg-step-card.is-locked {
    background: rgba(15, 23, 42, 0.45);
    border: 1px solid #1e293b;
    opacity: 0.6;
}

.pvg-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.875rem;
}

.pvg-card-title-group {
    display: flex;
    align-items: center;
    gap: 0.875rem;
}

.pvg-step-badge {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 50%;
    font-size: 0.875rem;
    font-weight: 700;
    flex-shrink: 0;
}

.pvg-step-badge.is-completed {
    background: #10b981;
    color: #ffffff;
}

.pvg-step-badge.is-active {
    background: #0ea5e9;
    color: #ffffff;
    box-shadow: 0 0 12px rgba(14, 165, 233, 0.5);
}

.pvg-step-badge.is-locked {
    background: #334155;
    color: #94a3b8;
}

.pvg-card-title {
    font-size: 1rem;
    font-weight: 600;
    color: #f8fafc;
    margin: 0;
}

.pvg-card-subtitle {
    font-size: 0.75rem;
    color: #94a3b8;
    margin: 0.125rem 0 0 0;
}

/* Controls Grid */
.pvg-controls-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
    background: rgba(30, 41, 59, 0.5);
    border: 1px solid #334155;
    border-radius: 0.625rem;
    padding: 1rem;
    margin: 0.875rem 0;
}

.pvg-field-group {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
}

.pvg-field-label {
    font-size: 0.6875rem;
    font-weight: 600;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.pvg-select, .pvg-input {
    background: #0b1120;
    border: 1px solid #475569;
    border-radius: 0.375rem;
    padding: 0.5rem 0.75rem;
    color: #f1f5f9;
    font-size: 0.8125rem;
    outline: none;
    transition: border-color 0.2s;
    width: 100%;
}

.pvg-select:focus, .pvg-input:focus {
    border-color: #0ea5e9;
    box-shadow: 0 0 0 2px rgba(14, 165, 233, 0.2);
}

.pvg-checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8125rem;
    color: #cbd5e1;
    cursor: pointer;
    margin-top: 0.25rem;
}

.pvg-checkbox {
    width: 1rem;
    height: 1rem;
    accent-color: #0ea5e9;
    border-radius: 0.25rem;
    cursor: pointer;
}

/* Summary Box */
.pvg-summary-box {
    background: rgba(16, 185, 129, 0.08);
    border: 1px solid rgba(16, 185, 129, 0.25);
    border-radius: 0.5rem;
    padding: 0.75rem 1rem;
    font-size: 0.8125rem;
    color: #a7f3d0;
    margin-bottom: 0.875rem;
}

/* Card Footer */
.pvg-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    margin-top: 1rem;
    padding-top: 0.875rem;
    border-top: 1px solid rgba(51, 65, 85, 0.6);
}

/* Media Card */
.pvg-media-card {
    background: #020617;
    border: 1px solid #334155;
    border-radius: 0.5rem;
    padding: 0.75rem 1rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-top: 0.75rem;
}
</style>

<div class="pvg-studio">

    {{-- ═══════════════════════════════════════════════════════════════════
         HERO COMMAND CENTER HEADER
    ════════════════════════════════════════════════════════════════════ --}}
    <div class="pvg-hero-card">
        <div class="pvg-hero-header">
            <div class="pvg-hero-title-group">
                <h1 class="pvg-hero-title">
                    <span>AI-PVG Autonomous Pipeline Studio</span>
                    <x-filament::badge color="danger">
                        🔒 PUBLISHING LOCKED OFF (100% LOCAL)
                    </x-filament::badge>
                </h1>
                <p class="pvg-hero-subtitle">
                    AI-PVG Autonomous Pipeline Wizard — Step-by-step local media orchestration engine
                </p>
            </div>

            <div class="pvg-hero-actions">
                <x-filament::button
                    color="gray"
                    size="sm"
                    icon="heroicon-m-lock-open"
                    wire:click="toggleUnlockAllSteps"
                >
                    {{ $unlockAllSteps ? 'All Steps Unlocked' : 'Strict Sequential Mode' }}
                </x-filament::button>

                <x-filament::button
                    color="danger"
                    outlined
                    size="sm"
                    icon="heroicon-m-arrow-path"
                    wire:click="resetWizard"
                >
                    Reset Wizard
                </x-filament::button>
            </div>
        </div>

        {{-- 6-Node Stepper Track --}}
        <div class="pvg-stepper-track">
            @foreach([
                1 => '1. Ingest',
                2 => '2. Triage',
                3 => '3. Script',
                4 => '4. Audio/Captions',
                5 => '5. Visuals/Formats',
                6 => '6. Render MP4',
            ] as $nodeIdx => $nodeLabel)
                @php
                    $nodeCompleted = in_array($nodeIdx, $completedSteps, true);
                    $nodeActive = ($currentStep === $nodeIdx);
                    $nodeClass = $nodeCompleted ? 'is-completed' : ($nodeActive ? 'is-active' : 'is-locked');
                @endphp
                <div class="pvg-stepper-node {{ $nodeClass }}">
                    @if($nodeCompleted)
                        <span>✓</span>
                    @endif
                    <span>{{ $nodeLabel }}</span>
                </div>
            @endforeach
        </div>

        {{-- Progress Bar & Step Counter --}}
        <div class="pvg-progress-container">
            <div class="flex items-center justify-between text-xs text-gray-400 mb-1.5 font-medium">
                <span>Progress: <strong>{{ $completedCount }} / 6 Steps Completed</strong></span>
                <span>
                    @if($completedCount === 6)
                        <strong class="text-emerald-400">100% Complete 🎉 All Stages Finished!</strong>
                    @else
                        Step {{ min($currentStep, 6) }} of 6 — <strong>{{ $percent }}% Complete</strong>
                    @endif
                </span>
            </div>
            <div class="pvg-progress-bar-bg">
                <div class="pvg-progress-bar-fill" style="width: {{ max($percent, 2) }}%;"></div>
            </div>
        </div>

        {{-- 6-Card KPI Metric Strip --}}
        <div class="pvg-kpi-grid">
            <div class="pvg-kpi-card" style="border-top-color: #0ea5e9;">
                <span class="pvg-kpi-label">Ready News</span>
                <span class="pvg-kpi-value">{{ $stats['ready_news'] }}</span>
            </div>
            <div class="pvg-kpi-card" style="border-top-color: #8b5cf6;">
                <span class="pvg-kpi-label">Scored Articles</span>
                <span class="pvg-kpi-value">{{ $stats['scores'] }}</span>
            </div>
            <div class="pvg-kpi-card" style="border-top-color: #ec4899;">
                <span class="pvg-kpi-label">Story Clusters</span>
                <span class="pvg-kpi-value">{{ $stats['clusters'] }}</span>
            </div>
            <div class="pvg-kpi-card" style="border-top-color: #f97316;">
                <span class="pvg-kpi-label">Video Scripts</span>
                <span class="pvg-kpi-value">{{ $stats['scripts'] }}</span>
            </div>
            <div class="pvg-kpi-card" style="border-top-color: #14b8a6;">
                <span class="pvg-kpi-label">Caption Tracks</span>
                <span class="pvg-kpi-value">{{ $stats['captions'] }}</span>
            </div>
            <div class="pvg-kpi-card" style="border-top-color: #10b981;">
                <span class="pvg-kpi-label">Verified MP4s</span>
                <span class="pvg-kpi-value">{{ $stats['mp4s'] }}</span>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════
         LIVE EXECUTION PRELOADER BANNER
    ════════════════════════════════════════════════════════════════════ --}}
    <div wire:loading.flex wire:target="runStep1,runStep2,runStep3,runStep4,runStep5,runStep6,runPreflight" class="pvg-preloader-banner">
        <div class="pvg-spinner"></div>
        <span>Executing pipeline stage... Please wait while AI-PVG processes this step in local mode.</span>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════
         THE 6 INTERACTIVE STEP CARDS TIMELINE
    ════════════════════════════════════════════════════════════════════ --}}
    <div class="pvg-timeline">

        {{-- ─────────────────────────────────────────────────────────────
             STEP 1: Prepare & Load Breaking News
        ────────────────────────────────────────────────────────────── --}}
        @php
            $s1Done = in_array(1, $completedSteps, true);
            $s1Active = ($currentStep === 1 || $unlockAllSteps);
            $s1Class = $s1Done ? 'is-completed' : ($s1Active ? 'is-active' : 'is-locked');
        @endphp
        <div class="pvg-step-card {{ $s1Class }}">
            <div class="pvg-card-header">
                <div class="pvg-card-title-group">
                    <div class="pvg-step-badge {{ $s1Class }}">
                        @if($s1Done) ✓ @else 01 @endif
                    </div>
                    <div>
                        <h2 class="pvg-card-title">Step 1: Prepare &amp; Load Breaking News</h2>
                        <p class="pvg-card-subtitle">Seed realistic multi-source breaking tech news and ensure local pipeline flags are active.</p>
                    </div>
                </div>

                <div>
                    @if($s1Done)
                        <x-filament::badge color="success">✓ Completed</x-filament::badge>
                    @elseif($s1Active)
                        <x-filament::badge color="info">Ready to Run</x-filament::badge>
                    @else
                        <x-filament::badge color="gray">🔒 Waiting for Step 0</x-filament::badge>
                    @endif
                </div>
            </div>

            @if($s1Done)
                <div class="pvg-summary-box">
                    ✓ Stage complete: <strong>{{ $m1['ready_news'] }}</strong> ready news articles across <strong>{{ $m1['active_sources'] }}</strong> active sources. Pipeline flags active.
                </div>
            @endif

            <div class="pvg-controls-grid">
                <div class="pvg-field-group">
                    <label class="pvg-field-label">News Ingestion Mode</label>
                    <select wire:model.live="step1SourceMode" class="pvg-select">
                        <option value="mock_cluster">Seed 3-5 Source Breaking News Cluster (Guaranteed)</option>
                        <option value="ready_news">Use Existing Ready News ({{ $m1['ready_news'] }} articles)</option>
                        <option value="live_rss">Import Live RSS Feeds Now</option>
                    </select>
                </div>

                <div class="pvg-field-group" style="justify-content: flex-end;">
                    <label class="pvg-checkbox-label">
                        <input type="checkbox" wire:model.live="step1AutoEnableFlags" class="pvg-checkbox">
                        <span>Auto-enable local pipeline flags (Keep Publishing OFF)</span>
                    </label>
                </div>
            </div>

            <div class="pvg-card-footer">
                <div class="text-xs text-gray-400">
                    Active Sources: <strong>{{ $m1['active_sources'] }}</strong> · Categories: <strong>{{ $m1['categories'] }}</strong>
                </div>

                <div class="flex items-center gap-2">
                    @if($s1Done)
                        <x-filament::button color="gray" size="sm" wire:click="runStep1">
                            Re-run Step 1
                        </x-filament::button>
                    @endif
                    <x-filament::button
                        color="primary"
                        size="md"
                        icon="heroicon-m-bolt"
                        wire:click="runStep1"
                    >
                        {{ $s1Done ? 'Re-load Breaking News' : 'Run Step 1: Prepare & Load News' }}
                    </x-filament::button>
                </div>
            </div>
        </div>

        {{-- ─────────────────────────────────────────────────────────────
             STEP 2: Score News & Build Story Cluster
        ────────────────────────────────────────────────────────────── --}}
        @php
            $s2Done = in_array(2, $completedSteps, true);
            $s2Active = ($currentStep === 2 || $unlockAllSteps);
            $s2Locked = (!$unlockAllSteps && $currentStep < 2 && !$s2Done);
            $s2Class = $s2Done ? 'is-completed' : ($s2Active ? 'is-active' : 'is-locked');
        @endphp
        <div class="pvg-step-card {{ $s2Class }}">
            <div class="pvg-card-header">
                <div class="pvg-card-title-group">
                    <div class="pvg-step-badge {{ $s2Class }}">
                        @if($s2Done) ✓ @else 02 @endif
                    </div>
                    <div>
                        <h2 class="pvg-card-title">Step 2: Score News &amp; Build Story Cluster (G-02)</h2>
                        <p class="pvg-card-subtitle">Autonomous news scoring, semantic clustering, and auto-greenlight triage.</p>
                    </div>
                </div>

                <div>
                    @if($s2Done)
                        <x-filament::badge color="success">✓ Completed</x-filament::badge>
                    @elseif($s2Active)
                        <x-filament::badge color="info">Ready to Run</x-filament::badge>
                    @else
                        <x-filament::badge color="gray">🔒 Waiting for Step 1</x-filament::badge>
                    @endif
                </div>
            </div>

            @if($s2Done)
                <div class="pvg-summary-box">
                    ✓ Story cluster formed: <strong>{{ $m2['latest_cluster'] }}</strong> (Confidence: {{ $m2['latest_score'] }}). Total scored: <strong>{{ $m2['scored'] }}</strong>.
                </div>
            @endif

            <div class="pvg-controls-grid">
                <div class="pvg-field-group">
                    <label class="pvg-field-label">Target Story Cluster</label>
                    <select wire:model.live="selectedClusterId" class="pvg-select" @if($s2Locked) disabled @endif>
                        <option value="">— Auto-Run Triage &amp; Select Top Cluster —</option>
                        @foreach($clusters as $cid => $clabel)
                            <option value="{{ $cid }}">{{ $clabel }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="pvg-field-group">
                    <label class="pvg-field-label">Similarity Threshold</label>
                    <input
                        type="number"
                        step="0.05"
                        min="0.1"
                        max="0.95"
                        wire:model.blur="step2SimilarityThreshold"
                        class="pvg-input"
                        @if($s2Locked) disabled @endif
                    >
                    <span class="text-[11px] text-gray-400">0.10 (broad) to 0.95 (strict match)</span>
                </div>
            </div>

            <div class="pvg-card-footer">
                <div class="text-xs text-gray-400">
                    Scored: <strong>{{ $m2['scored'] }}</strong> · Clusters: <strong>{{ $m2['clusters'] }}</strong>
                </div>

                <div class="flex items-center gap-2">
                    @if($s2Done)
                        <x-filament::button color="gray" size="sm" wire:click="runStep2">
                            Re-run Step 2
                        </x-filament::button>
                    @endif
                    <x-filament::button
                        color="primary"
                        size="md"
                        icon="heroicon-m-sparkles"
                        wire:click="runStep2"
                        :disabled="$s2Locked"
                    >
                        {{ $s2Done ? 'Re-Score & Cluster' : 'Run Step 2: Score & Select Cluster' }}
                    </x-filament::button>
                </div>
            </div>
        </div>

        {{-- ─────────────────────────────────────────────────────────────
             STEP 3: 3-Agent Script & Fact-Check Pipeline
        ────────────────────────────────────────────────────────────── --}}
        @php
            $s3Done = in_array(3, $completedSteps, true);
            $s3Active = ($currentStep === 3 || $unlockAllSteps);
            $s3Locked = (!$unlockAllSteps && $currentStep < 3 && !$s3Done);
            $s3Class = $s3Done ? 'is-completed' : ($s3Active ? 'is-active' : 'is-locked');
        @endphp
        <div class="pvg-step-card {{ $s3Class }}">
            <div class="pvg-card-header">
                <div class="pvg-card-title-group">
                    <div class="pvg-step-badge {{ $s3Class }}">
                        @if($s3Done) ✓ @else 03 @endif
                    </div>
                    <div>
                        <h2 class="pvg-card-title">Step 3: Generate AI Script, 3 Hooks &amp; Fact-Check (G-03)</h2>
                        <p class="pvg-card-subtitle">HookSpecialistAgent → NarrativeArchitectAgent → FactCheckerAgent execution chain.</p>
                    </div>
                </div>

                <div>
                    @if($s3Done)
                        <x-filament::badge color="success">✓ Completed</x-filament::badge>
                    @elseif($s3Active)
                        <x-filament::badge color="info">Ready to Run</x-filament::badge>
                    @else
                        <x-filament::badge color="gray">🔒 Waiting for Step 2</x-filament::badge>
                    @endif
                </div>
            </div>

            @if($s3Done)
                <div class="pvg-summary-box">
                    ✓ Script created: <strong>{{ $m3['title'] }}</strong> ({{ $m3['duration'] }}) with <strong>{{ $m3['variants'] }}</strong> hook variants and <strong>{{ $m3['scenes_count'] }}</strong> scenes.
                </div>
            @endif

            <div class="pvg-controls-grid">
                <div class="pvg-field-group">
                    <label class="pvg-field-label">Target Cluster / Source</label>
                    <select wire:model.live="selectedClusterId" class="pvg-select" @if($s3Locked) disabled @endif>
                        <option value="">— Latest Cluster / Ready News —</option>
                        @foreach($clusters as $cid => $clabel)
                            <option value="{{ $cid }}">{{ $clabel }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="pvg-field-group">
                    <label class="pvg-field-label">Generation Mode</label>
                    <select wire:model.live="step3Mode" class="pvg-select" @if($s3Locked) disabled @endif>
                        <option value="deterministic">Deterministic Grounded (Instant Local)</option>
                        <option value="ai">Ollama Local AI (LLM Gated)</option>
                    </select>
                </div>

                <div class="pvg-field-group">
                    <label class="pvg-field-label">Target Duration (Seconds)</label>
                    <input
                        type="number"
                        min="10"
                        max="180"
                        step="5"
                        wire:model.blur="step3Duration"
                        class="pvg-input"
                        @if($s3Locked) disabled @endif
                    >
                    <span class="text-[11px] text-gray-400">10s – 180s (e.g. 30s Shorts, 45s Reels, 60s TikTok)</span>
                </div>
            </div>

            <div class="pvg-card-footer">
                <div class="text-xs text-gray-400">
                    Hook Variants: <strong>{{ $m3['variants'] }}</strong> · Scenes: <strong>{{ $m3['scenes_count'] }}</strong>
                </div>

                <div class="flex items-center gap-2">
                    @if($s3Done)
                        <x-filament::button color="gray" size="sm" wire:click="runStep3">
                            Re-run Step 3
                        </x-filament::button>
                    @endif
                    <x-filament::button
                        color="primary"
                        size="md"
                        icon="heroicon-m-document-text"
                        wire:click="runStep3"
                        :disabled="$s3Locked"
                    >
                        {{ $s3Done ? 'Re-generate Script' : 'Run Step 3: Generate Script & 3 Hooks' }}
                    </x-filament::button>
                </div>
            </div>
        </div>

        {{-- ─────────────────────────────────────────────────────────────
             STEP 4: Voiceover, Word-Level Captions & Audio Mix
        ────────────────────────────────────────────────────────────── --}}
        @php
            $s4Done = in_array(4, $completedSteps, true);
            $s4Active = ($currentStep === 4 || $unlockAllSteps);
            $s4Locked = (!$unlockAllSteps && $currentStep < 4 && !$s4Done);
            $s4Class = $s4Done ? 'is-completed' : ($s4Active ? 'is-active' : 'is-locked');
        @endphp
        <div class="pvg-step-card {{ $s4Class }}">
            <div class="pvg-card-header">
                <div class="pvg-card-title-group">
                    <div class="pvg-step-badge {{ $s4Class }}">
                        @if($s4Done) ✓ @else 04 @endif
                    </div>
                    <div>
                        <h2 class="pvg-card-title">Step 4: Generate Voice, Word Captions &amp; Audio Mix</h2>
                        <p class="pvg-card-subtitle">TTS voice synthesis, monotonic word timestamps, caption track alignment, and audio ducking.</p>
                    </div>
                </div>

                <div>
                    @if($s4Done)
                        <x-filament::badge color="success">✓ Completed</x-filament::badge>
                    @elseif($s4Active)
                        <x-filament::badge color="info">Ready to Run</x-filament::badge>
                    @else
                        <x-filament::badge color="gray">🔒 Waiting for Step 3</x-filament::badge>
                    @endif
                </div>
            </div>

            @if($s4Done)
                <div class="pvg-summary-box">
                    ✓ Voice track synthesized &amp; <strong>{{ $m4['word_timings'] }}</strong> monotonic word timings generated. Caption track status: <strong>{{ $m4['caption_status'] }}</strong>.
                </div>
            @endif

            <div class="pvg-controls-grid">
                <div class="pvg-field-group">
                    <label class="pvg-field-label">Target VideoScript</label>
                    <select wire:model.live="selectedScriptId" class="pvg-select" @if($s4Locked) disabled @endif>
                        <option value="">— Latest VideoScript —</option>
                        @foreach($scripts as $sid => $slabel)
                            <option value="{{ $sid }}">{{ $slabel }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="pvg-field-group">
                    <label class="pvg-field-label">Caption Style Preset</label>
                    <select wire:model.live="step4CaptionStyle" class="pvg-select" @if($s4Locked) disabled @endif>
                        <option value="karaoke">Karaoke Word Highlight (Dynamic)</option>
                        <option value="highlight">Static Phrase Highlight</option>
                        <option value="lower_third">Lower-Third News Anchor</option>
                    </select>
                </div>

                <div class="pvg-field-group" style="justify-content: flex-end;">
                    <label class="pvg-checkbox-label">
                        <input type="checkbox" wire:model.live="step4Ducking" class="pvg-checkbox" @if($s4Locked) disabled @endif>
                        <span>Enable -18dB Sidechain Audio Ducking</span>
                    </label>
                </div>
            </div>

            <div class="pvg-card-footer">
                <div class="text-xs text-gray-400">
                    Word Timings: <strong>{{ $m4['word_timings'] }}</strong> · Status: <strong>{{ $m4['caption_status'] }}</strong>
                </div>

                <div class="flex items-center gap-2">
                    @if($s4Done)
                        <x-filament::button color="gray" size="sm" wire:click="runStep4">
                            Re-run Step 4
                        </x-filament::button>
                    @endif
                    <x-filament::button
                        color="primary"
                        size="md"
                        icon="heroicon-m-microphone"
                        wire:click="runStep4"
                        :disabled="$s4Locked"
                    >
                        {{ $s4Done ? 'Re-generate Audio & Captions' : 'Run Step 4: Build Captions & Audio Mix' }}
                    </x-filament::button>
                </div>
            </div>
        </div>

        {{-- ─────────────────────────────────────────────────────────────
             STEP 5: Visual Scene Props & Multi-Format Fan-Out
        ────────────────────────────────────────────────────────────── --}}
        @php
            $s5Done = in_array(5, $completedSteps, true);
            $s5Active = ($currentStep === 5 || $unlockAllSteps);
            $s5Locked = (!$unlockAllSteps && $currentStep < 5 && !$s5Done);
            $s5Class = $s5Done ? 'is-completed' : ($s5Active ? 'is-active' : 'is-locked');
        @endphp
        <div class="pvg-step-card {{ $s5Class }}">
            <div class="pvg-card-header">
                <div class="pvg-card-title-group">
                    <div class="pvg-step-badge {{ $s5Class }}">
                        @if($s5Done) ✓ @else 05 @endif
                    </div>
                    <div>
                        <h2 class="pvg-card-title">Step 5: Attach Visual B-Roll &amp; Multi-Format Plans (G-05 / G-06)</h2>
                        <p class="pvg-card-subtitle">Visual asset harvesting, Remotion scene prop injection, and 9:16 / 16:9 / 1:1 fan-out plans.</p>
                    </div>
                </div>

                <div>
                    @if($s5Done)
                        <x-filament::badge color="success">✓ Completed</x-filament::badge>
                    @elseif($s5Active)
                        <x-filament::badge color="info">Ready to Run</x-filament::badge>
                    @else
                        <x-filament::badge color="gray">🔒 Waiting for Step 4</x-filament::badge>
                    @endif
                </div>
            </div>

            @if($s5Done)
                <div class="pvg-summary-box">
                    ✓ Visual scene props injected for <strong>{{ $m5['scenes_count'] }}</strong> scenes. Formats staged: <strong>{{ $m5['formats'] }}</strong>.
                </div>
            @endif

            <div class="pvg-controls-grid">
                <div class="pvg-field-group">
                    <label class="pvg-field-label">Multi-Format Presets</label>
                    <div class="flex items-center gap-3 mt-1 text-xs">
                        <label class="pvg-checkbox-label">
                            <input type="checkbox" checked disabled class="pvg-checkbox">
                            <span>9:16 Shorts (1080x1920)</span>
                        </label>
                        <label class="pvg-checkbox-label">
                            <input type="checkbox" checked disabled class="pvg-checkbox">
                            <span>16:9 Long (1920x1080)</span>
                        </label>
                        <label class="pvg-checkbox-label">
                            <input type="checkbox" checked disabled class="pvg-checkbox">
                            <span>1:1 Square (1080x1080)</span>
                        </label>
                    </div>
                </div>

                <div class="pvg-field-group" style="justify-content: flex-end;">
                    <label class="pvg-checkbox-label">
                        <input type="checkbox" wire:model.live="step5Thumbnails" class="pvg-checkbox" @if($s5Locked) disabled @endif>
                        <span>Generate A/B Thumbnail Variants &amp; Titles</span>
                    </label>
                </div>
            </div>

            <div class="pvg-card-footer">
                <div class="text-xs text-gray-400">
                    Scenes: <strong>{{ $m5['scenes_count'] }}</strong> · Formats: <strong>{{ $m5['formats'] }}</strong>
                </div>

                <div class="flex items-center gap-2">
                    @if($s5Done)
                        <x-filament::button color="gray" size="sm" wire:click="runStep5">
                            Re-run Step 5
                        </x-filament::button>
                    @endif
                    <x-filament::button
                        color="primary"
                        size="md"
                        icon="heroicon-m-photo"
                        wire:click="runStep5"
                        :disabled="$s5Locked"
                    >
                        {{ $s5Done ? 'Re-inject Scene Props' : 'Run Step 5: Inject Visual Props & Formats' }}
                    </x-filament::button>
                </div>
            </div>
        </div>

        {{-- ─────────────────────────────────────────────────────────────
             STEP 6: Render & Verify Final MP4 Video
        ────────────────────────────────────────────────────────────── --}}
        @php
            $s6Done = in_array(6, $completedSteps, true);
            $s6Active = ($currentStep === 6 || $unlockAllSteps);
            $s6Locked = (!$unlockAllSteps && $currentStep < 6 && !$s6Done);
            $s6Class = $s6Done ? 'is-completed' : ($s6Active ? 'is-active' : 'is-locked');
        @endphp
        <div class="pvg-step-card {{ $s6Class }}">
            <div class="pvg-card-header">
                <div class="pvg-card-title-group">
                    <div class="pvg-step-badge {{ $s6Class }}">
                        @if($s6Done) ✓ @else 06 @endif
                    </div>
                    <div>
                        <h2 class="pvg-card-title">Step 6: Render Final MP4 Video Locally (G-00 / G-06)</h2>
                        <p class="pvg-card-subtitle">Real local Remotion rendering, FFprobe verification, and playable MP4 creation.</p>
                    </div>
                </div>

                <div>
                    @if($s6Done)
                        <x-filament::badge color="success">✓ Completed 🎉 (100%)</x-filament::badge>
                    @elseif($s6Active)
                        <x-filament::badge color="info">Ready to Run</x-filament::badge>
                    @else
                        <x-filament::badge color="gray">🔒 Waiting for Step 5</x-filament::badge>
                    @endif
                </div>
            </div>

            @if($s6Done)
                <div class="pvg-summary-box">
                    ✓ Final video rendered &amp; verified locally in <code class="font-mono text-emerald-300">storage/app/video_out/</code>. External publishing remains locked OFF.
                </div>
            @endif

            <div class="pvg-controls-grid">
                <div class="pvg-field-group">
                    <label class="pvg-field-label">Render Backend Engine</label>
                    <select wire:model.live="step6Backend" class="pvg-select" @if($s6Locked) disabled @endif>
                        <option value="remotion">Remotion Engine (Primary H.264 / React)</option>
                        <option value="hyperframes">HyperFrames Engine (HTML/CSS/GSAP)</option>
                    </select>
                </div>

                <div class="pvg-field-group">
                    <label class="pvg-field-label">Aspect Ratio</label>
                    <select wire:model.live="step6AspectRatio" class="pvg-select" @if($s6Locked) disabled @endif>
                        <option value="9:16">9:16 Vertical (1080x1920) — Shorts/Reels</option>
                        <option value="16:9">16:9 Horizontal (1920x1080) — YouTube Long</option>
                        <option value="1:1">1:1 Square (1080x1080) — Instagram</option>
                    </select>
                </div>

                <div class="pvg-field-group">
                    <label class="pvg-field-label">Visual Theme</label>
                    <select wire:model.live="step6Theme" class="pvg-select" @if($s6Locked) disabled @endif>
                        <option value="cyber-pulse">Cyber Pulse (Dark / Cyan Accent)</option>
                        <option value="breaking">Breaking News (Dark / Red Accent)</option>
                        <option value="minimal">Clean Minimal (Light Slate)</option>
                    </select>
                </div>
            </div>

            {{-- Verified MP4 Media List --}}
            @if(!empty($m6['mp4_files']))
                <div class="mt-3">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Verified Output Files:</p>
                    @foreach($m6['mp4_files'] as $file)
                        <div class="pvg-media-card">
                            <div class="flex items-center gap-2.5 truncate">
                                <span class="text-emerald-400 font-bold">🎬</span>
                                <span class="font-mono text-xs text-gray-200 truncate">{{ $file['name'] }}</span>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <span class="text-xs text-gray-400">{{ $file['size'] }}</span>
                                <x-filament::badge color="success">✓ FFprobe verified</x-filament::badge>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="pvg-card-footer">
                <div class="text-xs text-gray-400">
                    Output Directory: <code class="font-mono bg-gray-950 px-1 py-0.5 rounded text-gray-300">storage/app/video_out/</code>
                </div>

                <div class="flex items-center gap-2">
                    <x-filament::button
                        color="gray"
                        size="md"
                        icon="heroicon-m-check-badge"
                        wire:click="runPreflight"
                    >
                        Preflight Check
                    </x-filament::button>

                    <x-filament::button
                        color="success"
                        size="md"
                        icon="heroicon-m-film"
                        wire:click="runStep6"
                        :disabled="$s6Locked"
                    >
                        {{ $s6Done ? 'Re-render Final MP4' : 'Step 6: Render & Verify MP4 Now' }}
                    </x-filament::button>
                </div>
            </div>
        </div>

    </div>

    {{-- ═══════════════════════════════════════════════════════════════════
         COLLAPSIBLE TERMINAL LOG DRAWER
    ════════════════════════════════════════════════════════════════════ --}}
    @if(!empty($lastOutput))
        <div class="mt-2">
            <x-filament::section collapsible collapsed>
                <x-slot name="heading">
                    <div class="flex items-center gap-2 text-xs font-medium text-gray-300">
                        <span>Terminal Output / Diagnostics</span>
                    </div>
                </x-slot>
                <pre class="text-xs font-mono text-emerald-400 whitespace-pre-wrap max-h-72 overflow-y-auto bg-gray-950 rounded-lg p-3">{{ $lastOutput }}</pre>
            </x-filament::section>
        </div>
    @endif

</div>
</x-filament-panels::page>

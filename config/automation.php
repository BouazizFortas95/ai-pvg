<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Automation master switch
    |--------------------------------------------------------------------------
    |
    | All automation schedulers, jobs, provider calls, and mutations are
    | disabled by default. G-01 ships completely inert until explicitly
    | enabled in the environment.
    |
    */
    'enabled' => (bool) env('AUTOMATION_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Dry-run mode
    |--------------------------------------------------------------------------
    |
    | Dry-run defaults to on. It forces local providers, blocks public
    | publishing/uploading, and labels synthetic output as dry-run data.
    |
    */
    'dry_run' => (bool) env('AUTOMATION_DRY_RUN', true),

    'queue' => [
        'high' => env('AUTOMATION_QUEUE_HIGH', 'automation-high'),
        'media' => env('AUTOMATION_QUEUE_MEDIA', 'media'),
        'default' => env('AUTOMATION_QUEUE_DEFAULT', 'default'),
    ],

    'budget' => [
        'monthly_limit_micros' => (int) env('AUTOMATION_MONTHLY_BUDGET_MICROS', 5_000_000),
        'per_run_limit_micros' => (int) env('AUTOMATION_PER_RUN_BUDGET_MICROS', 250_000),
        'per_call_limit_micros' => (int) env('AUTOMATION_PER_CALL_BUDGET_MICROS', 50_000),
        'alert_at_percent' => (int) env('AUTOMATION_BUDGET_ALERT_PERCENT', 80),
    ],

    'providers' => [
        'default' => env('AUTOMATION_AI_PROVIDER', 'ollama'),
        'fallback' => env('AUTOMATION_AI_FALLBACK', 'ollama'),
        'tts' => env('AUTOMATION_TTS_PROVIDER', 'mock'),
    ],

    'approval' => [
        /*
         * Public publish requires two distinct administrators when enabled.
         * The requester may be automated (null) or a human user. A configured
         * system user may never be used as an approver for a human request.
         */
        'require_dual_public_publish' => (bool) env('AUTOMATION_DUAL_PUBLIC_PUBLISH', false),
        'ttl_hours' => (int) env('AUTOMATION_APPROVAL_TTL_HOURS', 72),
        'system_user_id' => env('AUTOMATION_SYSTEM_USER_ID') !== null
            ? (int) env('AUTOMATION_SYSTEM_USER_ID')
            : null,
    ],

    'circuit_breaker' => [
        'failure_threshold' => (int) env('AUTOMATION_BREAKER_FAILURE_THRESHOLD', 3),
        'cooldown_seconds' => (int) env('AUTOMATION_BREAKER_COOLDOWN_SECONDS', 900),
        'failure_window_seconds' => (int) env('AUTOMATION_BREAKER_FAILURE_WINDOW_SECONDS', 3600),
    ],

    'stages' => [
        'max_attempts' => (int) env('AUTOMATION_STAGE_MAX_ATTEMPTS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pipeline registry defaults
    |--------------------------------------------------------------------------
    |
    | G-01 intentionally declares no pillar-specific pipelines. G-02+ will
    | register their ordered stages here or via code.
    |
    */
    'pipelines' => [
        'script_agents.pipeline' => [
            'generate_variants',
            'compose_script',
            'fact_check_script',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Triage (Pillar 1)
    |--------------------------------------------------------------------------
    |
    | G-02 is opt-in. The config value is the default when no database
    | feature flag exists; a stored flag can override it. Both default to off.
    |
    */
    'triage' => [
        'enabled' => (bool) env('AUTOMATION_TRIAGE_ENABLED', false),
        'feature_flag' => env('AUTOMATION_TRIAGE_FEATURE_FLAG', 'triage.news_scoring_enabled'),
        'language' => env('AUTOMATION_TRIAGE_LANGUAGE', 'en'),
        'target_duration' => (int) env('AUTOMATION_TRIAGE_TARGET_DURATION', 45),
    ],

    'news_scoring' => [
        'auto_greenlight_min' => (int) env('AUTOMATION_AUTO_GREENLIGHT_MIN', 85),
        'review_band_min' => (int) env('AUTOMATION_REVIEW_BAND_MIN', 60),
        // Retained as a backwards-compatible alias for the original G-01 draft.
        'min_auto_greenlight' => (int) env('AUTOMATION_AUTO_GREENLIGHT_MIN', 85),
        'velocity_window_hours' => (int) env('AUTOMATION_VELOCITY_WINDOW_HOURS', 12),
        'velocity_target_sources' => (int) env('AUTOMATION_VELOCITY_TARGET_SOURCES', 5),
        'coverage_similarity_threshold' => (float) env('AUTOMATION_COVERAGE_SIMILARITY', 0.35),
        'recency_half_life_hours' => (int) env('AUTOMATION_RECENCY_HALF_LIFE_HOURS', 24),
        'novelty_window_days' => (int) env('AUTOMATION_NOVELTY_WINDOW_DAYS', 7),
        'cluster_similarity_threshold' => (float) env('AUTOMATION_CLUSTER_SIMILARITY', 0.82),
        'weights' => [
            'velocity' => 0.35,
            'keyword_heat' => 0.25,
            'novelty' => 0.20,
            'source_diversity' => 0.10,
            'recency' => 0.10,
        ],
        'keyword_weights' => [
            'breaking' => 20,
            'exclusive' => 18,
            'launch' => 15,
            'ai' => 10,
            'openai' => 12,
            'security' => 12,
            'breach' => 15,
            'lawsuit' => 12,
            'acquisition' => 15,
            'earnings' => 10,
            'election' => 12,
            'climate' => 10,
            'crisis' => 15,
            'record' => 10,
            'viral' => 12,
            'leak' => 12,
            'regulation' => 10,
            'funding' => 12,
            'chip' => 10,
            'model' => 8,
        ],
    ],

    'story_clustering' => [
        'window_hours' => (int) env('AUTOMATION_CLUSTER_WINDOW_HOURS', 48),
        'candidate_limit' => (int) env('AUTOMATION_CLUSTER_CANDIDATE_LIMIT', 200),
        'similarity_threshold' => (float) env('AUTOMATION_CLUSTER_SIMILARITY', 0.82),
        'min_sources' => (int) env('AUTOMATION_CLUSTER_MIN_SOURCES', 3),
        'max_sources' => (int) env('AUTOMATION_CLUSTER_MAX_SOURCES', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Script agents (Pillar 2)
    |--------------------------------------------------------------------------
    |
    | G-03 is opt-in and requires both the config switch and the database
    | feature flag. Agent calls are deterministic/local unless use_ai is
    | explicitly enabled; any external call is budget-guarded.
    |
    */
    'script_agents' => [
        'enabled' => (bool) env('AUTOMATION_SCRIPT_AGENTS_ENABLED', false),
        'feature_flag' => env('AUTOMATION_SCRIPT_AGENTS_FEATURE_FLAG', 'script_agents.enabled'),
        'use_ai' => (bool) env('AUTOMATION_SCRIPT_AGENTS_USE_AI', false),
        'language' => env('AUTOMATION_SCRIPT_AGENTS_LANGUAGE', 'en'),
        'target_duration' => (int) env('AUTOMATION_SCRIPT_AGENTS_TARGET_DURATION', 45),
        'duration_tolerance' => (int) env('AUTOMATION_SCRIPT_AGENTS_DURATION_TOLERANCE', 5),
        'hook_word_limit' => (int) env('AUTOMATION_SCRIPT_AGENTS_HOOK_WORD_LIMIT', 12),
        'max_scene_seconds' => (int) env('AUTOMATION_SCRIPT_AGENTS_MAX_SCENE_SECONDS', 12),
        'claim_coverage_threshold' => (float) env('AUTOMATION_SCRIPT_AGENTS_CLAIM_COVERAGE', 0.30),
        'evidence_max_chars' => (int) env('AUTOMATION_SCRIPT_AGENTS_EVIDENCE_CHARS', 300),
        'estimated_call_micros' => (int) env('AUTOMATION_SCRIPT_AGENTS_CALL_MICROS', 10_000),
        'max_tokens' => (int) env('AUTOMATION_SCRIPT_AGENTS_MAX_TOKENS', 1200),
        'temperature' => (float) env('AUTOMATION_SCRIPT_AGENTS_TEMPERATURE', 0.2),
        'model' => env('AUTOMATION_SCRIPT_AGENTS_MODEL'),
        'prompt_version' => env('AUTOMATION_SCRIPT_AGENTS_PROMPT_VERSION', 'g03-v1'),
    ],

    'render' => [
        'formats' => ['9:16', '16:9'],
        'max_parallel' => (int) env('AUTOMATION_RENDER_PARALLEL', 1),
    ],

    'publishing' => [
        'allow_public_without_dual_approval' => false,
        'default_visibility' => 'private',
    ],
];

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
    'pipelines' => [],

    'news_scoring' => [
        'min_auto_greenlight' => (int) env('AUTOMATION_AUTO_GREENLIGHT_MIN', 85),
        'velocity_window_hours' => (int) env('AUTOMATION_VELOCITY_WINDOW_HOURS', 12),
        'cluster_similarity_threshold' => (float) env('AUTOMATION_CLUSTER_SIMILARITY', 0.82),
        'weights' => [
            'velocity' => 0.35,
            'keyword_heat' => 0.25,
            'novelty' => 0.20,
            'source_diversity' => 0.10,
            'recency' => 0.10,
        ],
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

<?php

return [
    'notifications' => [
        // Keep external email delivery opt-in until local SMTP/provider settings are tested.
        'report_export_email_enabled' => (bool) env('METRIK_REPORT_EXPORT_EMAIL_ENABLED', false),
    ],

    'performance' => [
        'slow_query_logging_enabled' => (bool) env(
            'METRIK_SLOW_QUERY_LOGGING',
            env('APP_ENV', 'production') === 'local',
        ),
        'slow_query_threshold_ms' => max(1, (int) env('METRIK_SLOW_QUERY_THRESHOLD_MS', 250)),
        // Prevent accidental relationship queries in local development. Set METRIK_PREVENT_LAZY_LOADING=false to disable.
        'prevent_lazy_loading' => (bool) env(
            'METRIK_PREVENT_LAZY_LOADING',
            env('APP_ENV', 'production') === 'local',
        ),
        'report_cache_ttl_seconds' => max(1, (int) env('METRIK_REPORT_CACHE_TTL_SECONDS', 45)),
    ],
];

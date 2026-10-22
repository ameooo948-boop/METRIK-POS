<?php

return [
    // Adjust thresholds to the host's workload and available disk capacity.
    'disk_free_warning_mb' => max(1, (int) env('METRIK_DISK_FREE_WARNING_MB', 2048)),
    'disk_free_critical_mb' => max(1, (int) env('METRIK_DISK_FREE_CRITICAL_MB', 512)),
    'queue_max_pending' => max(1, (int) env('METRIK_QUEUE_MAX_PENDING', 500)),
    'queue_max_age_minutes' => max(1, (int) env('METRIK_QUEUE_MAX_AGE_MINUTES', 15)),
];

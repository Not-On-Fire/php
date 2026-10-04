<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Switch
    |--------------------------------------------------------------------------
    |
    | Turns error tracking and the analytics tag off everywhere. Nothing is
    | sent from the local and testing environments either way.
    |
    */

    'enabled' => (bool) env('NOTONFIRE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Error tracking
    |--------------------------------------------------------------------------
    |
    | The DSN from the application's settings in the NotOnFire dashboard. The
    | package configures the Sentry SDK itself, with tracing, profiling, PII,
    | logs, metrics, breadcrumbs and request bodies switched off, and that
    | configuration wins over config/sentry.php and SENTRY_* variables.
    |
    */

    'dsn' => env('NOTONFIRE_DSN') ?: env('SENTRY_LARAVEL_DSN') ?: env('SENTRY_DSN'),

    /*
    |--------------------------------------------------------------------------
    | Analytics
    |--------------------------------------------------------------------------
    |
    | With an ID set, the tag is added before </head> of every successful HTML
    | response in the web middleware group, except on the paths listed below.
    | Set NOTONFIRE_ANALYTICS_INJECT=false to place @notonfireAnalytics in
    | your layout yourself instead.
    |
    */

    'analytics' => [
        'id' => env('NOTONFIRE_ANALYTICS_ID'),
        'domains' => env('NOTONFIRE_ANALYTICS_DOMAINS'),
        'url' => env('NOTONFIRE_ANALYTICS_URL') ?: 'https://analytics.notonfire.systems',
        'inject' => (bool) env('NOTONFIRE_ANALYTICS_INJECT', true),
        'except' => [
            'admin', 'admin/*',
            'filament', 'filament/*',
            'horizon', 'horizon/*',
            'telescope', 'telescope/*',
            'pulse', 'pulse/*',
            'nova', 'nova/*',
        ],
    ],

];

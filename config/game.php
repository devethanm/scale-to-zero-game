<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Laravel Cloud API
    |--------------------------------------------------------------------------
    |
    | The API token is created in the Laravel Cloud dashboard under your
    | organization settings. It is used to poll the status of each target
    | environment. Status checks are disabled by default because the API
    | currently reports environments that scaled to zero as "running", so
    | the calls only add latency. Flip the flag once the API reports real
    | statuses.
    |
    */

    'cloud_status_enabled' => env('GAME_CLOUD_STATUS_ENABLED', false),

    'cloud_api_token' => env('LARAVEL_CLOUD_API_TOKEN'),

    'cloud_base_url' => env('LARAVEL_CLOUD_BASE_URL', 'https://cloud.laravel.com'),

    /*
    |--------------------------------------------------------------------------
    | Game Tuning
    |--------------------------------------------------------------------------
    |
    | Statuses are cached for a few seconds so the UI may poll every five
    | seconds without hammering the Cloud API. A round that never finishes
    | loading is voided after the timeout below.
    |
    */

    'status_cache_ttl' => 8,

    'round_timeout_ms' => 30000,

    'server_region' => env('GAME_SERVER_REGION', 'Sydney'),

    /*
    |--------------------------------------------------------------------------
    | Wake Cooldown
    |--------------------------------------------------------------------------
    |
    | The Cloud API does not reliably report scale-to-zero sleep, so the game
    | tracks wake-ups itself. A target only becomes playable again once this
    | many seconds have passed since it was last clicked, giving the platform
    | time to put it back to sleep.
    |
    */

    'wake_cooldown' => env('GAME_WAKE_COOLDOWN', 90),

    /*
    |--------------------------------------------------------------------------
    | Target Applications
    |--------------------------------------------------------------------------
    |
    | The scale-to-zero Laravel Cloud applications players race against. Each
    | target needs its public URL plus the application and environment IDs
    | from the Laravel Cloud API.
    |
    */

    'targets' => [
        [
            'name' => 'Sydney 1',
            'url' => 'https://scale-to-zero-target-1-production-lwhlwv.laravel-demo.cloud',
            'application_id' => 'app-a324493f-4cd6-422f-a96f-aaca6382d77c',
            'environment_id' => 'env-a324493f-6548-4ef8-ac70-001f12e2e037',
        ],
        [
            'name' => 'Sydney 2',
            'url' => 'https://scale-to-zero-target-2-production-v6dyta.laravel-demo.cloud',
            'application_id' => 'app-a3244a8f-8a67-4be4-b6c3-87f8fd124bdd',
            'environment_id' => 'env-a3244a92-30a6-4190-a00e-a1eead75b36e',
        ],
        [
            'name' => 'Sydney 3',
            'url' => 'https://scale-to-zero-target-3-production-cv987p.laravel-demo.cloud',
            'application_id' => 'app-a3244aa5-48f0-40cd-ab57-8cc151240068',
            'environment_id' => 'env-a3244aa7-c144-4657-8f75-07feb0677690',
        ],
        [
            'name' => 'Sydney 4',
            'url' => 'https://scale-to-zero-target-4-production-da8lup.laravel-demo.cloud',
            'application_id' => 'app-a3244ab4-833d-4d30-aba0-d05f025e4594',
            'environment_id' => 'env-a3244ab7-2baf-48da-a5b9-164536a683bc',
        ],
        [
            'name' => 'Sydney 5',
            'url' => 'https://scale-to-zero-target-5-production-reuzoo.laravel-demo.cloud',
            'application_id' => 'app-a3244ac1-b9b5-423b-8c51-d5219146a641',
            'environment_id' => 'env-a3244ac4-0ecb-4493-818a-cba9bce4c060',
        ],
    ],

];

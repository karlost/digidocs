<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Digidocs Configuration
    |--------------------------------------------------------------------------
    |
    | Zde můžete nakonfigurovat základní nastavení pro Digidocs package.
    |
    */

    // Základní nastavení pro package
    'enabled' => true,

    // Prefix pro databázové tabulky
    'table_prefix' => 'digidocs_',

    // Cache nastavení
    'cache' => [
        'enabled' => true,
        'ttl' => 3600, // 1 hodina
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Configuration
    |--------------------------------------------------------------------------
    |
    | Nastavení pro AI dokumentaci generování
    |
    */
    'ai' => [
        'provider' => 'openai',
        'api_key' => env('AUTODOCS_AI_KEY'),
        'model' => env('AUTODOCS_AI_MODEL', 'gpt-4'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Paths Configuration
    |--------------------------------------------------------------------------
    |
    | Cesty pro sledování souborů a ukládání dokumentace
    |
    */
    'paths' => [
        'watch' => ['app/', 'routes/'],
        'docs' => base_path('docs/code'),
        'memory' => storage_path('app/autodocs'),
    ],

    /*
    |--------------------------------------------------------------------------
    | File Processing
    |--------------------------------------------------------------------------
    |
    | Nastavení pro zpracování souborů
    |
    */
    'processing' => [
        'extensions' => ['php'],
        'exclude_dirs' => ['vendor', 'node_modules', 'storage', 'bootstrap/cache'],
        'exclude_files' => ['*.blade.php'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Watch Configuration
    |--------------------------------------------------------------------------
    |
    | Nastavení pro sledování změn a automatické generování dokumentace
    |
    */
    'watch' => [
        'enabled' => env('AUTODOCS_WATCH_ENABLED', true),
        'interval' => env('AUTODOCS_WATCH_INTERVAL', 5), // seconds
        'git_only' => env('AUTODOCS_WATCH_GIT_ONLY', false),
        'files_only' => env('AUTODOCS_WATCH_FILES_ONLY', false),
        'auto_commit_hook' => env('AUTODOCS_AUTO_COMMIT_HOOK', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Intelligent Analysis Configuration
    |--------------------------------------------------------------------------
    |
    | Nastavení pro inteligentní analýzu změn a rozhodování o regeneraci dokumentace
    |
    */
    'intelligent_analysis' => [
        'enabled' => env('AUTODOCS_INTELLIGENT_ANALYSIS', true),
        'fallback_to_classic' => env('AUTODOCS_FALLBACK_TO_CLASSIC', true),
        'track_documented_parts' => env('AUTODOCS_TRACK_DOCUMENTED_PARTS', true),
        'min_confidence_threshold' => env('AUTODOCS_MIN_CONFIDENCE', 0.7),
        'skip_private_changes' => env('AUTODOCS_SKIP_PRIVATE_CHANGES', true),
        'force_regenerate_on_public_api_changes' => env('AUTODOCS_FORCE_PUBLIC_API', true),
    ],
];

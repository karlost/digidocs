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
];

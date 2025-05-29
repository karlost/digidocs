<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Digidocs Configuration
    |--------------------------------------------------------------------------
    |
    | Simple configuration for DigiDocs AI documentation generator
    |
    */

    'enabled' => true,

    /*
    |--------------------------------------------------------------------------
    | AI Configuration
    |--------------------------------------------------------------------------
    */
    'ai' => [
        'api_key' => env('AUTODOCS_AI_KEY'),
        'model' => env('AUTODOCS_AI_MODEL', 'gpt-4'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Languages Configuration
    |--------------------------------------------------------------------------
    |
    | Configure languages for documentation generation using ISO format
    | Examples: cs-CZ, en-US, ja-JP, de-DE, pt-BR, zh-CN, etc.
    | AI will automatically recognize the ISO language code
    |
    */
    'languages' => [
        'enabled' => explode(',', env('DIGIDOCS_LANGUAGES', 'cs-CZ,en-US,pl-PL')),
        'default' => env('DIGIDOCS_DEFAULT_LANGUAGE', 'cs-CZ'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Paths Configuration
    |--------------------------------------------------------------------------
    */
    'paths' => [
        'watch' => ['app/', 'routes/'],
        'docs' => base_path('docs'),
        'user_docs' => base_path('docs/user'),
        'memory' => storage_path('app/autodocs'),
    ],

    /*
    |--------------------------------------------------------------------------
    | File Processing
    |--------------------------------------------------------------------------
    */
    'processing' => [
        'extensions' => ['php'],
        'exclude_dirs' => ['vendor', 'node_modules', 'storage', 'bootstrap/cache'],
        'exclude_files' => ['*.blade.php'],
    ],

    /*
    |--------------------------------------------------------------------------
    | RAG Configuration
    |--------------------------------------------------------------------------
    */
    'rag' => [
        'enabled' => true,
        
        'embeddings' => [
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'dimensions' => 1024,
        ],
        
        'vector_store' => [
            'type' => 'file',
            'path' => storage_path('app/autodocs/vectors'),
            'top_k' => 10,
        ],
        
        'chunking' => [
            'strategy' => 'semantic',
            'max_chunk_size' => 1000,
            'overlap' => 100,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Documentation Structure
    |--------------------------------------------------------------------------
    */
    'user_documentation' => [
        'enabled' => true,
        
        'structure' => [
            'main_sections' => [
                'getting-started',
                'features', 
                'guides',
                'troubleshooting',
                'reference'
            ],
            
            'auto_generate' => [
                'index' => true,
                'navigation' => true,
                'breadcrumbs' => true,
                'cross_references' => true,
                'glossary' => true,
            ],
        ],
        
        'formatting' => [
            'tone' => 'friendly',
            'technical_level' => 'beginner',
            'include_examples' => true,
            'include_screenshots' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Change Detection
    |--------------------------------------------------------------------------
    */
    'change_detection' => [
        'enabled' => true,
        'smart_analysis' => true,
        'min_change_threshold' => 0.1,
        'cache_ttl' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cost Tracking
    |--------------------------------------------------------------------------
    */
    'cost_tracking' => [
        'enabled' => true,
        'daily_limit' => 10.0, // USD
        'warn_threshold' => 0.8,
    ],
];
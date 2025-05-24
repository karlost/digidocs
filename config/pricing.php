<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Model Pricing Configuration
    |--------------------------------------------------------------------------
    |
    | Ceny modelů AI v USD za milion tokenů (MTok).
    | Aktualizováno: 2025-01-14
    |
    | Podporované providery v NeuronAI:
    | - OpenAI
    | - Anthropic
    | - Gemini (Google)
    | - Ollama (lokální modely - zdarma)
    | - Deepseek
    | - Mistral
    | - AzureOpenAI
    |
    */

    'providers' => [
        
        /*
        |--------------------------------------------------------------------------
        | OpenAI Models
        |--------------------------------------------------------------------------
        | Zdroj: https://openai.com/api/pricing/
        | Aktualizováno: 2025-01-14
        */
        'openai' => [
            // GPT-4.1 series (nejnovější)
            'gpt-4.1' => [
                'input' => 2.00,    // $2.00 / 1M tokens
                'output' => 8.00,   // $8.00 / 1M tokens
            ],
            'gpt-4.1-mini' => [
                'input' => 0.40,    // $0.40 / 1M tokens
                'output' => 1.60,   // $1.60 / 1M tokens
            ],
            'gpt-4.1-nano' => [
                'input' => 0.10,    // $0.10 / 1M tokens
                'output' => 0.40,   // $0.40 / 1M tokens
            ],
            'gpt-4.1-nano-2025-04-14' => [
                'input' => 0.10,    // $0.10 / 1M tokens
                'output' => 0.40,   // $0.40 / 1M tokens
            ],

            // GPT-4o series
            'gpt-4o' => [
                'input' => 5.00,    // $5.00 / 1M tokens
                'output' => 20.00,  // $20.00 / 1M tokens
            ],
            'gpt-4o-mini' => [
                'input' => 0.60,    // $0.60 / 1M tokens
                'output' => 2.40,   // $2.40 / 1M tokens
            ],

            // Legacy models
            'gpt-4' => [
                'input' => 30.00,   // $30.00 / 1M tokens
                'output' => 60.00,  // $60.00 / 1M tokens
            ],
            'gpt-4-turbo' => [
                'input' => 10.00,   // $10.00 / 1M tokens
                'output' => 30.00,  // $30.00 / 1M tokens
            ],
            'gpt-3.5-turbo' => [
                'input' => 1.50,    // $1.50 / 1M tokens
                'output' => 2.00,   // $2.00 / 1M tokens
            ],

            // Reasoning models
            'o3' => [
                'input' => 10.00,   // $10.00 / 1M tokens
                'output' => 40.00,  // $40.00 / 1M tokens
            ],
            'o4-mini' => [
                'input' => 1.10,    // $1.10 / 1M tokens
                'output' => 4.40,   // $4.40 / 1M tokens
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Anthropic Models
        |--------------------------------------------------------------------------
        | Zdroj: https://docs.anthropic.com/en/docs/about-claude/models/overview
        | Aktualizováno: 2025-01-14
        */
        'anthropic' => [
            // Claude 4 series (nejnovější)
            'claude-opus-4' => [
                'input' => 15.00,   // $15.00 / MTok
                'output' => 75.00,  // $75.00 / MTok
            ],
            'claude-opus-4-20250514' => [
                'input' => 15.00,   // $15.00 / MTok
                'output' => 75.00,  // $75.00 / MTok
            ],
            'claude-sonnet-4' => [
                'input' => 3.00,    // $3.00 / MTok
                'output' => 15.00,  // $15.00 / MTok
            ],
            'claude-sonnet-4-20250514' => [
                'input' => 3.00,    // $3.00 / MTok
                'output' => 15.00,  // $15.00 / MTok
            ],

            // Claude 3.7 series
            'claude-3-7-sonnet-20250219' => [
                'input' => 3.00,    // $3.00 / MTok
                'output' => 15.00,  // $15.00 / MTok
            ],

            // Claude 3.5 series
            'claude-3-5-sonnet-20241022' => [
                'input' => 3.00,    // $3.00 / MTok
                'output' => 15.00,  // $15.00 / MTok
            ],
            'claude-3-5-sonnet-20240620' => [
                'input' => 3.00,    // $3.00 / MTok
                'output' => 15.00,  // $15.00 / MTok
            ],
            'claude-3-5-haiku-20241022' => [
                'input' => 0.80,    // $0.80 / MTok
                'output' => 4.00,   // $4.00 / MTok
            ],

            // Claude 3 series (legacy)
            'claude-3-opus-20240229' => [
                'input' => 15.00,   // $15.00 / MTok
                'output' => 75.00,  // $75.00 / MTok
            ],
            'claude-3-sonnet-20240229' => [
                'input' => 3.00,    // $3.00 / MTok
                'output' => 15.00,  // $15.00 / MTok
            ],
            'claude-3-haiku-20240307' => [
                'input' => 0.25,    // $0.25 / MTok
                'output' => 1.25,   // $1.25 / MTok
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Google Gemini Models
        |--------------------------------------------------------------------------
        | Zdroj: https://ai.google.dev/gemini-api/docs/pricing
        | Aktualizováno: 2025-01-14
        */
        'gemini' => [
            'gemini-1.5-pro' => [
                'input' => 1.25,    // $1.25 / 1M tokens
                'output' => 5.00,   // $5.00 / 1M tokens
            ],
            'gemini-1.5-flash' => [
                'input' => 0.075,   // $0.075 / 1M tokens
                'output' => 0.30,   // $0.30 / 1M tokens
            ],
            'gemini-2.0-flash' => [
                'input' => 0.075,   // $0.075 / 1M tokens
                'output' => 0.30,   // $0.30 / 1M tokens
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Deepseek Models
        |--------------------------------------------------------------------------
        | Zdroj: https://platform.deepseek.com/api-docs/pricing
        | Aktualizováno: 2025-01-14
        */
        'deepseek' => [
            'deepseek-chat' => [
                'input' => 0.14,    // $0.14 / 1M tokens
                'output' => 0.28,   // $0.28 / 1M tokens
            ],
            'deepseek-coder' => [
                'input' => 0.14,    // $0.14 / 1M tokens
                'output' => 0.28,   // $0.28 / 1M tokens
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Mistral Models
        |--------------------------------------------------------------------------
        | Zdroj: https://mistral.ai/technology/#pricing
        | Aktualizováno: 2025-01-14
        */
        'mistral' => [
            'mistral-large' => [
                'input' => 2.00,    // $2.00 / 1M tokens
                'output' => 6.00,   // $6.00 / 1M tokens
            ],
            'mistral-medium' => [
                'input' => 2.70,    // $2.70 / 1M tokens
                'output' => 8.10,   // $8.10 / 1M tokens
            ],
            'mistral-small' => [
                'input' => 0.20,    // $0.20 / 1M tokens
                'output' => 0.60,   // $0.60 / 1M tokens
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Ollama Models (lokální)
        |--------------------------------------------------------------------------
        | Ollama modely běží lokálně, takže jsou zdarma
        */
        'ollama' => [
            'default' => [
                'input' => 0.00,    // Zdarma
                'output' => 0.00,   // Zdarma
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Azure OpenAI Models
        |--------------------------------------------------------------------------
        | Ceny jsou stejné jako OpenAI, ale mohou se lišit podle regionu
        */
        'azure' => [
            'gpt-4' => [
                'input' => 30.00,   // $30.00 / 1M tokens
                'output' => 60.00,  // $60.00 / 1M tokens
            ],
            'gpt-4o' => [
                'input' => 5.00,    // $5.00 / 1M tokens
                'output' => 20.00,  // $20.00 / 1M tokens
            ],
            'gpt-3.5-turbo' => [
                'input' => 1.50,    // $1.50 / 1M tokens
                'output' => 2.00,   // $2.00 / 1M tokens
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Fallback Pricing
    |--------------------------------------------------------------------------
    | Použije se pro neznámé modely
    */
    'default' => [
        'input' => 1.00,    // $1.00 / 1M tokens
        'output' => 2.00,   // $2.00 / 1M tokens
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing Metadata
    |--------------------------------------------------------------------------
    */
    'metadata' => [
        'last_updated' => '2025-01-14',
        'currency' => 'USD',
        'unit' => 'per 1M tokens',
        'sources' => [
            'openai' => 'https://openai.com/api/pricing/',
            'anthropic' => 'https://docs.anthropic.com/en/docs/about-claude/models/overview',
            'gemini' => 'https://ai.google.dev/gemini-api/docs/pricing',
            'deepseek' => 'https://platform.deepseek.com/api-docs/pricing',
            'mistral' => 'https://mistral.ai/technology/#pricing',
        ],
    ],
];

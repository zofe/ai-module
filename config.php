<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    | Supported: "anthropic", "openai", "ollama"
    */
    'provider' => env('AI_PROVIDER', 'anthropic'),

    'anthropic' => [
        'key'   => env('ANTHROPIC_API_KEY'),
        'model' => env('AI_MODEL', 'claude-haiku-4-5-20251001'),
    ],

    'openai' => [
        'key'      => env('AI_OPENAI_KEY', env('OPENAI_API_KEY')),
        'base_url' => env('AI_OPENAI_BASE_URL', env('OPENAI_BASE_URL', 'https://api.openai.com/v1')),
        'model'    => env('AI_MODEL', 'gpt-4o-mini'),
    ],

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        'model'    => env('AI_MODEL', 'llama3.2'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Widget
    |--------------------------------------------------------------------------
    */
    'widget' => [
        'enabled'      => env('AI_WIDGET_ENABLED', false),
        'mode'         => env('AI_WIDGET_MODE', 'operator'), // operator | customer
        'system_prompt' => env('AI_SYSTEM_PROMPT', null),   // null = auto-generated from rpd:context
        'max_tokens'   => 1024,
        'rate_limit'   => env('AI_RATE_LIMIT', 20),         // max requests per window
        'rate_window'  => env('AI_RATE_WINDOW', 3600),      // window in seconds (default: 1h)
    ],

];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Module: layout, menu and permissions (like every rapyd-admin module)
    |--------------------------------------------------------------------------
    | The AI readiness page (ai/status) shows how ready the application is for
    | AI-assisted development and what the AI runtime costs. `view ai status`
    | is created by AuthSeeder and given to admin and operator.
    */
    'layout' => 'layout::admin',
    'menu_admin' => 'ai::admin_menu',
    'menu_admin_position' => 90,

    'permissions' => ['view ai status'],
    'role_permissions' => [
        'operator' => ['view ai status'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    | Supported: "anthropic", "openai" (and any OpenAI-compatible API such as
    | DeepSeek, Groq, Mistral: set AI_OPENAI_BASE_URL), "ollama"
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
    | mode "operator": the assistant can call the AiTools registered by the
    | modules (data of the application). Tools are only offered to logged-in
    | users, and to the ones holding `tools_permission` when it is set.
    | mode "customer": text only, for a public support bot.
    */
    'widget' => [
        'enabled'       => env('AI_WIDGET_ENABLED', false),
        'mode'          => env('AI_WIDGET_MODE', 'operator'), // operator | customer
        'system_prompt' => env('AI_SYSTEM_PROMPT', null),     // null = auto-generated from rpd:context

        // Knowledge base appended to the system prompt: a markdown file (path
        // relative to the app root or absolute) or an http(s) URL (cached 1h).
        'knowledge'     => env('AI_KNOWLEDGE', null),
        'knowledge_max' => (int) env('AI_KNOWLEDGE_MAX', 16000),   // characters

        'tools_permission' => env('AI_TOOLS_PERMISSION', null),   // e.g. "ai.tools"; null = any logged-in user

        'max_tokens'    => (int) env('AI_MAX_TOKENS', 1024),  // reply length
        'max_input'     => (int) env('AI_MAX_INPUT', 500),    // characters per message
        'history'       => (int) env('AI_HISTORY', 8),        // messages sent to the model (last N)

        'rate_limit'    => (int) env('AI_RATE_LIMIT', 20),    // max requests per window, per session
        'rate_limit_ip' => (int) env('AI_RATE_LIMIT_IP', 60), // max requests per window, per IP address
        'rate_window'   => (int) env('AI_RATE_WINDOW', 3600), // window in seconds (default: 1h)
        'min_interval'  => (int) env('AI_MIN_INTERVAL', 2),   // seconds between two messages of a session

        'log_usage'     => env('AI_LOG_USAGE', true),         // one log line per request (tokens, cost)
    ],

    /*
    |--------------------------------------------------------------------------
    | Budget
    |--------------------------------------------------------------------------
    | Tokens of every request are counted (cache) and converted to USD with the
    | prices below (per million tokens). When the cost of the day reaches
    | `daily`, the widget answers "paused until tomorrow" instead of calling
    | the provider. 0 = unlimited. `php artisan ai:usage` shows the counters.
    | `store`: the cache store holding the counters (null = default cache);
    | pick another one (e.g. "file") so that `cache:clear` keeps them.
    */
    'budget' => [
        'daily'        => (float) env('AI_DAILY_BUDGET', 0),
        'store'        => env('AI_USAGE_STORE', null),
        'price_input'  => (float) env('AI_PRICE_INPUT', 0.30),
        'price_output' => (float) env('AI_PRICE_OUTPUT', 1.20),
    ],

];

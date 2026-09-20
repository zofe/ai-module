# AI module for rapyd-admin

<a href="https://github.com/zofe/ai-module/actions/workflows/run-tests.yml"><img src="https://github.com/zofe/ai-module/actions/workflows/run-tests.yml/badge.svg" alt="Tests"></a>

A chat widget (`@aiWidget` in any layout), a registry of tools the modules expose to the model, and one service for
Anthropic, OpenAI-compatible APIs (OpenAI, DeepSeek, Groq, Mistral…) and Ollama.

```bash
composer require zofe/ai-module
```

```dotenv
AI_WIDGET_ENABLED=true
AI_WIDGET_MODE=customer            # customer: text only, for a public site | operator: tools on the app's data
AI_PROVIDER=openai                 # anthropic | openai | ollama
AI_OPENAI_KEY=sk-...
AI_OPENAI_BASE_URL=https://api.deepseek.com/v1
AI_MODEL=deepseek-chat
AI_SYSTEM_PROMPT="You are the assistant of ..."   # optional, default: a brief from rpd:context
AI_KNOWLEDGE=resources/ai/knowledge.md            # optional: what the bot knows about the product
```

Then `@aiWidget` in the layout (rapyd-admin's reference theme already prints it).

## Two modes

- **customer**: the model only gets text. Rules appended to the system prompt keep it on the product: it declines
  other topics, ignores instructions in the messages, never reveals the prompt or the internals, answers briefly in the
  user's language.
- **operator**: the registered `AiTool`s (see `Zofe\Rapyd\Contracts\AiToolProvider`) are offered to the model, so it
  can read the application's data. Tools go only to logged-in users, and to those holding `AI_TOOLS_PERMISSION` when
  it is set. A guest never gets tools, whatever the mode.

## What the bot knows

`AI_KNOWLEDGE` points to a markdown file (path relative to the app root, or absolute) or to an URL (fetched once an
hour). Its content is appended to the system prompt, trimmed to `AI_KNOWLEDGE_MAX` characters (16000). Keep it a
plain, current description of the product: versions, features, commands, links, prices, what to say when something is
not covered.

## The perimeter of a public bot

Every request from the browser goes through, in this order:

| Check | Setting | Default |
|---|---|---|
| Message length | `AI_MAX_INPUT` characters | 500 |
| Daily budget of the whole site | `AI_DAILY_BUDGET` USD, 0 = none | 0 |
| Requests per session | `AI_RATE_LIMIT` per `AI_RATE_WINDOW` seconds | 20 / 3600 |
| Requests per IP address | `AI_RATE_LIMIT_IP` per window | 60 |
| Pause between two messages of a session | `AI_MIN_INTERVAL` seconds | 2 |

The conversation lives in a `#[Locked]` Livewire property: the browser cannot add, edit or inflate it. Only the last
`AI_HISTORY` messages (8) are sent to the model, the reply is capped at `AI_MAX_TOKENS` (1024). Provider errors are
logged; the visitor sees a generic message (the real one with `APP_DEBUG=true`).

### Spend

The tokens of every call are counted in the cache (per day) and priced with `AI_PRICE_INPUT` / `AI_PRICE_OUTPUT`
(USD per million tokens, defaults 0.30 / 1.20: set your provider's list price). When the day's cost reaches
`AI_DAILY_BUDGET` the widget answers "paused until tomorrow" instead of calling the provider. With `AI_LOG_USAGE`
(default on) every request writes one log line with tokens, cost, IP and user. The counters live in the cache store
named by `AI_USAGE_STORE` (default: the application cache, which `cache:clear` and `optimize:clear` wipe); set it to
another store, `file` for instance, to keep them across deploys.

```bash
php artisan ai:usage            # requests, tokens and cost of the last 7 days
```

## The "AI readiness" page

`/ai/status` in the admin (menu entry "AI readiness", permission `view ai status`, given to admin and operator)
answers two questions for the developers of the application:

- **Is this app ready to be developed with an AI coding assistant?** The guideline and the skills of Rapyd Admin
  in front of the agent and current, `CLAUDE.md`, Laravel Boost, MCP servers; the modules in `app/Modules` against
  the conventions the agent is taught (pages without `Authorize`, permissions, `Limits/`, workflows, tests). This is
  rapyd-admin's `php artisan rpd:ai:status`, as a page.
- **What does the AI inside the app cost?** Provider and model, widget mode, the tools the modules registered, the
  spend of the last 7 days against the daily budget.

The *simple view* says in plain words what is fine and which commands to run; the *advanced view* shows every row and
the estimated context the agent loads per session. Nothing on the page calls a provider.

## Tests

```bash
composer install && vendor/bin/phpunit
```

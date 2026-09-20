# Changelog

## [1.1.0] - 2026-09-20

- "AI readiness" page (`ai/status`, permission `view ai status` for admin and operator, entry in the admin menu): is
  the application ready for AI-assisted development? The coding assistant tooling and the app modules from
  rapyd-admin's `rpd:ai:status`, plus the AI runtime of the application: provider, widget, registered tools, spend of
  the last 7 days against the budget. A simple view in plain words with the commands to run, an advanced view with
  every row and the estimated context per session.
- The module is a rapyd-admin module package (`RapydModuleServiceProvider`): `config.php` declares layout, menu and
  permissions; requires rapyd-admin ^9.15.
- `AiUsage::lastDays()`; counters kept 8 days instead of 2.

## [1.0.1] - 2026-09-12

- `AI_USAGE_STORE`: the cache store of the daily counters (default: the application cache). `optimize:clear` on deploy was resetting the day's spend.

## [1.0.0] - 2026-09-12

First tagged release.

- Chat widget (`@aiWidget`), `AiRegistry` of tools exposed by the modules, `AiService` for Anthropic, OpenAI-compatible APIs and Ollama.
- Perimeter for a public bot: daily budget in USD (`AI_DAILY_BUDGET`, `php artisan ai:usage`), rate limits per session and per IP, minimum interval, input length, history window, `AI_MAX_TOKENS`.
- Conversation in a `#[Locked]` property; tools only for logged-in users (`AI_TOOLS_PERMISSION`).
- `AI_KNOWLEDGE`: markdown file or URL appended to the system prompt; customer-mode rules.
- Provider errors logged, generic message to the visitor; one usage log line per request.
- Testbench suite and GitHub Actions (PHP 8.2/8.3, Laravel 12/13).

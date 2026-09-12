# Changelog

## [1.0.0] - 2026-09-12

First tagged release.

- Chat widget (`@aiWidget`), `AiRegistry` of tools exposed by the modules, `AiService` for Anthropic, OpenAI-compatible APIs and Ollama.
- Perimeter for a public bot: daily budget in USD (`AI_DAILY_BUDGET`, `php artisan ai:usage`), rate limits per session and per IP, minimum interval, input length, history window, `AI_MAX_TOKENS`.
- Conversation in a `#[Locked]` property; tools only for logged-in users (`AI_TOOLS_PERMISSION`).
- `AI_KNOWLEDGE`: markdown file or URL appended to the system prompt; customer-mode rules.
- Provider errors logged, generic message to the visitor; one usage log line per request.
- Testbench suite and GitHub Actions (PHP 8.2/8.3, Laravel 12/13).

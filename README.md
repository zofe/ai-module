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
AI_WIDGET_TITLE="Ticket assistant"                # optional: what the panel calls itself
AI_WIDGET_INTRO="Ask about the tickets: how many, of what kind, in which month."
AI_WIDGET_EXAMPLES="How many tickets in September?|Which problems are growing?"
AI_WIDGET_TOOLS=tickets_*                         # optional: the tools the assistant may use
AI_WIDGET_REMEMBER=false                          # optional: do not keep the chat across pages
```

Then `@aiWidget` in the layout (rapyd-admin's reference theme already prints it).

## Two modes

- **customer**: the model only gets text. Rules appended to the system prompt keep it on the product: it declines
  other topics, ignores instructions in the messages, never reveals the prompt or the internals, answers briefly in the
  user's language.
- **operator**: the registered `AiTool`s (see `Zofe\Rapyd\Contracts\AiToolProvider`) are offered to the model, so it
  can read the application's data. Tools go only to logged-in users, and to those holding `AI_TOOLS_PERMISSION` when
  it is set. A guest never gets tools, whatever the mode.

## What the panel says about itself

The defaults describe the module, not your application: in operator mode the empty panel offers "your application data,
logs, users, or anything else", which is wrong the moment the assistant is there for one subject. `ai.widget.title` and
`ai.widget.intro` replace both (`null` = the wording of the module, translated into the language of the page).

`ai.widget.examples` is the part that earns its keep: two or three questions shown as buttons in the empty panel. They
teach what can be asked here better than any description, and a click sends the question as if it had been typed. The
index is resolved against the config server-side, so the panel asks what you wrote and nothing else.

```php
'title'    => 'Ticket assistant',
'intro'    => 'Ask about the tickets: how many, of what kind, in which month.',
'examples' => ['Which problems grew in the last three months?', 'How many tickets about connectivity in September?'],
```

## The perimeter of the tools

`AiRegistry` collects the tools of **every** installed module, and some arrive without being asked for: rapyd-admin's
Log module registers `get_recent_errors` and `get_error_summary`, so a chat widget meant for sales data can read the
application log. `ai.widget.tools` is the allow-list that decides what the assistant is offered, names with `*`
wildcards, empty = everything:

```dotenv
AI_WIDGET_TOOLS=tickets_*,category_*
```

Tools left out stay registered — other code can still call them — but they are neither sent to the provider nor
executed if the model names one anyway. "Develop with AI" shows both lists, so the perimeter is visible.

## A system prompt that depends on the data

`ai.widget.system_prompt` takes a string, but a prompt that has to say what today's date is, or which categories exist,
cannot be a constant in `.env`. Give it the name of an invokable class instead: it is resolved from the container and
called at every request, so it is never frozen in the config cache.

```php
// config/ai.php
'system_prompt' => \App\Modules\TicketReport\Ai\AssistantPrompt::class,
```

```php
class AssistantPrompt
{
    public function __invoke(): string
    {
        return "You are the assistant of ... Today is " . now()->isoFormat('D MMMM YYYY') . " ...";
    }
}
```

The widget and any other caller of `AiService` then share one assistant: same voice, same rules, same perimeter.

## The conversation between two pages

The panel is re-created at every page load, so by default a chat used to end the moment the user went to look
something up. It now waits in the user's session (`ai.widget.remember`, on): reopening the panel on another page
finds it again, "clear" ends it for good, and saving it leaves the panel empty. Turn it off
(`AI_WIDGET_REMEMBER=false`) where the session lives in a cookie (4 KB) or where a chat must not outlive the page.

## Keeping a conversation

A chat in the panel is thrown away when the page changes. `ai.widget.on_save` is how an application offers to keep
one: the name of an invokable class that receives the messages and returns the URL to send the user to (`null` to
stay where they are). A bookmark appears in the header of the panel as soon as there is an answer worth keeping.

```php
'on_save'    => \App\Modules\TicketReport\Ai\SaveConversationAsReport::class,
'save_label' => 'Save as report',
```

```php
class SaveConversationAsReport
{
    /** @param list<array{role: string, content: string, in?: int, out?: int, cost?: float}> $messages */
    public function __invoke(array $messages): ?string
    {
        $report = ...;   // whatever "keeping it" means in your application

        return route('reports.show', $report);
    }
}
```

Each answer carries what it cost (`in`, `out`, `cost`), so what is saved keeps its price. The messages travel as
JSON, so a whole cost arrives as an int: cast it. Nothing here calls the provider.

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

## The "Develop with AI" page

`/ai/develop` in the admin (menu entry "Develop with AI", permission `develop with ai`, given to admin and operator)
shows the developers of the application what Rapyd Admin gives their coding assistant:

- **What your agent can do here**: knows Rapyd Admin (guideline), builds modules and designs workflows (skills),
  knows Laravel and Livewire (Boost), reads the schema, the logs and the routes (MCP). Every capability that is
  missing names the command that unlocks it. Then the generators the agent calls instead of writing the code, and
  the estimated context it loads per session.
- **Built in this project**: every module in `app/Modules`, generated by `rpd:make` (when, how many files, how many
  tokens the model did not have to write) or by hand, and whether it follows the conventions: authorized pages,
  permissions, `Limits/`, tests, workflows.
- **Try it**: prompts to copy into the agent, each saying which packages it needs.
- **AI in the app**: provider and model, widget mode, the tools the assistant is offered and the ones an allow-list
  holds back, the spend of the last 7 days against the daily budget.

This is rapyd-admin's `php artisan rpd:ai:develop`, as a page. Nothing on it calls a provider.

## Tests

```bash
composer install && vendor/bin/phpunit
```

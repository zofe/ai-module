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

`AI_KNOWLEDGE` points to a markdown file (path relative to the app root, or absolute), to an URL (fetched once an
hour), to a **directory** — every `*.md` in it, in name order (`10-product.md`, `20-cases.md`…), files starting with
`_` or `.` left out, so a `_README.md` can explain the conventions to whoever edits them — or, in `config/ai.php`, to
an array of those. The pieces are joined with a rule and appended to the system prompt, trimmed to `AI_KNOWLEDGE_MAX`
characters (16000) with a warning in the log when that happens. Point it at a directory and a new document is one
more file, nothing else. Keep them plain, current descriptions: versions, features, commands, what to say when
something is not covered. "AI overview" lists the files the assistant reads.

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
php artisan ai:usage            # per day, and per month and context from the ledger
```

### The ledger

The counters answer "how much today". The ledger answers "how much this month, since the start, and for what": one
row in `ai_usage` per call to the provider, with the **context** the caller declared, the tokens, the cost at the
prices configured at that moment (a price change later does not rewrite history) and who asked. It ships as a
migration of the module (`php artisan migrate`), it is on by default (`AI_LEDGER=false` to opt out), and an
application that has not migrated loses nothing: the row is skipped with a warning, the counters keep counting.

The context is one word chosen by whoever calls the model, so the spend can be read by purpose:

```php
$ai->chat($messages, withTools: false, context: 'classify');   // the widget writes "widget"
```

```php
$usage = app(\Zofe\Ai\Services\AiUsage::class);
$usage->month();                       // ['month' => '2026-09', 'requests' => 412, 'input' => ..., 'output' => ..., 'cost' => 1.83]
$usage->month('classify');             // the same, for one context
$usage->months(12);                    // the last twelve, current first
$usage->allTime();                     // ['since' => '2026-09-22', ...]
$usage->byContext(now()->startOfMonth());   // [['context' => 'classify', 'cost' => 1.41, ...], ...], most expensive first
$usage->lastDays(7);                   // from the ledger when it is on: a cache:clear does not blank the page
```

"Develop with AI" shows the month, the whole life of the application and the split by context; `ai:usage` prints
the same in the console.

## The AI page

`/ai/develop` in the admin (menu entry "AI", permission `develop with ai`, given to admin and operator) has two sides.

**AI in this application**, for the people who use it:

- **What the AI did**: the activities the modules declare, in the words of the domain — "Tickets classified",
  8 845, "from 26 categories the AI proposed" — each with the cost of its ledger context next to it. A module
  implements rapyd-admin's `AiActivityProvider` and registers it, guarded like the tools:

  ```php
  use Zofe\Rapyd\Contracts\{AiActivity, AiActivityProvider};

  class TicketReportActivities implements AiActivityProvider
  {
      public function activities(): array
      {
          return [
              new AiActivity('tickets_classified', 'Tickets classified', TicketClassification::count(),
                  detail: 'from the categories the AI proposed', context: 'classify', lastAt: $lastRun?->finished_at),
          ];
      }
  }

  if (class_exists(\Zofe\Ai\AiRegistry::class)) {
      \Zofe\Ai\AiRegistry::registerActivities(new TicketReportActivities());
  }
  ```
- **What it costs**: today against the daily budget, this month, since the start, by context — from the ledger.
- **What the assistant may read**: the tools it is offered, with the words the modules gave them, and the ones
  `ai.widget.tools` holds back.

**Develop with AI**, for the ones who develop it: what the coding agent can do here and the command that unlocks
each capability, the generators it calls instead of writing the code, the context it loads per session, the
modules in `app/Modules` (generated by `rpd:make` or by hand, and whether they follow the conventions). This is
rapyd-admin's `php artisan rpd:ai:develop`, as a page. `AI_DEVELOP_PAGE=false` hides this side where the
administrators are not the developers.

Nothing on the page calls a provider.

## Tests

```bash
composer install && vendor/bin/phpunit
```

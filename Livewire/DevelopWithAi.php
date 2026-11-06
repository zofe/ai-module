<?php

namespace Zofe\Ai\Livewire;

use App\Modules\Auth\Traits\Authorize;
use Livewire\Component;
use Zofe\Ai\AiRegistry;
use Zofe\Ai\Services\AiKnowledge;
use Zofe\Ai\Services\AiRedactor;
use Zofe\Ai\Services\AiService;
use Zofe\Ai\Services\AiUsage;
use Zofe\Rapyd\Ai\AiDevelopment;

/**
 * The AI page, two sides. "AI in this application", for the people who use it: what the AI
 * did (the activities the modules declare), what it costs (the ledger), what the assistant
 * may read. "Develop with AI", for the ones who develop it: what the coding agent can do
 * here, what was built and whether it follows the conventions, the boilerplate rpd:make
 * wrote instead of the model. Nothing here calls a provider.
 */
class DevelopWithAi extends Component
{
    use Authorize;

    public array $report = [];

    public array $runtime = [];

    public array $activities = [];

    public function booted(): void
    {
        $this->authorize('admin|develop with ai');
    }

    public function mount(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        $this->report = $this->develop() ? app(AiDevelopment::class)->report() : [];
        $this->runtime = $this->runtime();
        $this->activities = $this->activities();
    }

    /** The developer side is shown unless the application turned it off (`ai.develop`). */
    public function develop(): bool
    {
        return (bool) config('ai.develop', true);
    }

    /**
     * What the AI did here, as the modules declare it, with the cost of each activity next
     * to it when the module said which ledger context it belongs to.
     *
     * @return list<array{key: string, label: string, count: int, detail: ?string, context: ?string, last_at: ?string, cost: ?float}>
     */
    protected function activities(): array
    {
        $usage = app(AiUsage::class);
        $costs = $usage->ledgerOn() ? collect($usage->byContext())->keyBy('context') : collect();

        return array_map(function ($activity) use ($costs) {
            $row = $activity->toArray();
            $row['cost'] = $activity->context && $costs->has($activity->context) ? $costs[$activity->context]['cost'] : null;

            return $row;
        }, AiRegistry::activities());
    }

    /** What the AI inside the application is and costs: provider, widget, tools, spend. */
    protected function runtime(): array
    {
        $usage = app(AiUsage::class);
        $provider = config('ai.provider', 'anthropic');
        $baseUrl = $provider === 'openai' ? config('ai.openai.base_url') : ($provider === 'ollama' ? config('ai.ollama.base_url') : null);
        $key = match ($provider) {
            'anthropic' => config('ai.anthropic.key'),
            'openai' => config('ai.openai.key'),
            default => 'local',
        };

        $registered = array_map(fn ($tool) => $tool->name, AiRegistry::tools());
        $offered = app(AiService::class)->tools();

        return [
            'provider' => $provider,
            'model' => config("ai.{$provider}.model"),
            'base_url' => $baseUrl,
            'configured' => (bool) $key,
            'widget' => (bool) config('ai.widget.enabled'),
            'mode' => config('ai.widget.mode', 'operator'),
            'knowledge' => config('ai.widget.knowledge'),
            // The documents the assistant reads, in order, rendered so that the people who
            // use the application can read what it knows — and see what to correct.
            'knowledge_docs' => array_map(fn ($doc) => $doc + ['html' => $this->markdown($doc['text'])], app(AiKnowledge::class)->documents()),
            'knowledge_max' => (int) config('ai.widget.knowledge_max', 16000),
            // What the assistant may read, with the words the modules gave each tool.
            'tools' => array_map(fn ($tool) => ['name' => $tool->name, 'description' => $tool->description], $offered),
            // Registered by a module but kept out by `ai.widget.tools`: the perimeter, visible.
            'tools_withheld' => array_values(array_diff($registered, array_map(fn ($tool) => $tool->name, $offered))),
            'today' => $usage->today(),
            'days' => $usage->lastDays(),
            // The ledger: the month, the whole life of the application and what the money went to.
            'ledger' => $usage->ledgerOn(),
            'month' => $usage->ledgerOn() ? $usage->month() : null,
            'all_time' => $usage->ledgerOn() ? $usage->allTime() : null,
            'contexts' => $usage->ledgerOn() ? $usage->byContext(now()->startOfMonth()) : [],
            'budget' => $usage->budget(),
            'prices' => [config('ai.budget.price_input'), config('ai.budget.price_output')],
            'redact' => $this->redaction($provider),
        ];
    }

    /**
     * The diagrams of docs/ (how the data stay inside, how context and tools work), in the language
     * of the page when there is a translation, as data URIs: the package has no public assets.
     *
     * @return list<array{title: string, text: string, src: string}>
     */
    protected function diagrams(): array
    {
        $dir = dirname(__DIR__) . '/docs';
        $it = str_starts_with(app()->getLocale(), 'it');
        $out = [];
        foreach ([
            'context-and-tools' => [__('It decides what the AI may read'), __('Only the context and the tools the developers wrote: every tool reads its own window of the data, and the rest of the database does not exist for the AI.')],
            'data-protection' => [__('It keeps the personal data inside'), __('Before calling the AI it masks names, addresses and credentials, with Presidio for the names in free text, and puts the real values back in the answer.')],
        ] as $name => [$title, $text]) {
            $file = $it && is_file("{$dir}/{$name}.it.svg") ? "{$dir}/{$name}.it.svg" : "{$dir}/{$name}.svg";
            if (is_file($file)) {
                $out[] = ['title' => $title, 'text' => $text, 'src' => 'data:image/svg+xml;base64,' . base64_encode($this->withoutTitle((string) file_get_contents($file)))];
            }
        }

        return $out;
    }

    /**
     * The diagram without its own title and subtitle (the band above y=92): on the page the
     * heading and the explanation above it say the same. The files in docs/ keep it, for whoever
     * reads them alone.
     */
    protected function withoutTitle(string $svg): string
    {
        return (string) preg_replace_callback('/viewBox="(\S+) (\S+) (\S+) (\S+)" width="\S+" height="\S+"/', function ($m) {
            $cut = 92 - (float) $m[2];
            $height = (float) $m[4] - $cut;

            return sprintf('viewBox="%s 92 %s %s" width="%s" height="%s"', $m[1], $m[3], $height, $m[3], $height);
        }, $svg, 1);
    }

    /** How the personal data is kept from the provider (ai.redact): null when the filter is off for it. */
    protected function redaction(string $provider): ?array
    {
        if (! AiRedactor::enabledFor($provider)) {
            return null;
        }

        $terms = null;
        if ($source = config('ai.redact.terms')) {
            try {
                $terms = count((array) app($source)());
            } catch (\Throwable) {
                $terms = null;
            }
        }
        $presidio = config('ai.redact.presidio.url');

        return [
            'terms' => $terms,
            'terms_description' => config('ai.redact.terms_description'),
            'patterns' => array_keys((array) (config('ai.redact.patterns') ?? AiRedactor::DEFAULT_PATTERNS)),
            'presidio' => $presidio ? parse_url($presidio, PHP_URL_HOST) : null,
            'entities' => config('ai.redact.presidio.entities'),
            'block' => config('ai.redact.on_error', 'block') === 'block',
            'keep' => array_values((array) config('ai.redact.keep', [])),
        ];
    }

    /** Markdown to HTML when league/commonmark is there (Laravel ships it), the text otherwise. */
    protected function markdown(string $text): string
    {
        if (class_exists(\League\CommonMark\GithubFlavoredMarkdownConverter::class)) {
            return (string) \Illuminate\Support\Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
        }

        return '<pre class="small mb-0" style="white-space: pre-wrap">' . e($text) . '</pre>';
    }

    /** The commands that unlock a capability, listed once. */
    public function unlocks(): array
    {
        return collect($this->report['capabilities'] ?? [])->where('status', '!=', 'ok')->pluck('fix')->unique()->values()->all();
    }

    public function render()
    {
        return view('ai::develop_with_ai', ['unlocks' => $this->unlocks(), 'develop' => $this->develop(), 'diagrams' => $this->diagrams()])
            ->layout(config('ai.layout', 'layout::admin'));
    }
}

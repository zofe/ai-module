<?php

namespace Zofe\Ai\Livewire;

use App\Modules\Auth\Traits\Authorize;
use Livewire\Component;
use Zofe\Ai\AiRegistry;
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
        ];
    }

    /** The commands that unlock a capability, listed once. */
    public function unlocks(): array
    {
        return collect($this->report['capabilities'] ?? [])->where('status', '!=', 'ok')->pluck('fix')->unique()->values()->all();
    }

    public function render()
    {
        return view('ai::develop_with_ai', ['unlocks' => $this->unlocks(), 'develop' => $this->develop()])
            ->layout(config('ai.layout', 'layout::admin'));
    }
}

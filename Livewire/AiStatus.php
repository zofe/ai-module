<?php

namespace Zofe\Ai\Livewire;

use App\Modules\Auth\Traits\Authorize;
use Livewire\Component;
use Zofe\Ai\AiRegistry;
use Zofe\Ai\Services\AiUsage;
use Zofe\Rapyd\Ai\AiReadiness;

/**
 * AI readiness: is this application ready for AI-assisted development (the tooling
 * of the coding agent, the modules against the conventions) and what the AI runtime
 * of the application (widget, tools, provider) costs.
 *
 * Two views: "simple" says in plain words what is fine and what to do; "advanced"
 * shows every row, the context estimate and the last days of spend.
 */
class AiStatus extends Component
{
    use Authorize;

    public bool $advanced = false;

    public array $report = [];

    public array $runtime = [];

    public function booted(): void
    {
        $this->authorize('admin|view ai status');
    }

    public function mount(): void
    {
        $this->advanced = (bool) session('ai.status.advanced', false);
        $this->refresh();
    }

    public function toggle(): void
    {
        $this->advanced = ! $this->advanced;
        session(['ai.status.advanced' => $this->advanced]);
    }

    public function refresh(): void
    {
        $this->report = app(AiReadiness::class)->report();
        $this->runtime = $this->runtime();
    }

    /** What the AI inside the application is and costs: provider, widget, tools, spend. */
    protected function runtime(): array
    {
        $usage = app(AiUsage::class);
        $provider = config('ai.provider', 'anthropic');
        $model = config("ai.{$provider}.model");
        $baseUrl = $provider === 'openai' ? config('ai.openai.base_url') : ($provider === 'ollama' ? config('ai.ollama.base_url') : null);
        $key = match ($provider) {
            'anthropic' => config('ai.anthropic.key'),
            'openai' => config('ai.openai.key'),
            default => 'local',
        };
        $knowledge = config('ai.widget.knowledge');

        return [
            'provider' => $provider,
            'model' => $model,
            'base_url' => $baseUrl,
            'configured' => (bool) $key,
            'widget' => (bool) config('ai.widget.enabled'),
            'mode' => config('ai.widget.mode', 'operator'),
            'knowledge' => $knowledge,
            'tools' => array_map(fn ($tool) => $tool['name'] ?? '?', AiRegistry::definitions()),
            'today' => $usage->today(),
            'days' => $usage->lastDays(),
            'budget' => $usage->budget(),
            'prices' => [config('ai.budget.price_input'), config('ai.budget.price_output')],
        ];
    }

    /** The fixes still to run, for the simple view. */
    public function fixes(): array
    {
        return collect($this->report['tooling'])->where('status', '!=', 'ok')->pluck('fix')->unique()->values()->all();
    }

    public function render()
    {
        return view('ai::ai_status', ['fixes' => $this->fixes()])->layout(config('ai.layout', 'layout::admin'));
    }
}

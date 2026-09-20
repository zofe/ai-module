<?php

namespace Zofe\Ai\Livewire;

use App\Modules\Auth\Traits\Authorize;
use Livewire\Component;
use Zofe\Ai\AiRegistry;
use Zofe\Ai\Services\AiUsage;
use Zofe\Rapyd\Ai\AiDevelopment;

/**
 * Develop with AI: what the coding agent can do in this application, what was built and
 * whether it follows the conventions, the boilerplate rpd:make wrote instead of the
 * model, the prompts to try; and what the AI inside the application (widget, tools,
 * provider) is and costs. Nothing here calls a provider.
 */
class DevelopWithAi extends Component
{
    use Authorize;

    public array $report = [];

    public array $runtime = [];

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
        $this->report = app(AiDevelopment::class)->report();
        $this->runtime = $this->runtime();
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

        return [
            'provider' => $provider,
            'model' => config("ai.{$provider}.model"),
            'base_url' => $baseUrl,
            'configured' => (bool) $key,
            'widget' => (bool) config('ai.widget.enabled'),
            'mode' => config('ai.widget.mode', 'operator'),
            'knowledge' => config('ai.widget.knowledge'),
            'tools' => array_map(fn ($tool) => $tool['name'] ?? '?', AiRegistry::definitions()),
            'today' => $usage->today(),
            'days' => $usage->lastDays(),
            'budget' => $usage->budget(),
            'prices' => [config('ai.budget.price_input'), config('ai.budget.price_output')],
        ];
    }

    /** The commands that unlock a capability, listed once. */
    public function unlocks(): array
    {
        return collect($this->report['capabilities'])->where('status', '!=', 'ok')->pluck('fix')->unique()->values()->all();
    }

    public function render()
    {
        return view('ai::develop_with_ai', ['unlocks' => $this->unlocks()])->layout(config('ai.layout', 'layout::admin'));
    }
}

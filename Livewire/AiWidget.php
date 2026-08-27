<?php

namespace Zofe\Ai\Livewire;

use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Zofe\Ai\Services\AiService;

class AiWidget extends Component
{
    public string $input = '';
    public bool $loading = false;
    public array $messages = [];

    #[Locked]
    public string $mode;

    public function mount(): void
    {
        $this->mode = config('ai.widget.mode', 'operator');
    }

    public function send(): void
    {
        $text = trim($this->input);
        if (!$text || $this->loading) {
            return;
        }

        // Rate limiting per session (funziona anche con utenti condivisi come nella demo)
        $limit    = (int) config('ai.widget.rate_limit', 20);
        $window   = (int) config('ai.widget.rate_window', 3600);
        $rateKey  = 'ai-widget:' . session()->getId();

        if (RateLimiter::tooManyAttempts($rateKey, $limit)) {
            $seconds = RateLimiter::availableIn($rateKey);
            $minutes = (int) ceil($seconds / 60);
            $this->messages[] = ['role' => 'user', 'content' => $text];
            $this->messages[] = [
                'role'    => 'assistant',
                'content' => "Rate limit reached ({$limit} requests / " . ($window >= 3600 ? ($window / 3600) . 'h' : ($window / 60) . 'min') . "). Try again in {$minutes} minute" . ($minutes !== 1 ? 's' : '') . '.',
                'error'   => true,
            ];
            $this->input = '';
            return;
        }

        RateLimiter::hit($rateKey, $window);

        $this->messages[] = ['role' => 'user', 'content' => $text];
        $this->input      = '';
        $this->loading    = true;

        try {
            $reply = app(AiService::class)->chat(
                messages: $this->messages,
                withTools: $this->mode === 'operator',
            );

            $this->messages[] = ['role' => 'assistant', 'content' => $reply];
        } catch (\Throwable $e) {
            $this->messages[] = [
                'role'    => 'assistant',
                'content' => 'Error: ' . $e->getMessage(),
                'error'   => true,
            ];
        }

        $this->loading = false;
    }

    public function clear(): void
    {
        $this->messages = [];
    }

    public function render()
    {
        return view('ai::ai_widget');
    }
}

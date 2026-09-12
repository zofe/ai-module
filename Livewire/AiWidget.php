<?php

namespace Zofe\Ai\Livewire;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Zofe\Ai\Services\AiService;
use Zofe\Ai\Services\AiUsage;

class AiWidget extends Component
{
    /** Messages kept in the panel (the browser cannot alter them). */
    public const KEEP = 40;

    public string $input = '';
    public bool $loading = false;

    #[Locked]
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
        if ($text === '' || $this->loading) {
            return;
        }

        if ($refusal = $this->refusal($text)) {
            $this->reply($text, $refusal, true);
            return;
        }

        $this->hit();

        $this->messages[] = ['role' => 'user', 'content' => $text];
        $this->input      = '';
        $this->loading    = true;

        try {
            $service = app(AiService::class);
            $reply   = $service->chat(
                messages: $this->context(),
                withTools: $this->toolsAllowed(),
            );

            $this->messages[] = ['role' => 'assistant', 'content' => $reply];

            if (config('ai.widget.log_usage', true)) {
                Log::info('ai-widget', [
                    'mode'   => $this->mode,
                    'ip'     => request()->ip(),
                    'user'   => auth()->id(),
                    'input'  => $service->lastInput,
                    'output' => $service->lastOutput,
                    'cost'   => round(app(AiUsage::class)->cost($service->lastInput, $service->lastOutput), 5),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('ai-widget: ' . $e->getMessage(), ['exception' => $e]);
            $this->messages[] = [
                'role'    => 'assistant',
                'content' => config('app.debug') ? 'Error: ' . $e->getMessage() : 'The assistant is not available right now. Please try again later.',
                'error'   => true,
            ];
        }

        $this->messages = array_slice($this->messages, -self::KEEP);
        $this->loading  = false;
    }

    public function clear(): void
    {
        $this->messages = [];
    }

    public function render()
    {
        return view('ai::ai_widget');
    }

    // -------------------------------------------------------------------------

    /** The reason to answer without calling the provider, null when the message can go. */
    protected function refusal(string $text): ?string
    {
        $maxInput = (int) config('ai.widget.max_input', 500);
        if ($maxInput > 0 && mb_strlen($text) > $maxInput) {
            return "Please keep your message under {$maxInput} characters.";
        }

        if (app(AiUsage::class)->exhausted()) {
            return 'The assistant has reached its daily budget and is paused until tomorrow.';
        }

        $limit  = (int) config('ai.widget.rate_limit', 20);
        $window = (int) config('ai.widget.rate_window', 3600);
        if ($limit > 0 && RateLimiter::tooManyAttempts($this->sessionKey(), $limit)) {
            return $this->waitMessage($limit, $window, RateLimiter::availableIn($this->sessionKey()));
        }

        $ipLimit = (int) config('ai.widget.rate_limit_ip', 60);
        if ($ipLimit > 0 && RateLimiter::tooManyAttempts($this->ipKey(), $ipLimit)) {
            return $this->waitMessage($ipLimit, $window, RateLimiter::availableIn($this->ipKey()));
        }

        $interval = (int) config('ai.widget.min_interval', 2);
        if ($interval > 0 && RateLimiter::tooManyAttempts($this->intervalKey(), 1)) {
            return 'One message at a time, please: wait a moment and try again.';
        }

        return null;
    }

    protected function hit(): void
    {
        $window = (int) config('ai.widget.rate_window', 3600);
        RateLimiter::hit($this->sessionKey(), $window);
        RateLimiter::hit($this->ipKey(), $window);

        $interval = (int) config('ai.widget.min_interval', 2);
        if ($interval > 0) {
            RateLimiter::hit($this->intervalKey(), $interval);
        }
    }

    /** The last `ai.widget.history` messages, the ones sent to the model. */
    protected function context(): array
    {
        $history = (int) config('ai.widget.history', 8);
        $context = array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']],
            array_values(array_filter($this->messages, fn ($m) => empty($m['error']))));

        return $history > 0 ? array_slice($context, -$history) : $context;
    }

    /** Tools reach the application's data: operator mode, logged-in user, permission when configured. */
    protected function toolsAllowed(): bool
    {
        if ($this->mode !== 'operator' || !auth()->check()) {
            return false;
        }

        $permission = config('ai.widget.tools_permission');

        return !$permission || auth()->user()->can($permission);
    }

    protected function reply(string $question, string $answer, bool $error = false): void
    {
        $this->messages[] = ['role' => 'user', 'content' => $question];
        $this->messages[] = ['role' => 'assistant', 'content' => $answer, 'error' => $error];
        $this->input = '';
    }

    protected function waitMessage(int $limit, int $window, int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));
        $per     = $window >= 3600 ? ($window / 3600) . 'h' : ($window / 60) . 'min';

        return "Rate limit reached ({$limit} requests / {$per}). Try again in {$minutes} minute" . ($minutes !== 1 ? 's' : '') . '.';
    }

    protected function sessionKey(): string
    {
        return 'ai-widget:' . session()->getId();
    }

    protected function ipKey(): string
    {
        return 'ai-widget-ip:' . request()->ip();
    }

    protected function intervalKey(): string
    {
        return 'ai-widget-pace:' . session()->getId();
    }
}

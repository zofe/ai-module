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

    /**
     * One of the configured examples becomes the question. The index is read against the
     * config, so the panel offers what the application wrote and nothing else.
     */
    public function ask(int $index): void
    {
        $examples = $this->examples();

        if (isset($examples[$index])) {
            $this->input = $examples[$index];
            $this->send();
        }
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
                'content' => config('app.debug') ? 'Error: ' . $e->getMessage() : __('The assistant is not available right now. Please try again later.'),
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
        return view('ai::ai_widget', [
            'title'    => $this->title(),
            'intro'    => $this->intro(),
            'examples' => $this->examples(),
        ]);
    }

    /**
     * The name of the panel. `ai.widget.title` when the application set one: "AI Assistant"
     * says what it is, not what it is for here.
     */
    public function title(): string
    {
        return trim((string) config('ai.widget.title'))
            ?: ($this->mode === 'operator' ? __('AI Assistant') : __('Support'));
    }

    /**
     * The line of the empty panel. The default of the operator mode promises the data, the
     * logs and the users of the application: an application that scoped the assistant to one
     * subject (or took the log tools away with `ai.widget.tools`) has to say so instead.
     */
    public function intro(): string
    {
        return trim((string) config('ai.widget.intro'))
            ?: ($this->mode === 'operator'
                ? __('Ask me about your application data, logs, users, or anything else.')
                : __('How can I help you today?'));
    }

    /**
     * Questions offered as buttons while the panel is empty: three of them teach what can be
     * asked here better than any description.
     *
     * @return list<string>
     */
    public function examples(): array
    {
        return array_values(array_filter(array_map(
            fn ($example) => trim((string) $example),
            (array) config('ai.widget.examples', []),
        )));
    }

    // -------------------------------------------------------------------------

    /** The reason to answer without calling the provider, null when the message can go. */
    protected function refusal(string $text): ?string
    {
        $maxInput = (int) config('ai.widget.max_input', 500);
        if ($maxInput > 0 && mb_strlen($text) > $maxInput) {
            return __('Please keep your message under :max characters.', ['max' => $maxInput]);
        }

        if (app(AiUsage::class)->exhausted()) {
            return __('The assistant has reached its daily budget and is paused until tomorrow.');
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
            return __('One message at a time, please: wait a moment and try again.');
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

        return __('Rate limit reached (:limit requests / :per).', ['limit' => $limit, 'per' => $per])
            . ' ' . trans_choice('Try again in :count minute.|Try again in :count minutes.', $minutes, ['count' => $minutes]);
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

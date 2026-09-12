<?php

namespace Zofe\Ai\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Daily token counters in the cache, converted to USD with the configured
 * prices. The counters are the perimeter of the spend: when the day's cost
 * reaches the budget, the widget stops calling the provider.
 *
 * They live in the store named by `ai.budget.store` (null = the default
 * cache), so that `cache:clear` on the application cache can leave them alone.
 */
class AiUsage
{
    protected function cache(): Repository
    {
        return Cache::store(config('ai.budget.store') ?: null);
    }

    public function record(int $input, int $output): void
    {
        $day = $this->day();
        foreach (['requests' => 1, 'input' => $input, 'output' => $output] as $counter => $value) {
            $key = $this->key($day, $counter);
            $this->cache()->add($key, 0, now()->addDays(2));
            $this->cache()->increment($key, $value);
        }
    }

    /** @return array{day: string, requests: int, input: int, output: int, cost: float} */
    public function today(): array
    {
        return $this->forDay($this->day());
    }

    /** @return array{day: string, requests: int, input: int, output: int, cost: float} */
    public function forDay(string $day): array
    {
        $input  = (int) $this->cache()->get($this->key($day, 'input'), 0);
        $output = (int) $this->cache()->get($this->key($day, 'output'), 0);

        return [
            'day'      => $day,
            'requests' => (int) $this->cache()->get($this->key($day, 'requests'), 0),
            'input'    => $input,
            'output'   => $output,
            'cost'     => $this->cost($input, $output),
        ];
    }

    public function cost(int $input, int $output): float
    {
        return $input / 1_000_000 * (float) config('ai.budget.price_input', 0)
            + $output / 1_000_000 * (float) config('ai.budget.price_output', 0);
    }

    public function budget(): float
    {
        return (float) config('ai.budget.daily', 0);
    }

    /** True when a daily budget is set and today's cost has reached it. */
    public function exhausted(): bool
    {
        $budget = $this->budget();

        return $budget > 0 && $this->today()['cost'] >= $budget;
    }

    /** Rough token count for providers that do not report usage. */
    public static function estimate(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    protected function day(): string
    {
        return now()->format('Y-m-d');
    }

    protected function key(string $day, string $counter): string
    {
        return "ai-usage:{$day}:{$counter}";
    }
}

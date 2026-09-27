<?php

namespace Zofe\Ai\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What the AI costs, kept in two places with two jobs.
 *
 * The daily counters in the cache are the perimeter of the spend: when the day's cost reaches
 * the budget, the widget stops calling the provider. They live in the store named by
 * `ai.budget.store` (null = the default cache), so that `cache:clear` on the application
 * cache can leave them alone.
 *
 * The ledger (`ai_usage`, `ai.budget.ledger`) is the history: one row per call with the
 * context the caller declared, the tokens, the cost at the prices of that moment and who
 * asked. A month, the whole life of the application or the split by context are read from it.
 */
class AiUsage
{
    /** Days of counters kept, for the history of the AI readiness page. */
    public const KEEP_DAYS = 7;

    public const TABLE = 'ai_usage';

    /** Whether the ledger table is there, checked once per process. */
    protected static ?bool $tableExists = null;

    protected function cache(): Repository
    {
        return Cache::store(config('ai.budget.store') ?: null);
    }

    /**
     * Counts one call: the day's counters, and a row in the ledger when it is on. `$context`
     * is what the call was for, one word the caller chooses (classify, chat, widget...).
     */
    public function record(int $input, int $output, ?string $context = null): void
    {
        $day = $this->day();
        foreach (['requests' => 1, 'input' => $input, 'output' => $output] as $counter => $value) {
            $key = $this->key($day, $counter);
            $this->cache()->add($key, 0, now()->addDays(self::KEEP_DAYS + 1));
            $this->cache()->increment($key, $value);
        }

        if ($this->ledgerOn()) {
            $this->write($input, $output, $context);
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

    /**
     * The last days, today first. From the ledger when it is on (it survives `cache:clear`
     * and prices it at the cost of the moment), from the counters otherwise.
     *
     * @return list<array{day: string, requests: int, input: int, output: int, cost: float}>
     */
    public function lastDays(int $days = self::KEEP_DAYS): array
    {
        $rows = [];

        if ($this->ledgerOn()) {
            $from = now()->subDays($days - 1)->startOfDay();
            $byDay = $this->sums(DB::table(self::TABLE)->where('created_at', '>=', $from), $this->dayExpression())
                ->keyBy('bucket');

            for ($i = 0; $i < $days; $i++) {
                $day = now()->subDays($i)->format('Y-m-d');
                $rows[] = $this->shape($byDay->get($day), ['day' => $day]);
            }

            return $rows;
        }

        for ($i = 0; $i < $days; $i++) {
            $rows[] = $this->forDay(now()->subDays($i)->format('Y-m-d'));
        }

        return $rows;
    }

    /**
     * The spend of the current month, from the ledger.
     *
     * @return array{month: string, requests: int, input: int, output: int, cost: float}
     */
    public function month(?string $context = null): array
    {
        $query = DB::table(self::TABLE)->where('created_at', '>=', now()->startOfMonth());
        if ($context) {
            $query->where('context', $context);
        }

        return $this->shape($this->sums($query)->first(), ['month' => now()->format('Y-m')]);
    }

    /**
     * The last months, current first, from the ledger.
     *
     * @return list<array{month: string, requests: int, input: int, output: int, cost: float}>
     */
    public function months(int $months = 12, ?string $context = null): array
    {
        $query = DB::table(self::TABLE)->where('created_at', '>=', now()->subMonths($months - 1)->startOfMonth());
        if ($context) {
            $query->where('context', $context);
        }
        $byMonth = $this->sums($query, $this->monthExpression())->keyBy('bucket');

        $rows = [];
        for ($i = 0; $i < $months; $i++) {
            $month = now()->subMonths($i)->format('Y-m');
            $rows[] = $this->shape($byMonth->get($month), ['month' => $month]);
        }

        return $rows;
    }

    /**
     * Everything since the first row, from the ledger.
     *
     * @return array{since: ?string, requests: int, input: int, output: int, cost: float}
     */
    public function allTime(?string $context = null): array
    {
        $query = DB::table(self::TABLE);
        if ($context) {
            $query->where('context', $context);
        }

        $since = (clone $query)->min('created_at');

        return $this->shape($this->sums($query)->first(), ['since' => $since ? substr((string) $since, 0, 10) : null]);
    }

    /**
     * The spend per context in a period (default: since the start), most expensive first.
     *
     * @return list<array{context: ?string, requests: int, input: int, output: int, cost: float}>
     */
    public function byContext(?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
    {
        $query = DB::table(self::TABLE);
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<', $to);
        }

        return $this->sums($query, 'context')
            ->map(fn ($row) => $this->shape($row, ['context' => $row->bucket]))
            ->sortByDesc('cost')
            ->values()
            ->all();
    }

    /** True when the ledger is on and its table is there. */
    public function ledgerOn(): bool
    {
        if (! config('ai.budget.ledger', true)) {
            return false;
        }

        try {
            return self::$tableExists ??= DB::getSchemaBuilder()->hasTable(self::TABLE);
        } catch (\Throwable) {
            return false;
        }
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

    /** Forget whether the table exists: after a migration in the same process (tests). */
    public static function reset(): void
    {
        self::$tableExists = null;
    }

    // -------------------------------------------------------------------------

    /** One row in the ledger. A failure is logged and never reaches the caller: the answer was given. */
    protected function write(int $input, int $output, ?string $context): void
    {
        $provider = (string) config('ai.provider', 'anthropic');

        try {
            DB::table(self::TABLE)->insert([
                'context'    => $context ? mb_substr($context, 0, 40) : null,
                'provider'   => mb_substr($provider, 0, 20),
                'model'      => mb_substr((string) config("ai.{$provider}.model"), 0, 80) ?: null,
                'tokens_in'  => $input,
                'tokens_out' => $output,
                'cost'       => round($this->cost($input, $output), 6),
                'user_id'    => auth()->id() !== null ? (string) auth()->id() : null,
                'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            Log::warning('ai-usage: the ledger row was not written: ' . $e->getMessage());
        }
    }

    /** Sums of a query, optionally grouped by an expression aliased "bucket". */
    protected function sums($query, ?string $groupBy = null)
    {
        $select = [
            DB::raw('count(*) as requests'),
            DB::raw('coalesce(sum(tokens_in), 0) as input'),
            DB::raw('coalesce(sum(tokens_out), 0) as output'),
            DB::raw('coalesce(sum(cost), 0) as cost'),
        ];

        if ($groupBy) {
            $select[] = DB::raw("{$groupBy} as bucket");
            $query->groupBy(DB::raw($groupBy));
        }

        return $query->select($select)->get();
    }

    protected function shape(?object $row, array $with): array
    {
        return $with + [
            'requests' => (int) ($row->requests ?? 0),
            'input'    => (int) ($row->input ?? 0),
            'output'   => (int) ($row->output ?? 0),
            'cost'     => (float) ($row->cost ?? 0),
        ];
    }

    /** The day of a row as YYYY-MM-DD, in the SQL of the connection in use. */
    protected function dayExpression(): string
    {
        return match (DB::getDriverName()) {
            'sqlite' => "strftime('%Y-%m-%d', created_at)",
            'pgsql'  => "to_char(created_at, 'YYYY-MM-DD')",
            default  => "date_format(created_at, '%Y-%m-%d')",
        };
    }

    protected function monthExpression(): string
    {
        return match (DB::getDriverName()) {
            'sqlite' => "strftime('%Y-%m', created_at)",
            'pgsql'  => "to_char(created_at, 'YYYY-MM')",
            default  => "date_format(created_at, '%Y-%m')",
        };
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

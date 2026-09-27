<?php

namespace Zofe\Ai\Commands;

use Illuminate\Console\Command;
use Zofe\Ai\Services\AiUsage;

class AiUsageCommand extends Command
{
    protected $signature = 'ai:usage {--days=7 : Days to show, today included} {--months=6 : Months to show when the ledger is on}';

    protected $description = 'Requests, tokens and cost of the AI: per day, and per month and context from the ledger';

    public function handle(AiUsage $usage): int
    {
        $money = fn (float $v) => number_format($v, 4);

        $rows = [];
        foreach (array_reverse($usage->lastDays((int) $this->option('days'))) as $day) {
            $rows[] = [$day['day'], $day['requests'], $day['input'], $day['output'], $money($day['cost'])];
        }
        $this->table(['day', 'requests', 'input tokens', 'output tokens', 'cost USD'], $rows);

        if ($usage->ledgerOn()) {
            $rows = [];
            foreach (array_reverse($usage->months((int) $this->option('months'))) as $month) {
                $rows[] = [$month['month'], $month['requests'], $month['input'], $month['output'], $money($month['cost'])];
            }
            $this->table(['month', 'requests', 'input tokens', 'output tokens', 'cost USD'], $rows);

            $rows = [];
            foreach ($usage->byContext(now()->startOfMonth()) as $context) {
                $rows[] = [$context['context'] ?? '-', $context['requests'], $money($context['cost'])];
            }
            $this->table(['context, this month', 'requests', 'cost USD'], $rows);

            $all = $usage->allTime();
            $this->line("Since {$all['since']}: {$all['requests']} requests, {$money($all['cost'])} USD");
        } else {
            $this->line('Ledger: off (AI_LEDGER, or the ai_usage table is missing: php artisan migrate)');
        }

        $budget = $usage->budget();
        $this->line($budget > 0
            ? "Daily budget: {$budget} USD" . ($usage->exhausted() ? ' (reached: the widget is paused until tomorrow)' : '')
            : 'Daily budget: none (AI_DAILY_BUDGET)');

        return self::SUCCESS;
    }
}

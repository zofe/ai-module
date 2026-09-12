<?php

namespace Zofe\Ai\Commands;

use Illuminate\Console\Command;
use Zofe\Ai\Services\AiUsage;

class AiUsageCommand extends Command
{
    protected $signature = 'ai:usage {--days=7 : Days to show, today included}';

    protected $description = 'Requests, tokens and estimated cost of the AI widget, per day';

    public function handle(AiUsage $usage): int
    {
        $rows = [];
        for ($i = (int) $this->option('days') - 1; $i >= 0; $i--) {
            $day = $usage->forDay(now()->subDays($i)->format('Y-m-d'));
            $rows[] = [$day['day'], $day['requests'], $day['input'], $day['output'], number_format($day['cost'], 4)];
        }

        $this->table(['day', 'requests', 'input tokens', 'output tokens', 'cost USD'], $rows);

        $budget = $usage->budget();
        $this->line($budget > 0
            ? "Daily budget: {$budget} USD" . ($usage->exhausted() ? ' (reached: the widget is paused until tomorrow)' : '')
            : 'Daily budget: none (AI_DAILY_BUDGET)');

        return self::SUCCESS;
    }
}

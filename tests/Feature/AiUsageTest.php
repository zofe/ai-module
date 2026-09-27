<?php

namespace Zofe\Ai\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Zofe\Ai\Livewire\AiWidget;
use Zofe\Ai\Services\AiService;
use Zofe\Ai\Services\AiUsage;
use Zofe\Ai\Tests\TestCase;

class AiUsageTest extends TestCase
{
    protected function fakeProvider(int $in = 1_000_000, int $out = 1_000_000): void
    {
        Http::fake([
            'api.test/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]],
                'usage'   => ['prompt_tokens' => $in, 'completion_tokens' => $out],
            ]),
        ]);
    }

    public function test_every_call_writes_one_row_with_its_context_and_its_cost_at_the_prices_of_the_moment()
    {
        $this->fakeProvider();
        config(['ai.budget.price_input' => 0.3, 'ai.budget.price_output' => 1.2]);

        $this->actingAs($this->user());
        app(AiService::class)->chat([['role' => 'user', 'content' => 'hi']], withTools: false, context: 'classify');

        $row = DB::table(AiUsage::TABLE)->first();
        $this->assertSame('classify', $row->context);
        $this->assertSame('openai', $row->provider);
        $this->assertSame(1_000_000, (int) $row->tokens_in);
        $this->assertEqualsWithDelta(1.5, (float) $row->cost, 0.000001);
        $this->assertSame('1', (string) $row->user_id);

        // Prices change later: the row keeps what the call cost when it was made.
        config(['ai.budget.price_input' => 30.0]);
        $this->assertEqualsWithDelta(1.5, (float) DB::table(AiUsage::TABLE)->first()->cost, 0.000001);

        // The counters of the day are still there for the budget.
        $this->assertSame(1, app(AiUsage::class)->today()['requests']);
    }

    public function test_the_widget_writes_under_its_own_context_and_a_call_without_one_is_still_written()
    {
        $this->fakeProvider();

        Livewire::test(AiWidget::class)->set('input', 'hi')->call('send');
        app(AiService::class)->chat([['role' => 'user', 'content' => 'hi']], withTools: false);

        $this->assertSame(['widget', null], DB::table(AiUsage::TABLE)->orderBy('id')->pluck('context')->all());
    }

    public function test_the_month_the_whole_life_and_the_split_by_context_are_read_from_the_ledger()
    {
        config(['ai.budget.price_input' => 1.0, 'ai.budget.price_output' => 1.0]);
        $usage = app(AiUsage::class);

        // Two calls this month, one two months ago: 1M tokens each side, 2 $ each at these prices.
        $this->travelTo(now()->startOfMonth()->addDays(3));
        $usage->record(1_000_000, 1_000_000, 'classify');
        $usage->record(1_000_000, 1_000_000, 'chat');
        $this->travelTo(now()->subMonths(2));
        $usage->record(1_000_000, 1_000_000, 'classify');
        $firstDay = now()->format('Y-m-d');
        $this->travelBack();

        $this->assertEqualsWithDelta(4.0, $usage->month()['cost'], 0.000001);
        $this->assertSame(2, $usage->month()['requests']);
        $this->assertEqualsWithDelta(2.0, $usage->month('chat')['cost'], 0.000001);

        $all = $usage->allTime();
        $this->assertEqualsWithDelta(6.0, $all['cost'], 0.000001);
        $this->assertSame($firstDay, $all['since']);

        $months = $usage->months(3);
        $this->assertSame(now()->format('Y-m'), $months[0]['month']);
        $this->assertEqualsWithDelta(4.0, $months[0]['cost'], 0.000001);
        $this->assertEqualsWithDelta(0.0, $months[1]['cost'], 0.000001);
        $this->assertEqualsWithDelta(2.0, $months[2]['cost'], 0.000001);

        $contexts = $usage->byContext();
        $this->assertSame(['classify', 'chat'], array_column($contexts, 'context'), 'most expensive first');
        $this->assertEqualsWithDelta(4.0, $contexts[0]['cost'], 0.000001);

        // The last days come from the ledger too: a cache:clear does not blank the page.
        $usage->record(1_000_000, 1_000_000, 'chat');   // today
        app('cache')->flush();
        $this->assertEqualsWithDelta(0.0, $usage->today()['cost'], 0.000001, 'the counters are gone');
        $this->assertEqualsWithDelta(2.0, array_sum(array_column($usage->lastDays(7), 'cost')), 0.000001, 'the ledger is not');
    }

    public function test_with_the_ledger_off_nothing_is_written_and_the_counters_still_count()
    {
        config(['ai.budget.ledger' => false]);
        $usage = app(AiUsage::class);

        $usage->record(10, 5, 'chat');

        $this->assertSame(0, DB::table(AiUsage::TABLE)->count());
        $this->assertSame(1, $usage->today()['requests']);
        $this->assertFalse($usage->ledgerOn());
    }

    public function test_an_application_that_has_not_migrated_keeps_its_answers()
    {
        DB::getSchemaBuilder()->drop(AiUsage::TABLE);
        AiUsage::reset();
        $this->fakeProvider();

        $reply = app(AiService::class)->chat([['role' => 'user', 'content' => 'hi']], withTools: false, context: 'chat');

        $this->assertSame('ok', $reply);
        $this->assertFalse(app(AiUsage::class)->ledgerOn());
        $this->assertSame(1, app(AiUsage::class)->today()['requests']);
    }
}

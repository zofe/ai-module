<?php

namespace Zofe\Ai\Tests\Feature;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Livewire\Livewire;
use Zofe\Ai\AiRegistry;
use Zofe\Ai\Livewire\DevelopWithAi;
use Zofe\Ai\Services\AiUsage;
use Zofe\Ai\Tests\TestCase;
use Zofe\Rapyd\Contracts\AiActivity;
use Zofe\Rapyd\Contracts\AiActivityProvider;
use Zofe\Rapyd\Contracts\AiTool;
use Zofe\Rapyd\Contracts\AiToolProvider;

/**
 * The AI page: who may open it; the side for the people who use the application (what the
 * AI did, what it costs, what the assistant may read); the side for the developers.
 */
class DevelopWithAiTest extends TestCase
{
    /** A user with roles, never touching the database. */
    protected function userWith(bool $allowed): Authenticatable
    {
        return new class($allowed) extends Authenticatable {
            protected $guarded = [];
            public function __construct(protected bool $allowed = false) { parent::__construct(); }
            public function getAuthIdentifier() { return 1; }
            public function hasAnyRole($roles) { return $this->allowed; }
            public function hasAnyPermission($permissions) { return $this->allowed; }
        };
    }

    public function test_the_module_declares_its_permission_layout_and_menu()
    {
        $this->assertContains('develop with ai', config('auth.permissions'), 'merged into auth.permissions');
        $this->assertContains('develop with ai', config('auth.role_permissions.operator'));
        $this->assertSame('ai::admin_menu', config('ai.menu_admin'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('ai.develop'));
    }

    public function test_a_guest_is_sent_to_login_and_a_user_without_the_permission_is_refused()
    {
        Livewire::test(DevelopWithAi::class)->assertRedirect();

        $this->actingAs($this->userWith(false));
        Livewire::test(DevelopWithAi::class)->assertForbidden();
    }

    public function test_the_side_for_the_people_who_use_the_application_shows_what_the_ai_did_and_what_it_costs()
    {
        config(['ai.budget.price_input' => 0.30, 'ai.budget.price_output' => 1.20]);
        app(AiUsage::class)->record(1_000_000, 500_000, 'classify');
        AiRegistry::registerActivities(new class implements AiActivityProvider {
            public function activities(): array
            {
                return [
                    new AiActivity('tickets_classified', 'Tickets classified', 8845, 'from 26 categories the AI proposed', 'classify', now()),
                    new AiActivity('chat_sessions', 'Chat sessions', 3),
                ];
            }
        });
        AiRegistry::register(new class implements AiToolProvider {
            public function tools(): array
            {
                return [new AiTool('tickets_count', 'Counts the tickets', ['type' => 'object'], fn () => 0)];
            }
        });
        $this->actingAs($this->userWith(true));

        Livewire::test(DevelopWithAi::class)
            ->assertSee('AI in this application')
            ->assertSee('Tickets classified')
            ->assertSee('8,845')
            ->assertSee('from 26 categories the AI proposed')
            ->assertSee('Chat sessions')
            ->assertSeeInOrder(['8,845', '0.9000 $'])   // the cost of the activity: 1M input at 0.30 + 0.5M output at 1.20
            ->assertSee('This month')
            ->assertSee('Since ' . now()->format('Y-m-d'))
            ->assertSee('tickets_count')
            ->assertSee('Counts the tickets')
            ->assertDontSee('Try it: prompts')
            ->assertDontSee('shop-module');
    }

    public function test_the_side_for_the_developers_is_there_unless_the_application_turned_it_off()
    {
        $this->actingAs($this->userWith(true));

        Livewire::test(DevelopWithAi::class)
            ->assertSee('Develop with AI')
            ->assertSee('To unlock the rest')   // the testbench skeleton has no guideline
            ->assertSee('php artisan rpd:ai')
            ->assertSee('Knows Rapyd Admin')
            ->assertSee('rpd:make Things Thing')
            ->assertSee('Boilerplate written for you');

        config(['ai.develop' => false]);

        Livewire::test(DevelopWithAi::class)
            ->assertSee('AI in this application')
            ->assertDontSee('Develop with AI')
            ->assertDontSee('rpd:make Things Thing')
            ->assertDontSee('Boilerplate written for you');
    }
}

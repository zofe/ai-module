<?php

namespace Zofe\Ai\Tests\Feature;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Livewire\Livewire;
use Zofe\Ai\Livewire\AiStatus;
use Zofe\Ai\Services\AiUsage;
use Zofe\Ai\Tests\TestCase;

/**
 * The AI readiness page: who may open it, the two views, what it says about the
 * application and about the AI runtime.
 */
class AiStatusTest extends TestCase
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
        $this->assertContains('view ai status', config('auth.permissions'), 'merged into auth.permissions');
        $this->assertContains('view ai status', config('auth.role_permissions.operator'));
        $this->assertSame('ai::admin_menu', config('ai.menu_admin'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('ai.status'));
    }

    public function test_a_user_without_the_permission_is_refused_and_a_guest_is_sent_to_login()
    {
        Livewire::test(AiStatus::class)->assertRedirect();

        $this->actingAs($this->userWith(false));
        Livewire::test(AiStatus::class)->assertForbidden();
    }

    public function test_the_simple_view_speaks_plainly_and_lists_the_fixes()
    {
        $this->actingAs($this->userWith(true));

        Livewire::test(AiStatus::class)
            ->assertSet('advanced', false)
            ->assertSee('AI readiness')
            ->assertSee('Coding assistant')
            ->assertSee('Some of what the agent needs is missing')   // the testbench skeleton has no guideline
            ->assertSee('php artisan rpd:ai')
            ->assertSee('Your modules')
            ->assertSee('AI in the app')
            ->assertSeeInOrder(['Provider', 'openai', 'Widget on, mode', 'customer'])
            ->assertDontSee('Resident per session');
    }

    public function test_the_advanced_view_shows_every_row_and_the_spend_of_the_last_days()
    {
        app(AiUsage::class)->record(1_000_000, 500_000);
        $this->actingAs($this->userWith(true));

        Livewire::test(AiStatus::class)
            ->call('toggle')
            ->assertSet('advanced', true)
            ->assertSee('Rapyd Admin guideline')
            ->assertSee('Skill rapyd-module')
            ->assertSee('MCP servers')
            ->assertSee('Resident per session')
            ->assertSee(now()->format('Y-m-d'))
            ->assertSee('1,000,000')
            ->assertSee('0.9000 $')   // 1M input at 0.30 + 0.5M output at 1.20; four decimals under one dollar
            ->call('toggle')
            ->assertSet('advanced', false);

        $this->assertTrue(session('ai.status.advanced') === false, 'the choice is remembered in the session');
    }
}

<?php

namespace Zofe\Ai\Tests\Feature;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Livewire\Livewire;
use Zofe\Ai\Livewire\DevelopWithAi;
use Zofe\Ai\Services\AiUsage;
use Zofe\Ai\Tests\TestCase;

/**
 * The "Develop with AI" page: who may open it, what it says about the agent, the
 * modules, the prompts and the AI runtime.
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

    public function test_the_page_shows_capabilities_generators_modules_prompts_and_the_spend()
    {
        app(AiUsage::class)->record(1_000_000, 500_000);
        $this->actingAs($this->userWith(true));

        Livewire::test(DevelopWithAi::class)
            ->assertSee('Develop with AI')
            ->assertSee('To unlock the rest')   // the testbench skeleton has no guideline
            ->assertSee('php artisan rpd:ai')
            ->assertSee('Knows Rapyd Admin')
            ->assertSee('Builds modules')
            ->assertSee('rpd:make Things Thing')
            ->assertSee('No module in')
            ->assertSee('Try it: prompts for your agent')
            ->assertSee('Add a Suppliers section')
            ->assertSee('needs zofe/shop-module')
            ->assertSee('Boilerplate written for you')
            ->assertSee(now()->format('Y-m-d'))
            ->assertSee('1,000,000')
            ->assertSee('0.9000 $')   // 1M input at 0.30 + 0.5M output at 1.20
            ->assertSeeInOrder(['Provider', 'openai', 'Widget', 'enabled, mode customer']);
    }
}

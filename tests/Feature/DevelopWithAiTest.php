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

    public function test_a_public_page_shows_a_guest_the_side_for_the_people_who_use_the_application_costs_included()
    {
        config(['ai.overview_public' => true, 'ai.budget.price_input' => 0.30, 'ai.budget.price_output' => 1.20]);
        app(AiUsage::class)->record(1_000_000, 500_000, 'chat');

        Livewire::test(DevelopWithAi::class)
            ->assertOk()
            ->assertSee('AI in this application')
            ->assertSee('What it costs')
            ->assertSee('0.9000 $')
            ->assertDontSee('Develop with AI')
            ->assertDontSee('Boilerplate written for you');

        // the public navbar carries the entry only then
        $this->assertStringContainsString('ai/develop', view('ai::frontend_menu')->render());
        config(['ai.overview_public' => false]);
        $this->assertStringNotContainsString('ai/develop', view('ai::frontend_menu')->render());

        // the route has no auth middleware: the component decides
        $this->assertNotContains('auth', \Illuminate\Support\Facades\Route::getRoutes()->getByName('ai.develop')->middleware());
    }

    public function test_the_side_for_the_people_who_use_the_application_shows_what_the_ai_did_and_what_it_costs()
    {
        config(['ai.budget.price_input' => 0.30, 'ai.budget.price_output' => 1.20]);
        app(AiUsage::class)->record(1_000_000, 500_000, 'classify');
        AiRegistry::registerActivities(new class implements AiActivityProvider {
            public function activities(): array
            {
                return [
                    new AiActivity('reviews_summarised', 'Reviews summarised', 1240, 'from the reviews of the last month', 'classify', now()),
                    new AiActivity('chat_sessions', 'Chat sessions', 3),
                ];
            }
        });
        AiRegistry::register(new class implements AiToolProvider {
            public function tools(): array
            {
                return [new AiTool('orders_count', 'Counts the orders', ['type' => 'object'], fn () => 0)];
            }
        });
        $this->actingAs($this->userWith(true));

        Livewire::test(DevelopWithAi::class)
            ->assertSee('AI in this application')
            ->assertSee('Reviews summarised')
            ->assertSee('1,240')
            ->assertSee('from the reviews of the last month')
            ->assertSee('Chat sessions')
            ->assertSeeInOrder(['1,240', '0.9000 $'])   // the cost of the activity: 1M input at 0.30 + 0.5M output at 1.20
            ->assertSee('This month')
            ->assertSee('Since ' . now()->format('Y-m-d'))
            ->assertSee('orders_count')
            ->assertSee('Counts the orders')
            ->assertDontSee('Try it: prompts')
            ->assertDontSee('shop-module');
    }

    public function test_the_documents_the_assistant_knows_are_readable_on_the_page()
    {
        $dir = sys_get_temp_dir() . '/ai-kb-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/10-box.md', "# The box\n\nIt has **two** WAN lines.\n\n<script>alert(1)</script>");
        config(['ai.widget.knowledge' => $dir, 'ai.widget.knowledge_max' => 16000]);
        $this->actingAs($this->userWith(true));

        Livewire::test(DevelopWithAi::class)
            ->assertSee('What it knows')
            ->assertSee('The box')
            ->assertSee('10-box.md')
            ->assertSee('<strong>two</strong>', false)   // rendered, not shown as markdown
            ->assertDontSee('<script>', false);          // html in a document is stripped

        unlink($dir . '/10-box.md');
        rmdir($dir);
    }

    public function test_what_the_ai_did_is_hidden_when_no_module_declares_it()
    {
        $this->actingAs($this->userWith(true));

        Livewire::test(DevelopWithAi::class)->assertDontSeeHtml('>What the AI did</h5>')->assertDontSee('AiActivityProvider');
    }

    public function test_the_page_shows_how_it_works_in_the_language_of_the_page()
    {
        $this->actingAs($this->userWith(true));

        $images = function (string $html): array {
            preg_match_all('#src="data:image/svg\+xml;base64,([^"]+)"#', $html, $m);

            return array_map('base64_decode', $m[1]);
        };

        $html = Livewire::test(DevelopWithAi::class)
            ->assertSee('What ai-module does')
            ->assertSee('It decides what the AI may read')
            ->assertSeeHtml('href="https://github.com/zofe/ai-module"')
            ->html();
        [$tools, $data] = $images($html);
        $this->assertStringContainsString('Who decides what the AI may read', $tools, 'the English diagram, who decides first');
        // On the page the diagram starts below its own title: the heading above says the same.
        $this->assertStringContainsString('viewBox="18 92 ', $tools);
        $this->assertStringContainsString('viewBox="18 92 ', $data);

        app()->setLocale('it');
        [$tools] = $images(Livewire::test(DevelopWithAi::class)->html());
        $this->assertStringContainsString("Chi decide cosa l'AI può leggere", $tools, 'the Italian diagram');
    }

    public function test_the_page_says_how_the_personal_data_is_protected()
    {
        $this->actingAs($this->userWith(true));
        Livewire::test(DevelopWithAi::class)->assertSee('No filter: the texts leave for the provider as they are');

        config(['ai.redact.keep' => ['SKU', 'VAT'], 'ai.redact.enabled' => true, 'ai.redact.presidio.url' => 'https://presidio.example.org', 'ai.redact.presidio.entities' => ['PERSON']]);
        Livewire::test(DevelopWithAi::class)
            ->assertSee('Personal data')
            ->assertSee('Sensitive data protected with Microsoft Presidio')
            ->assertSee('running on presidio.example.org')
            ->assertSee('Presidio finds in the free text: person.')
            ->assertSee('If Presidio does not answer, nothing is sent.')
            ->assertSee('SKU, VAT');
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

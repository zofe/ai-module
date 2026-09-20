<?php

namespace Zofe\Ai;

use Illuminate\Support\Facades\Blade;
use Zofe\Ai\Services\AiService;
use Zofe\Rapyd\Modules\RapydModuleServiceProvider;

/**
 * The AI module as a module package: config.php (provider, widget, budget and,
 * as every module, layout / menu / permissions), the "ai::" views and components,
 * routes.php with the AI readiness page, the @aiWidget directive.
 */
class AiServiceProvider extends RapydModuleServiceProvider
{
    protected string $moduleName = 'Ai';

    protected ?string $modulePath = __DIR__;

    protected ?string $livewireNamespace = 'Zofe\\Ai\\Livewire';

    public function register(): void
    {
        parent::register();   // config.php as config('ai'), permissions into auth.*

        $this->app->singleton(AiService::class);
    }

    public function boot(): void
    {
        $this->bootAppModule('ai');

        Blade::directive('aiWidget', fn () => "<?php if(config('ai.widget.enabled')) echo \Livewire\Livewire::mount('ai::ai-widget'); ?>");

        if ($this->app->runningInConsole()) {
            $this->commands([Commands\AiUsageCommand::class]);
            $this->publishes([
                __DIR__ . '/config.php' => config_path('ai.php'),
            ], 'ai-config');
        }
    }
}

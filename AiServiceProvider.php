<?php

namespace Zofe\Ai;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Zofe\Ai\Services\AiService;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config.php', 'ai');

        $this->app->singleton(AiService::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/Views', 'ai');

        Livewire::addNamespace('ai', null, 'Zofe\\Ai\\Livewire', __DIR__ . '/Livewire');

        Blade::directive('aiWidget', fn () => "<?php if(config('ai.widget.enabled')) echo \Livewire\Livewire::mount('ai::ai-widget'); ?>");

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/config.php' => config_path('ai.php'),
            ], 'ai-config');
        }
    }
}

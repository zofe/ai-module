<?php

namespace Zofe\Ai\Tests;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Zofe\Ai\AiRegistry;
use Zofe\Ai\AiServiceProvider;
use Zofe\Rapyd\RapydServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        AiRegistry::reset();
    }

    protected function getPackageProviders($app)
    {
        return [
            RapydServiceProvider::class,
            LivewireServiceProvider::class,
            \Lab404\Impersonate\ImpersonateServiceProvider::class,
            AiServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        $app['config']->set('app.key', 'base64:Hupx3yAySikrM2/edkZQNQHslgDWYfiBfCuSThJ5SK8=');
        $app['config']->set('app.debug', false);
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('rapyd.search.models', []);

        $app['config']->set('ai.provider', 'openai');
        $app['config']->set('ai.openai.key', 'test');
        $app['config']->set('ai.openai.base_url', 'https://api.test/v1');
        $app['config']->set('ai.widget.enabled', true);
        $app['config']->set('ai.widget.mode', 'customer');
        $app['config']->set('ai.widget.system_prompt', 'You are the test bot.');
        $app['config']->set('ai.widget.min_interval', 0);
        $app['config']->set('ai.widget.rate_limit', 100);
        $app['config']->set('ai.widget.rate_limit_ip', 100);
        $app['config']->set('ai.budget.daily', 0);
    }

    /** A user that never touches the database. */
    protected function user(): Authenticatable
    {
        return new class extends Authenticatable {
            protected $guarded = [];
            public function getAuthIdentifier() { return 1; }
        };
    }
}

<?php

namespace Zofe\Ai;

use Zofe\Rapyd\Contracts\AiActivity;
use Zofe\Rapyd\Contracts\AiActivityProvider;
use Zofe\Rapyd\Contracts\AiTool;
use Zofe\Rapyd\Contracts\AiToolProvider;

/**
 * Static registry that collects AiTool instances from any module.
 *
 * Modules register themselves in their ServiceProvider::boot():
 *
 *     if (class_exists(\Zofe\Ai\AiRegistry::class)) {
 *         \Zofe\Ai\AiRegistry::register(new MyModuleAiToolProvider());
 *     }
 *
 * This guard makes ai-module a soft dependency: the module works without it.
 */
final class AiRegistry
{
    /** @var array<string, AiTool> */
    private static array $tools = [];

    /** @var AiActivityProvider[] */
    private static array $activityProviders = [];

    public static function register(AiToolProvider $provider): void
    {
        foreach ($provider->tools() as $tool) {
            static::$tools[$tool->name] = $tool;
        }
    }

    /** @return AiTool[] */
    public static function tools(): array
    {
        return array_values(static::$tools);
    }

    /**
     * Returns tool definitions ready to be sent to the AI API.
     * Format: Anthropic input_schema by default.
     */
    public static function definitions(): array
    {
        return array_map(fn (AiTool $t) => $t->toDefinition(), static::tools());
    }

    public static function has(string $name): bool
    {
        return isset(static::$tools[$name]);
    }

    public static function execute(string $name, array $input): mixed
    {
        if (!isset(static::$tools[$name])) {
            throw new \InvalidArgumentException("Unknown AI tool: {$name}");
        }

        return static::$tools[$name]->execute($input);
    }

    /**
     * A module says what the AI did in it, for the people who use the application. The
     * providers are kept, not their answers: the counts are read when the page is shown.
     */
    public static function registerActivities(AiActivityProvider $provider): void
    {
        static::$activityProviders[] = $provider;
    }

    /** @return AiActivity[] */
    public static function activities(): array
    {
        $activities = [];
        foreach (static::$activityProviders as $provider) {
            foreach ($provider->activities() as $activity) {
                $activities[$activity->key] = $activity;
            }
        }

        return array_values($activities);
    }

    public static function reset(): void
    {
        static::$tools = [];
        static::$activityProviders = [];
    }
}

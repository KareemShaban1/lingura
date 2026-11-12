<?php

namespace App\Domain\AI;

use App\Domain\AI\Contracts\AiDriver;
use App\Domain\AI\Drivers\FakeDriver;
use App\Domain\AI\Drivers\OpenAiDriver;
use App\Domain\AI\Exceptions\ProviderNotConfigured;
use RuntimeException;

/** Resolves provider drivers by slug. New providers are registered here (or via extend()). */
class AiManager
{
    /** @var array<string, AiDriver> */
    private array $resolved = [];

    /** @var array<string, callable(array): AiDriver> */
    private array $custom = [];

    public function extend(string $driver, callable $factory): void
    {
        $this->custom[$driver] = $factory;
    }

    public function driver(?string $provider = null): AiDriver
    {
        $provider ??= config('ai.default_provider');

        return $this->resolved[$provider] ??= $this->make($provider);
    }

    private function make(string $provider): AiDriver
    {
        $config = config("ai.providers.{$provider}") ?? throw ProviderNotConfigured::make($provider);

        if (isset($this->custom[$config['driver']])) {
            return ($this->custom[$config['driver']])($config);
        }

        return match ($config['driver']) {
            'openai' => new OpenAiDriver($config),
            'fake' => app()->environment('local', 'testing')
                ? new FakeDriver
                : throw new RuntimeException('The fake AI driver may not be used in this environment.'),
            default => throw ProviderNotConfigured::make($provider),
        };
    }
}

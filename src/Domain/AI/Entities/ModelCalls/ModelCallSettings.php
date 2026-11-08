<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\ModelCalls;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Libs\Config;

/**
 * The application's egress settings for model calls, read once per process from the config path `AI.modelCalls`.
 *
 * The module ships NO config file for this path: the framework's config lookup answers a path from the first file it
 * finds (application roots before module roots) and never merges two files key by key, so a module default file would
 * be shadowed as a whole by any application file. Every key therefore has its default here, and a key the application
 * file leaves out keeps that default. An empty string means "off".
 *
 * Keys of `AI/modelCalls.php`:
 *  - `liteLlmHost` — a host fragment identifying a LiteLLM proxy endpoint ('' = no LiteLLM detection);
 *  - `liteLlmApiKeyEnvName` — the env var holding the LiteLLM key, sent as `x-litellm-api-key`;
 *  - `openRouterApiKeyEnvName` — the env var holding the OpenRouter key, sent as `Authorization: Bearer` to openrouter.ai;
 *  - `openRouterPassthroughApiKeyEnvName` — the env var whose key is sent as body `api_key` to a gateway passthrough
 *    endpoint (an OpenRouter-bound endpoint that is not openrouter.ai itself); '' = send none;
 *  - `mandatedRefusalPrefix` — the fixed refusal sentence the application's system prompts mandate; an answer starting
 *    with it is logged as a refusal ('' = detection off).
 */
class ModelCallSettings extends ValueObject
{
    /** @var string The config path of the settings */
    public const string CONFIG_PATH = 'AI.modelCalls';

    /** @var ModelCallSettings|null The settings of this process */
    protected static ?ModelCallSettings $settingsOfThisProcess = null;

    /** @var string Host fragment of a LiteLLM proxy endpoint ('' = none) */
    public string $liteLlmHost = '';

    /** @var string Env var with the LiteLLM key ('' = none) */
    public string $liteLlmApiKeyEnvName = '';

    /** @var string Env var with the OpenRouter key for openrouter.ai */
    public string $openRouterApiKeyEnvName = 'OPENROUTER_KEY';

    /** @var string Env var with the key sent as body `api_key` to a gateway passthrough ('' = none) */
    public string $openRouterPassthroughApiKeyEnvName = '';

    /** @var string The mandated refusal sentence ('' = detection off) */
    public string $mandatedRefusalPrefix = '';

    /**
     * The settings of this process: the application's `AI/modelCalls.php` over the defaults of this class.
     */
    public static function getSettingsOfThisProcess(): ModelCallSettings
    {
        if (static::$settingsOfThisProcess === null) {
            static::$settingsOfThisProcess = static::buildFromConfig();
        }
        return static::$settingsOfThisProcess;
    }

    /**
     * Drops the settings read so far, so the next {@see getSettingsOfThisProcess()} reads the config again (tests).
     */
    public static function forgetSettingsOfThisProcess(): void
    {
        static::$settingsOfThisProcess = null;
    }

    /**
     * Builds the settings from the config path; a key the config does not carry keeps its default.
     */
    public static function buildFromConfig(): static
    {
        $modelCallSettings = new static();
        $configuredSettings = Config::get(self::CONFIG_PATH);
        if (!is_array($configuredSettings)) {
            return $modelCallSettings;
        }
        foreach (['liteLlmHost', 'liteLlmApiKeyEnvName', 'openRouterApiKeyEnvName', 'openRouterPassthroughApiKeyEnvName', 'mandatedRefusalPrefix'] as $settingName) {
            if (isset($configuredSettings[$settingName]) && is_string($configuredSettings[$settingName])) {
                $modelCallSettings->$settingName = $configuredSettings[$settingName];
            }
        }
        return $modelCallSettings;
    }

    /** The LiteLLM host fragment, or null when LiteLLM detection is off. */
    public function getLiteLlmHost(): ?string
    {
        return $this->liteLlmHost !== '' ? $this->liteLlmHost : null;
    }

    /** The env var name of the LiteLLM key, or null when none is configured. */
    public function getLiteLlmApiKeyEnvName(): ?string
    {
        return $this->liteLlmApiKeyEnvName !== '' ? $this->liteLlmApiKeyEnvName : null;
    }

    /** The env var name of the OpenRouter key for openrouter.ai, or null when none is configured. */
    public function getOpenRouterApiKeyEnvName(): ?string
    {
        return $this->openRouterApiKeyEnvName !== '' ? $this->openRouterApiKeyEnvName : null;
    }

    /** The env var name of the gateway passthrough key, or null when no key is sent there. */
    public function getOpenRouterPassthroughApiKeyEnvName(): ?string
    {
        return $this->openRouterPassthroughApiKeyEnvName !== '' ? $this->openRouterPassthroughApiKeyEnvName : null;
    }

    /** The mandated refusal sentence, or null when refusal detection is off. */
    public function getMandatedRefusalPrefix(): ?string
    {
        return $this->mandatedRefusalPrefix !== '' ? $this->mandatedRefusalPrefix : null;
    }
}

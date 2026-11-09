# mgamadeus/ddd-ai

AI model management, prompt system, and Argus AI integration traits for the [mgamadeus/ddd](https://github.com/ddd-framework/ddd) framework.

## Installation

```bash
composer require mgamadeus/ddd-ai
```

## What it does

Provides the infrastructure for AI-powered features:

- **AIModel** — catalog of 60+ AI models (OpenAI GPT-4o through GPT-5.4, o3/o4 reasoning, Meta Llama, image models FLUX/Imagen, audio, embeddings) with pricing and capability metadata
- **AIPrompt** — template system with `{%parameter%}` substitution and token estimation
- **Argus AI traits** — plug into any Argus repo entity to add LLM-powered loading via OpenAI/Google Gemini APIs
- **Batch services** — OpenAI and OpenRouter server-side integration (batch controllers for Argus endpoints)
- **Cost tracking** — per-operation cost estimation and budget enforcement

## Configuration

### AI Models

Models are loaded from `config/app/AI/models.php` (ships with 60+ model definitions). Override by placing your own `config/app/AI/models.php` in your project — project config takes priority.

### AI Prompts

Prompts are markdown files loaded from `config/app/AI/Prompts/` using dot-notation paths:

```php
$prompt = AIPrompt::getService()->getAIPromptByName('Common.Texts.DetectedLanguage');
// Loads: config/app/AI/Prompts/Common/Texts/DetectedLanguage.md
```

Override any prompt by placing a file at the same path in your project's `config/app/AI/Prompts/`.

### Environment Variables

The AI module itself requires no environment variables. The underlying Argus module needs `ARGUS_API_ENDPOINT` configured (see [ddd-argus](https://github.com/ddd-framework/ddd-argus)).

AI batch services require the OpenRouter/OpenAI API keys to be configured in the Argus batch gateway.

## Service registration

Add to your project's `services.yaml`:

```yaml
# DDD Module: ddd-ai
DDD\Domain\AI\Services\:
    resource: '%kernel.project_dir%/vendor/mgamadeus/ddd-ai/src/Domain/AI/Services/*'
    public: true

DDD\Domain\Batch\Services\AI\:
    resource: '%kernel.project_dir%/vendor/mgamadeus/ddd-ai/src/Domain/Batch/Services/AI/*'
    public: true
```

## Usage

### Adding AI to an Argus repo entity

```php
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;

#[ArgusLoad(
    loadEndpoint: 'POST:/ai/openRouter/chatCompletions',
    cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB,
    cacheTtl: ArgusCache::CACHELEVEL_NONE
)]
#[ArgusLanguageModel(
    defaultAIModelName: AIModel::MODEL_OPENAI_GPT5_2,
    defaultAIPromptName: 'My.Custom.Prompt',
)]
class ArgusMyAIEntity extends MyEntity
{
    use ArgusTrait, ArgusAILanguageModelTrait;

    public function getAIPromptWithParametersApplied(): AIPrompt
    {
        $prompt = $this->getAIPrompt();
        $prompt->setParameter('locale', 'de-DE');
        return $prompt;
    }

    public function getUserContent(): string|array
    {
        return 'The text to process';
    }

    protected function applyLoadResult(string $resultText): void
    {
        $this->result = $resultText;
    }
}
```

### Supported vendors

- **OpenAI** (default) — chat completions, embeddings, audio, image
- **Google Gemini** — alternative payload format, auto-detected by vendor
- **Fal.ai** — image generation models (FLUX, Sana, etc.)
- **OpenRouter** — multi-model gateway

## Batch controllers

The module ships `BatchAIController` with endpoints for:
- `/ai/openRouter/chatCompletions` — LLM chat completions
- `/ai/openRouter/embeddings` — text embeddings

Import in your project's `routes.yaml` to activate:

```yaml
batch_ai:
    resource: '%kernel.project_dir%/vendor/mgamadeus/ddd-ai/src/Presentation/Api/Batch/AI'
    type: annotation
    prefix: '/api/batch'
```

Or extend the controller in your app to customize behavior.

### Security

Batch endpoints must be secured with ROLE_SUPER_ADMIN. Ensure your `security.yaml` includes:

```yaml
# config/symfony/default/packages/security.yaml
security:
    access_control:
        - { path: ^/api/batch, roles: ROLE_SUPER_ADMIN }
```

Argus clients authenticate automatically using the account specified by:

```env
# Must have SUPER_ADMIN role — bearer token is sent with every Argus API call
CLI_DEFAULT_ACCOUNT_ID_FOR_CLI_OPERATIONS=1
```

See [ddd-argus](https://github.com/ddd-framework/ddd-argus) for the full security configuration example.

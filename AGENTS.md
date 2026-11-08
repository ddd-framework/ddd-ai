# mgamadeus/ddd-ai -- AI Module

AI model management, prompt system, and Argus AI integration traits for the `mgamadeus/ddd` framework.

**Package:** `mgamadeus/ddd-ai` (v1.0.x)
**Namespace:** `DDD\`
**Depends on:** `mgamadeus/ddd` (^2.10), `mgamadeus/ddd-argus` (^1.0), `mgamadeus/ddd-common-money` (^1.0)

> **This module follows all DDD Core conventions.** For base patterns, see `vendor/mgamadeus/ddd/AGENTS.md` and skills in `vendor/mgamadeus/ddd`. For Argus patterns, see `vendor/mgamadeus/ddd-argus`.

## Architecture

```
src/
+-- Domain/AI/
|   +-- Entities/
|   |   +-- Models/AIModel.php, AIModels.php          [60+ AI models with pricing]
|   |   +-- Models/Settings/AILanguageModelSetting.php [Token limits, costs]
|   |   +-- Prompts/AIPrompt.php, AIPrompts.php       [Template system with parameters]
|   +-- Repo/Argus/
|   |   +-- Attributes/ArgusLanguageModel.php          [#[ArgusLanguageModel] attribute]
|   |   +-- Traits/ArgusAILanguageModelTrait.php       [LLM integration trait]
|   |   +-- Traits/ArgusAIImageModelTrait.php          [Image generation trait]
|   +-- Services/AIModelsService.php, AIPromptsService.php
|   +-- Exceptions/AiBudgetExceededException.php
+-- Domain/Batch/Services/AI/
|   +-- OpenAIService.php                              [Direct OpenAI HTTP client]
|   +-- OpenRouterService.php                          [Multi-vendor gateway client]
+-- Presentation/Api/Batch/AI/BatchAIController.php    [4 batch endpoints]
+-- Modules/AI/AIModule.php
config/app/AI/
+-- models.php                                         [60+ model definitions with pricing]
+-- Prompts/                                           [Markdown prompt templates]
```

## Core Concepts

### AIModel (60+ Models)

Catalog of AI models across vendors with capability metadata and pricing:

**Vendors:** OpenAI, Google Gemini, Meta, Black Forest Labs, Fal.ai
**Types:** LANGUAGE, IMAGE, AUDIO, EMBEDDINGS
**Flags:** `isReasoningModel`, `hasVisionCapabilities`

Key models: `MODEL_OPENAI_GPT5_4` (current flagship), `MODEL_OPENAI_GPT5_4_MINI` (fast), `MODEL_OPENAI_O3` (reasoning), `MODEL_GOOGLE_GEMINI_2_5_PRO`, `MODEL_OPENAI_TEXT_EMBEDDING_3_SMALL`

### AIPrompt (Template System)

Markdown prompts with `{%parameter%}` substitution, loaded from `config/app/AI/Prompts/` via dot-notation:

```php
$prompt = AIPrompt::getService()->getAIPromptByName('Common.Texts.DetectedLanguage');
// Loads: config/app/AI/Prompts/Common/Texts/DetectedLanguage.md
$prompt->setParameter('locale', 'de-DE');
$tokens = $prompt->getEstimatedInputTokens();
```

### ArgusAILanguageModelTrait

Plug LLM capabilities into any Argus entity. The trait handles:
- Model/prompt resolution from attributes
- Vendor-specific payload formatting (OpenAI chat messages vs Gemini parts)
- Image handling (base64 data URLs for multimodal)
- Response parsing from both API formats
- Token counting and cost tracking
- Gates and usage recording through `AIModelCallGateService` / `AIModelCallUsageRecorderService` (module defaults:
  allow everything, record nothing; an application overrides them with a same-name subclass in `App\Domain\AI\Services`)
- Responses API mode, reasoning effort, provider routing, fast-mode service tier, tool calls, 503 fallback model
- Egress authentication from `ModelCallSettings` (optional application config `AI/modelCalls.php`)

**Three abstract methods to implement:** `getUserContent()`, `getAIPromptWithParametersApplied()`, `applyLoadResult(string)`

Every Chat Completions request sends `max_tokens` and `temperature` (default 0.8); `DEFAULT_ARGUS_TIMEOUT` is 300 s.
See CHANGELOG.md for the 3.0.0 changes.

### Cost Tracking

```php
$model = AIModelsService::instance()->getAIModelByName(AIModel::MODEL_OPENAI_GPT5_4);
$costs = $model->getEstimatedCostsForPrompt($prompt, $userContent);  // MoneyAmount
$costs = $model->getEstimatedCostsForTokens(1000, 500);              // MoneyAmount
```

Tiered pricing supported (e.g., Gemini: different rates above 200K tokens).

## Batch Endpoints

`BatchAIController` at `/api/batch/ai/`:

| Endpoint | Purpose |
|----------|---------|
| `POST /ai/openAi/chatCompletions` | OpenAI chat completions |
| `POST /ai/openAi/embeddings` | OpenAI embeddings |
| `POST /ai/openRouter/chatCompletions` | OpenRouter multi-vendor chat |
| `POST /ai/openRouter/embeddings` | OpenRouter embeddings |

Secured with `ROLE_SUPER_ADMIN`.

## Environment Variables

```env
ARGUS_API_ENDPOINT=https://...              # Required by Argus
CLI_DEFAULT_ACCOUNT_ID_FOR_CLI_OPERATIONS=1 # SUPER_ADMIN for batch auth
OPEN_AI_KEY=sk-...                          # For OpenAIService (direct)
OPENROUTER_KEY=sk-or-...                    # For OpenRouterService
```

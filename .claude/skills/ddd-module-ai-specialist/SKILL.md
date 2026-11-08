---
name: ddd-module-ai-specialist
description: "Work with AI models, prompts, cost tracking and Argus AI integration in the ddd-ai module: the model catalog (config/app/AI/models.php: vendor, openRouterExternalId, prices, speed and benchmarks, agentEligible/agentTier, supportsFastMode), AIModelsService lookup and scope-based selection, AIPrompt templates with project overrides, cost estimation, the gate and usage-recorder services (budget, eligibility, cost ledger via same-name App subclasses), request shapes (max_tokens + temperature, Responses API, reasoning effort, provider routing, fast tier, 503 fallback, egress keys from AI/modelCalls.php), image generation, embeddings, batch endpoints, decision models (TypeSafe System One). Use when adding LLM, image or embedding capabilities to entities, enforcing an AI budget or recording AI costs, adding or repricing a model, choosing a model for a scope, managing prompts, or estimating AI costs."
metadata:
  author: mgamadeus
  version: "3.0.0"
  module: mgamadeus/ddd-ai
---

# AI Module Specialist

AI model management, prompt system, and Argus AI integration.

> **Base patterns:** See core skills in `vendor/mgamadeus/ddd`. For Argus repos, see `vendor/mgamadeus/ddd-argus`.

## When to Use

- Adding LLM-powered features to Argus entities
- Working with AI prompts and parameter substitution
- Estimating token costs for AI operations
- Adding image generation capabilities
- Creating text embeddings

## Adding AI to an Argus Entity

Combine `#[ArgusLoad]` + `#[ArgusLanguageModel]` + `ArgusAILanguageModelTrait`:

```php
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;

#[ArgusLoad(
    loadEndpoint: 'POST:/ai/openRouter/chatCompletions',
    cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB,
    cacheTtl: ArgusCache::CACHELEVEL_NONE          // Don't cache AI responses by default
)]
#[ArgusLanguageModel(
    defaultAIModelName: AIModel::MODEL_OPENAI_GPT5_4_MINI,
    defaultAIPromptName: 'MyApp.Analysis.Classify',
    responseFormat: ArgusLanguageModel::RESPONSE_FORMAT_JSON_OBJECT,
)]
class ArgusMyAnalysis extends MyAnalysis
{
    use ArgusTrait, ArgusAILanguageModelTrait;

    // REQUIRED: Provide the user content to send to the LLM
    public function getUserContent(): string|array
    {
        return $this->textToAnalyze;
        // For multimodal (text + images):
        // return [
        //     ['type' => 'text', 'text' => 'Describe this image'],
        //     ['type' => 'image_url', 'image_url' => ['image' => $photoEntity]],
        // ];
    }

    // REQUIRED: Set prompt parameters
    public function getAIPromptWithParametersApplied(): AIPrompt
    {
        $prompt = $this->getAIPrompt();
        $prompt->setParameter('locale', 'de-DE');
        $prompt->setParameter('categories', 'food, drink, dessert');
        return $prompt;
    }

    // REQUIRED: Store the AI response
    protected function applyLoadResult(string $resultText): void
    {
        $parsed = json_decode($resultText);
        $this->category = $parsed->category ?? null;
        $this->confidence = $parsed->confidence ?? 0.0;
    }
}
```

### `#[ArgusLanguageModel]` Attribute

```php
#[ArgusLanguageModel(
    defaultAIModelName: AIModel::MODEL_OPENAI_GPT5_4,   // Model constant
    defaultAIPromptName: 'MyApp.Analysis.Classify',       // Prompt dot-path
    defaultAIPromptVersion: null,                          // Optional version
    temperature: 0.8,                                       // 0.0-1.0+ (skipped for reasoning models)
    responseFormat: ArgusLanguageModel::RESPONSE_FORMAT_JSON_OBJECT, // or DEFAULT
    systemPromptName: 'MyApp.System.Instructions',          // Optional system prompt
    preferredProviders: ['groq', 'cerebras'],               // OpenRouter-only: ordered provider preference
)]
```

Response formats: `RESPONSE_FORMAT_DEFAULT` (free text), `RESPONSE_FORMAT_JSON_OBJECT` (forces JSON output)

**`preferredProviders`** (OpenRouter only): Ordered list of provider slugs to prefer (e.g. `['groq', 'cerebras']`). Translated into the OpenRouter `provider.order` body parameter with `allow_fallbacks: true`. Has no effect when the load endpoint targets OpenAI directly.

## AIModel (60+ Models)

```php
$modelsService = AIModelsService::instance();
$model = $modelsService->getAIModelByName(AIModel::MODEL_OPENAI_GPT5_4);

$model->type;                   // 'LANGUAGE'
$model->vendor;                 // 'OPENAI'
$model->externalId;             // 'gpt-5.4'
$model->isReasoningModel;       // true
$model->hasVisionCapabilities;  // true
$model->settings->maxInputTokens;  // 1050000
```

**Key model constants** (the catalog is the source of truth — read `config/app/AI/models.php` before quoting a price):
- `MODEL_OPENAI_GPT6_SOL` -- PREMIUM tier, reasoning + vision, 1.05M context
- `MODEL_OPENAI_GPT6_LUNA` -- CHEAP tier and the pinned AGENTIC / COMPACTION default
- `MODEL_OPENAI_GPT5_6_LUNA` -- superseded 2026-09-24, kept without a tier (historical eval numbers refer to it)
- `MODEL_GOOGLE_GEMINI_3_5_FLASH`, `MODEL_ZAI_GLM_5_2`, `MODEL_MINIMAX_M3` -- other tier/scope picks
- `MODEL_ANTHROPIC_CLAUDE_OPUS_5_5`, `MODEL_ANTHROPIC_CLAUDE_SONNET_5`, `MODEL_ANTHROPIC_CLAUDE_FABLE_5_1` -- the Claude 5 family (2.0.0); Opus 4.8 and Sonnet 4.6 were REMOVED in the same release
- `MODEL_TYPESAFE_JEV_1_13` -- decision model, see below
- `MODEL_OPENAI_TEXT_EMBEDDING_3_SMALL` -- Embeddings

**Pinned scope defaults** live in `AIModelsService::SCOPE_PINNED_DEFAULT_MODELS` (`ModelScope::AGENTIC`,
`COMPACTION`, `ORCHESTRATOR`, …). A pin is an explicit owner decision that overrides the cost/score heuristic —
change it there, never by repricing a model.

### A time-limited price gets a dated TEST, not a comment

Vendors ship introductory pricing that expires (Gemini 3.x Flash: half price through 2026-12-31, then double).
The catalog books what is charged TODAY — booking tomorrow's list price over-books every call until then. But a
comment saying "update this on 2027-01-01" is a reminder nobody receives: the day passes and the catalog silently
under-books by half.

So pair the introductory price with a test that fails on the date (`GeminiFlashCatalogTest::testTheGeminiIntroductoryPriceWindowHasNotClosed()`):
it passes while the window is open, and from the deadline it fails with a message naming the model, the key, the
list price to set, and the reminder to re-check the vendor's page in case the window was extended. The deadline
becomes a red test at exactly the right moment instead of a wrong number nobody looks at.

### Fast mode — a per-model service tier, flagged only after a verified request

Some providers serve the same model on a faster, dearer tier (OpenAI's `service_tier: "priority"`, passed through
and echoed by OpenRouter). Whether the tier exists and what it costs is catalog knowledge:
`'supportsFastMode' => true` plus `'fastModePriceMultiplier' => 2.0` on the row, hydrated onto
`AIModel::$supportsFastMode` / `$fastModePriceMultiplier`. The egress sends the tier only when the SELECTED model's
flag is true, and multiplies an ESTIMATED cost by the factor when the provider reported no cost of its own.

Flag a model only after one request with the tier set, reading the echoed tier and the cost — never by family
analogy (GPT-6 Luna is verified; GPT-6 Sol is not, and keeps the default). `FastModeCatalogTest` pins both cases.

### Retiring a model — a stored name must keep resolving

Model names are PERSISTED by consumers (conversation overrides, messages, compaction nodes, usage logs). Deleting a
catalog entry therefore breaks every stored row that names it: `getAIModelByName()` returns null, or throws with
`throwErrors`, and continuing an old conversation or opening an admin view of an old row fails.

So a removal is two edits, not one: drop the constant and the entry, AND add the name to
`AIModel::RETIRED_MODEL_SUCCESSORS`, which `getAIModelByName()` follows. The resolved model carries
`$resolvedFromRetiredModelName` (the name that was asked for) — read it before writing any record of what ran, so a
log of an Opus 4.8 call never claims Opus 5.5 produced it.

What a removal does NOT preserve is the retired model's PRICES. Every cost in this package is computed from a live
model at call time and persisted as an amount by the consumer; nothing re-prices a stored row. A consumer that ever
wants to re-derive a historical cost must keep its own record of the rate it paid.

Removing a public constant is a BREAKING change — release it as a major version, and check the consuming apps for
references first (`grep -rn MODEL_<NAME>`): a removed constant is a fatal error, not a graceful failure.

**Benchmarks and agent eligibility:** `getAgentEligibleModels()` keeps only models whose `agenticScore` is not null,
i.e. that carry at least one benchmark from `AGENTIC_WEIGHTS` (BFCL, τ²-bench, GAIA, SWE-bench Verified, AgentBench,
Terminal-Bench). A brand-new model without one of those is invisible to tier selection. Never file a non-map
benchmark under a neighbouring id; if a score has to stand in for a successor model, mark the row `'official' => false`
and say in the comment what replaces it.

**Vendors:** `VENDOR_OPENAI`, `VENDOR_GOOGLE`, `VENDOR_ANTHROPIC`, `VENDOR_XAI`, `VENDOR_META`, `VENDOR_PERPLEXITY`, `VENDOR_BLACK_FOREST_LABS`, `VENDOR_FALAI`, and the Chinese group served through Western OpenRouter providers: `VENDOR_MINIMAX`, `VENDOR_MOONSHOT`, `VENDOR_ALIBABA`, `VENDOR_ZAI` — plus `VENDOR_TYPESAFE` (decision models, below). `AIModel::getModelVendors()` reflects over the `VENDOR_` constants, so a new vendor constant is all the `#[Choice]` validation needs.

### Decision models — a catalog row that is not a chat model

`MODEL_TYPESAFE_JEV_1_13` (TypeSafe "System One") answers TYPED questions — choice (≤ 255 options), score (2–10
levels), noul (yes/no) — with calibrated probabilities and a confidence, in one parallel pass, and produces **no
text**. It is reachable only through OpenRouter's `POST /api/v1/systemone` route, so a consuming app overrides the
payload and response protocol methods of the language-model trait in its own Argus repo; the module only carries the
catalog row.

Such a row is `TYPE_LANGUAGE` (the settings hydrate as `AILanguageModelSetting`, and that price row is what
`getEstimatedCostsForTokens()` reads), `agentEligible => false`, and carries **no** `agentTier`, `speed` or
`benchmarks`: no agentic benchmark constant applies to a decision model, and a null `agenticScore` is never consulted
while `agentEligible` is false. Going through the catalog is what makes the call a normal AI op — scope and envelope
enforced, counted against the account's cap, logged per call, and priced from this row.

**Types:** `TYPE_LANGUAGE`, `TYPE_IMAGE`, `TYPE_AUDIO`, `TYPE_EMBEDDINGS`

## AIPrompt (Template System)

Prompts are markdown files at `config/app/AI/Prompts/{dot.path.as.directories}.md`:

```php
$promptsService = AIPromptsService::instance();
$prompt = $promptsService->getAIPromptByName('Common.Texts.DetectedLanguage');
// Loads: config/app/AI/Prompts/Common/Texts/DetectedLanguage.md

$prompt->setParameter('locale', 'de-DE');
$prompt->setParameter('text', $inputText);

$rendered = $prompt->getPromtTextWithParametersApplied();  // {%locale%} replaced
$tokens = $prompt->getEstimatedInputTokens();              // Token count
```

**Override:** Place file at same path in your project's `config/app/AI/Prompts/`.

## Cost Estimation

```php
$model = $modelsService->getAIModelByName(AIModel::MODEL_OPENAI_GPT5_4);

// From prompt + content
$costs = $model->getEstimatedCostsForPrompt($prompt, $userContent);  // MoneyAmount

// From token counts
$costs = $model->getEstimatedCostsForTokens(promptTokens: 1000, outputTokens: 500);

// Image token calculation (for vision models)
$imageTokens = $model->calculateImageTokenCost($photo, 'auto');  // 65 low, 129/512px high
```

Tiered pricing supported (Gemini: different rates above `inputTierThresholdTokens`).

## Gates, budget and usage recording (3.0)

The trait asks `AIModelCallGateService` before a call goes out and hands every priced answer to
`AIModelCallUsageRecorderService`. The module defaults allow every call and record nothing; an application adds its
policy with a subclass of the SAME name in `App\Domain\AI\Services` (the framework's service lookup returns it):

```php
namespace App\Domain\AI\Services;

class AIModelCallGateService extends \DDD\Domain\AI\Services\AIModelCallGateService
{
    public function needsCostEstimateForBudgetCheck(AIModelCall $aiModelCall): bool
    {
        return $aiModelCall->accountBilledForAICosts !== null;
    }

    public function refuseModelCallOverBudget(AIModelCall $aiModelCall, ?MoneyAmount $estimatedCosts): void
    {
        if (/* the account's remaining budget is below $estimatedCosts */ false) {
            throw new AiBudgetExceededException('AI budget exhausted');
        }
    }
}
```

Stage order for a language model: `refuseModelCallWithoutUsageScopeOrEnvelope` → model resolved →
`prepareBilledEntitiesBeforeGates()` (repo hook) → `refuseModelCallForIneligibleBilledScope` → when the attribute's
`enforceBudget` is true and `needsCostEstimateForBudgetCheck()` answers true: `refuseModelCallWithoutBilledAccount` →
`loadBudgetSettingsOfBilledAccount` → cost estimate → `refuseModelCallOverBudget`. A stage refuses by throwing.

- Who pays: `$argusRepo->billAICostsTo($account, $entityWithinAccount)` before the load; the gate and recorder read it
  from `AIModelCall::$accountBilledForAICosts` / `$entityWithinAccountBilledForAICosts`.
- The recorder gets `AIModelCallUsage` (input/output/cached/cache-write/reasoning tokens, web searches, `costs`).
- `enforceBudget: false` on the attribute skips the budget stages (infrastructure calls); usage is still recorded.

## Request shape and egress (3.0)

- Chat Completions: `model`, `max_tokens`, `temperature` (attribute value, else 0.8), `messages`, then
  `response_format`, `provider`, `reasoning`, `tools`. No `max_completion_tokens`.
- Model id: the catalog's `openRouterExternalId` towards an OpenRouter-bound endpoint (path contains `openrouter`),
  `openrouter/<id>` towards a LiteLLM proxy, the provider-native `externalId` otherwise.
- A Gemini model gets the Gemini-native body only towards a non-OpenRouter endpoint.
- A declared `systemPromptName` puts the system prompt in the `system` role; the task prompt then leads the user
  content and string user content is wrapped in `<user-data>…</user-data>`.
- `apiMode: ArgusOpenAIApiMode::RESPONSES` builds an OpenAI Responses payload; `reasoningEffort` becomes
  `reasoning: {effort}` towards OpenRouter, `reasoning_effort` towards OpenAI directly.
- `preferredProviders` / `providerPrioritizationStrategy` → the OpenRouter `provider` block; `fastMode: true` sends
  the priority service tier for models with `supportsFastMode`.
- `fallbackAIModelName`: one retry with that model (same vendor) after a 503.
- Egress keys come from the optional application config `AI/modelCalls.php` (`ModelCallSettings`; defaults in code,
  the module ships no such file): `liteLlmHost`, `liteLlmApiKeyEnvName`, `openRouterApiKeyEnvName`
  (Bearer towards openrouter.ai), `openRouterPassthroughApiKeyEnvName` (body `api_key` towards a gateway passthrough),
  `mandatedRefusalPrefix`.
- `DEFAULT_ARGUS_TIMEOUT` = 300 s; failed loads are not cached; `getArgusCallKind()` = usage scope.

## Image Generation

Use `ArgusAIImageModelTrait` (extends language trait):

```php
#[ArgusLoad(loadEndpoint: 'POST:/ai/falai/imageGeneration')]
#[ArgusLanguageModel(defaultAIModelName: AIModel::MODEL_FALAI_FLUX_PRO_V1_1_ULTRA)]
class ArgusImageGenerator extends MyImage
{
    use ArgusTrait, ArgusAIImageModelTrait;

    protected int $width = 1024;
    protected int $height = 768;
    protected int $numberOfImages = 1;

    public function getUserContent(): string { return $this->prompt; }
    public function getAIPromptWithParametersApplied(): AIPrompt { return $this->getAIPrompt(); }
    protected function applyLoadResult(string $resultText): void { $this->imageUrls = $resultText; }
}
```

## Batch Endpoints

| Endpoint | Service | Purpose |
|----------|---------|---------|
| `POST /ai/openAi/chatCompletions` | `OpenAIService` | Direct OpenAI chat |
| `POST /ai/openAi/embeddings` | `OpenAIService` | Direct OpenAI embeddings |
| `POST /ai/openRouter/chatCompletions` | `OpenRouterService` | Multi-vendor chat |
| `POST /ai/openRouter/embeddings` | `OpenRouterService` | Multi-vendor embeddings |

## Vendor Auto-Detection

The trait auto-detects Google Gemini vs OpenAI/OpenRouter based on `AIModel.vendor`:
- **Gemini:** Builds "parts" array format, `inline_data` for images, `response_mime_type` for JSON
- **OpenAI/OpenRouter:** Chat message format, `response_format` for JSON, `max_completion_tokens` for reasoning models
- Temperature is skipped for reasoning models (o3, o4, GPT-5.4 Pro)

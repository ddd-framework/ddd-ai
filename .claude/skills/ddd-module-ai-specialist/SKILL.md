---
name: ddd-module-ai-specialist
description: "Work with AI models, prompts, cost tracking and Argus AI integration in the ddd-ai module: the model catalog (config/app/AI/models.php: vendor, openRouterExternalId, prices, speed and benchmarks, agentEligible/agentTier, supportsFastMode), AIModelsService lookup and scope-based selection, AIPrompt templates with project overrides, cost estimation, the gate and usage-recorder services (budget, cost ledger via same-name App subclasses), request shapes (Responses API, reasoning effort, provider routing, fast tier, 503 fallback, egress keys from AI/modelCalls.php), images, embeddings, batch endpoints, decision models, usage envelopes (AIUsageEnvelopesService, budget bypass, booked costs), compaction threshold per model, commands tier-picks and provider-endpoints. Use when adding LLM, image or embedding capabilities to entities, enforcing an AI budget or recording AI costs, grouping AI calls of a request or job, adding or repricing a model, choosing a model for a scope, managing prompts, or estimating AI costs."
metadata:
  author: mgamadeus
  version: "3.6.0"
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
- `MODEL_OPENAI_GPT6_1_SOL` -- upgrade of GPT-6 Sol, no agent tier (picked by name, e.g. app UI translations); no fast mode
- `MODEL_OPENAI_GPT6_2_SOL` -- upgrade of GPT-6.1 Sol (same prices), no agent tier (picked by name); no fast mode
- `MODEL_MOONSHOT_KIMI_K3_1_PRO` -- Moonshot open-weight, no agent tier; catalog `providerFilters` route it only to US/EU full-precision hosts; `supportsParallelToolCalls: false` (no allowed host accepts `parallel_tool_calls`; with `requireParameters` the request would find no endpoint). Every language model sets `supportsParallelToolCalls` explicitly; requests with tools send `parallel_tool_calls` unless it is false. `supportsForcedToolChoice` (explicit on every tool model, host-overridable; false on Claude Opus 5.5 and Fable 5.1, which answer `tool_choice: "required"` or a named function with a 400) — `AIModel::acceptsForcedToolChoice()`; an agent then sends `auto` and names the tool in the prompt. `transportTimeoutSeconds` (int, optional, host-overridable; Kimi K3.1 Pro 120) is the total timeout of one HTTP attempt (`AIModel::getTransportTimeoutSeconds()`, default 300); the agent loop applies it and retries the call. `agenticUseCase.previousReasoning` (optional, host-overridable; value object `AIModelPreviousReasoningCarryOver` on `AIModelAgenticUseCaseConfig::$previousReasoning`, read with `AIModel::getAgenticPreviousReasoningCarryOver(?string $agentWireApi)`) tells an agent whether and how far to send the model's own reasoning of earlier calls back: `carryOver` (off|currentTurn|capped|all), and under `capped` `capTokens` (max), `keptTokensAtCut` (min: whole steps kept from the newest back until at least this many tokens), `keptStepsAtCut`, `headroomTokensAfterCut` (the next cut waits until the carried sum exceeds max(cap, kept + headroom)); every field null = the agent's next source decides (its run settings come first, its config per wire API after). A value measured on one wire API goes under `agenticUseCase.previousReasoningByWireApi` (`[AIModel::AGENT_WIRE_API_MESSAGES => ['carryOver' => …]]`, same shape per wire; `AIModelWireApiPreviousReasoningCarryOvers` on `AIModelAgenticUseCaseConfig::$previousReasoningByWireApi`): on a call over that wire it replaces the model-wide `previousReasoning` as a whole, on every other wire the model-wide value applies (absent = the model-wide value everywhere). The resolver is `getAgenticPreviousReasoningCarryOver($agentWireApi)` — the one read; pass the wire the call actually speaks (after a fallback), null = the catalog's `agentWireApi`. A known wire key counts in any case; an unknown key is kept as written and logged as a warning at catalog load (applies only to that exact wire id), a non-string key is skipped. A host overrides both keys in its own `models.php` copy. Catalog: Kimi K3.1 Pro `currentTurn` (model-wide: the measuring wire is not recorded); GPT-6 Luna and GPT-6.2 Sol `capped` at 1,000 on `responses` only (Luna measured there; Sol the same by decision, not measured), the agent's defaults on Chat Completions; Claude Opus 5.5 `all` on `messages` only (measured there). An invalid value reads as unset. `ArgusAILanguageModelTrait::$providerOrderRotation` rotates the OpenRouter `order` of a retry (`AIModelProviderFilters::rotateOrderOfProviderBlock()`), `$lastResponseStatusCode` / `getLastResponseHeaders()` describe the last response
- `MODEL_OPENAI_GPT6_LUNA` -- CHEAP tier and the pinned AGENTIC / COMPACTION default
- `MODEL_OPENAI_GPT5_6_LUNA` -- superseded 2026-09-24, kept without a tier (historical eval numbers refer to it)
- `MODEL_GOOGLE_GEMINI_3_5_FLASH`, `MODEL_ZAI_GLM_5_2`, `MODEL_MINIMAX_M3` -- other tier/scope picks
- `MODEL_ANTHROPIC_CLAUDE_OPUS_5_5`, `MODEL_ANTHROPIC_CLAUDE_SONNET_5`, `MODEL_ANTHROPIC_CLAUDE_FABLE_5_1` -- the Claude 5 family (2.0.0); Opus 4.8 and Sonnet 4.6 were REMOVED in the same release
- `MODEL_ANTHROPIC_CLAUDE_SONNET_5_5`, `MODEL_ANTHROPIC_CLAUDE_HAIKU_5_5`, `MODEL_ANTHROPIC_CLAUDE_FABLE_5_2` -- the 5.5 line and Fable 5.2 (3.5.0), no measured speed or benchmark yet. Sonnet 5.5 and Fable 5.2 reject a forced tool choice, Haiku 5.5 accepts it. Opus 5.5, Sonnet 5.5, Haiku 5.5, Fable 5.1 and Fable 5.2 name `agentWireApi: messages`; Sonnet 5 (no mid-conversation tool additions) and Haiku 4.5 (no adaptive thinking) stay on Chat Completions
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
analogy (GPT-6 Luna is verified; GPT-6 Sol and GPT-6.1 Sol are not, and keep the default). `FastModeCatalogTest` pins both cases.

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

### Compaction threshold per model (`effectiveContextTokens`)

`AIModel::$effectiveContextTokens` is the token count from which a consumer reduces a conversation's context (the
reliable-reasoning length, well below the hard window). A catalog row sets it with `'effectiveContextTokens' => …`;
without one, `AIModelsService` derives it from the context window (`defaultEffectiveContextTokensForWindow()`):

| Context window | Default threshold |
|---|---|
| ≥ 1,000,000 | 128,000 |
| ≥ 400,000 | 96,000 |
| ≥ 250,000 | 64,000 |
| ≥ 190,000 | 50,000 |
| ≥ 120,000 | 64,000 |
| smaller | 60 % of the window, at least 8,000 |

Every value is capped at 80 % of the window. The values are absolute counts, not fractions: accuracy degrades with the
raw token count, not with the share of the window used. No window (null or 0) → no threshold.

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

## Console commands (3.4)

`app:ai-model:tier-picks [--vendor=…]` prints the pick per tier, per scope (a pinned scope default is named as such)
and the Pareto frontier — run it before and after a catalog change and compare. `app:ai-models:provider-endpoints
<catalog name|author/model>` lists the OpenRouter providers of a model (tool support, uptime, price), cheapest first.
`ModelTierOptionTrait` gives a command that runs a model `--model` / `--tier` / `--vendor`
(`addModelTierOptions()`, `resolveEffectiveModelName()`, `writeModelSelection()`). An application replaces a command
with a subclass that repeats `#[AsCommand]` with the same name. Details and override points: `AGENTS.md`, "Console
Commands".

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
- **Session seconds** (a realtime voice session billed by time): `AISessionUsageService::recordSessionSeconds($aiModelCall,
  $seconds, ?$costsPerSessionSecond)` books through the same recorder; the usage carries `sessionSeconds` and
  `costs` = seconds × the catalog's `costsPerSessionSecondInUSD` (`AIModelSetting::$costsPerSessionSecond`) or the
  passed price. Unpriced = nothing booked (`costs` null on the returned usage).
  The key is read for every type: a session-only model (realtime voice) is filed as `TYPE_AUDIO` with
  `settings.costsPerSessionSecondInUSD`; only `TYPE_LANGUAGE` models are candidates of the automatic choice, so it
  never answers a text turn. Book inside the session's envelope,
  under its usage scope and billed account; a long session may book in slices.
- `enforceBudget: false` on the attribute skips the budget stages (infrastructure calls); usage is still recorded.

### The AI cost ledger (3.9)

`AI.usageLog.enabled` (config `config/app/AI/usageLog.php`, default `false`) makes the module's recorder book every
priced call and slice of session seconds into `AIUsageLog` (table `AIUsageLogs`, `AIUsageLogsService`) and report it
to the open envelope (`recordBookedCost`). Switch it on only after the table exists (generate the model with
`app:generate-doctrine-models-for-entities`, create the table through the schema diff). A row the ledger cannot write
is logged as an error; the call that caused the cost is never failed by it.

- Columns: account (billed, else the envelope's, else the signed-in one), feature, usage and envelope scope, source
  (short repo class name), model, prompt, tokens, web searches, session seconds, `costInUSD`, `initiatedBy`.
- `AIModelCall::$subjectEntity`: the record a call works for (a conversation, a voice call), for the ledger only; a
  repo names it in `getSubjectEntityForUsageRecording()` (default none), a builder of a call passes it.
- `recordFee()`: a cost that is no model call (web search requests).
- Override points (same-path subclass of `AIUsageLogsService`): `attributeUsageLog()` (more columns: a same-path
  subclass of `AIUsageLog`, same table), `findFeature()` (the application's feature names),
  `findAccountIdConfiningTheReader()` (default: an admin with access to all resources reads every row, any other
  account its own). No account writes a row with its own rights; `recordUsage()` writes with the rights lifted.
- An application with budget pots of its own keeps its same-path recorder subclass and calls the parent first.

## Usage envelopes, budget bypass and booked costs (3.2)

An envelope groups every AI call made while it is open (one request, one job, one turn); envelopes nest.
`AIUsageEnvelopesService` holds them for the process — fetch it from the container only (one shared instance):

```php
/** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
$aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
$envelope = $aIUsageEnvelopesService->openEnvelope($accountId, 'ENVELOPE.MY.WORK', AIUsageInitiator::MESSENGER);
try {
    // the AI calls
} finally {
    $aIUsageEnvelopesService->closeEnvelope($envelope);
}
```

- Scopes start with `ENVELOPE.` (`WithAIEnvelope::ENVELOPE_SCOPE_PREFIX`). The `WithAIEnvelope` attribute only
  DECLARES the scope of a class or method; the application (or a module) opens the envelope from it.
- **Budget bypass:** `grantBudgetBypassForCurrentEnvelope(AIBudgetBypassGrant::forReason(…))` — inherited by every
  envelope opened inside; `grantBudgetBypassUntilRestored()` + `restoreBudgetBypassGrant()` in `finally`;
  `grantBudgetBypassForTheProcess()` for a console command. The grant lifts the cap only, the spend is still booked.
- **Booked costs of one piece of work:** `$recording = startRecordingBookedCosts()` … `stopRecordingBookedCosts($recording)`
  in `finally`; the usage log calls `recordBookedCost($usageScope, $costInUSD)`. `AIUsageBookedCosts` holds the total,
  the count and `scopeBookedCosts` (one `AIUsageScopeBookedCost` per scope). A booked amount goes to the innermost
  open recording only.
- The base persists nothing. An application overrides `persistEnvelopeOpening()` (returns the envelope id),
  `persistEnvelopeClosing()`, `isEnvelopeScope()`, `isBookedCostCountedElsewhere()` (default: config
  `AI.usageScopesCountedElsewhere`) in a same-name subclass; more attribution than an account = a subclass of
  `AIUsageEnvelopeContext` opened with `openEnvelopeForContext()`.
- A Messenger worker empties the service before every consumed job (it is a worker state resetter) — never reset it
  in a handler.

Agent settings in the model catalog: `settings.agentWireApi` (`chatCompletions` / `responses` / `messages`) and
`settings.agentReasoningContext` (`all_turns` / `current_turn` / `auto`), read with `AIModel::getAgentWireApi()` /
`getAgentReasoningContext()`. `getAgentWireApi()` returns the value AS WRITTEN (absent or empty = Chat Completions)
and does not check it: the agent validates it and speaks Chat Completions for a value it does not know, so a new wire
API needs no ddd-ai release. (`getAgentReasoningContext()` still answers null for a value outside
`REASONING_CONTEXTS`.) `agentWireApi` names the wire a model works best on (its native one): `messages` = the Anthropic Messages API (signed thinking blocks that are sent
back, a report of every block the provider dropped, mid-conversation system messages and tool additions),
`responses` = the OpenAI Responses API, absent = Chat Completions. The package catalog sets `messages` on the Claude
models probed on it; a host overrides the key per model in its own `config/app/AI/models.php`. An agent that cannot
serve the named wire on its egress falls back to Chat Completions (mgamadeus/ddd-agents >= 0.2.0; older agent
versions do not know `messages`, hence the Composer conflict).

Answers of a wire API the trait does not know: the trait reads Chat Completions, OpenAI Responses and Gemini-native
answers and composes no Messages request. A repo that speaks another wire API (the agent egress on the Anthropic
Messages API) builds its body itself and overrides four protected hooks for its answer shape:
`isAnswerObjectWithoutEnvelope()` (default: an object with `choices`; asked by `unwrapAIModelResponse()`),
`extractAnswerText()` and `extractAnswerUsage()` (defaults: the Responses readers, which read only a Responses
answer, `isOpenAIResponsesAnswer()`; null falls through to the Chat and Gemini readers) and `extractToolCallsFromResponse()` (calls in the Chat-Completions shape). Usage convention of
`AIModelCallUsage`: `inputTokens` is the whole input (uncached + cache read + cache write), `cachedInputTokens` and
`cacheWriteTokens` are parts of it, `reasoningOutputTokens` is part of `outputTokens`.

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
- A failed answer never throws: after the load, `getLastLoadFailureReason()` names why (`http 400 from the upstream
  call: …`, a transport rejection, `an answer without text`) and `getArgusSettings()->isLoadedSuccessfully` is false.
  Treat that as a failure in the caller — never as an empty success.

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

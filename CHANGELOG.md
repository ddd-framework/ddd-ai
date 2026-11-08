# Changelog

## 3.1.0

Requires `mgamadeus/ddd-argus` ^1.3.2.

- `getLastLoadFailureReason(): ?string` on the language-model trait: why the last load failed (the transport
  rejection, the upstream HTTP status and message, or an answer without text); null after a successful load. A load
  never throws for a failed answer; check this (or `getArgusSettings()->isLoadedSuccessfully`) before using the result.

## 3.0.0

Requires `mgamadeus/ddd` ^3.14.3 and `mgamadeus/ddd-argus` ^1.3.1. A major release: the language-model and image-model
Argus traits are rebuilt; check every repo class that uses them before upgrading.

### Breaking / consumer-visible

- **Request shape (Chat Completions):** every request sends `max_tokens` and `temperature`, reasoning models included;
  `max_completion_tokens` is gone. A class without its own `temperature` now sends the trait default `0.8` (2.x sent
  none, so the provider used its own default). Body key order: `model`, `max_tokens`, `temperature`, `messages`, then
  the optional keys.
- **A Gemini model towards an OpenRouter-bound endpoint** gets the Chat Completions body; the Gemini-native body is
  built only for a Gemini endpoint.
- **Failed loads are not cached:** an answer without text (and without tool calls) clears its Argus cache entry.
- **Timeout:** `DEFAULT_ARGUS_TIMEOUT = 300` s applies when `#[ArgusLoad]` declares no timeout (2.x: none).
- **Recorded runs:** `getArgusCallKind()` returns the usage scope (`ddd-argus` adds the class name).
- **Pricing:** every answer with usage is priced from the catalog (or the provider-reported cost) and handed to the
  usage recorder; a model used by a repo must carry prices and an `openRouterExternalId` in the catalog.
- **Removed:** `isAIBudgetSufficientForOperation()` (replaced by the gate service, below).
- **`billAICostsTo(?Account $account, ?Entity $entityWithinAccount)`:** replaces the 2.x budget-owner setters; read the
  result with `getAccountBilledForAICosts()` / `getEntityWithinAccountBilledForAICosts()`.
- **Lookups:** a model or prompt lookup of the trait no longer leaves `throwErrors` switched on.

### New

- **`AIModelCallGateService`** (default: lets every call through) — staged checks before a call goes out:
  `refuseModelCallWithoutUsageScopeOrEnvelope`, `refuseModelCallForIneligibleBilledScope`,
  `needsCostEstimateForBudgetCheck`, `refuseModelCallWithoutBilledAccount`, `loadBudgetSettingsOfBilledAccount`,
  `refuseModelCallOverBudget`, `refuseImageModelCallOverBudget`.
- **`AIModelCallUsageRecorderService`** (default: records nothing) — `recordModelCallUsage(AIModelCall, AIModelCallUsage)`
  once per priced answer.
- An application overrides either with a subclass of the same name in `App\Domain\AI\Services`; the framework's service
  lookup returns the subclass.
- Value objects `AIModelCall`, `AIModelCallUsage`, `ModelCallSettings` (egress settings from the optional application
  config `AI/modelCalls.php`: `liteLlmHost`, `liteLlmApiKeyEnvName`, `openRouterApiKeyEnvName`,
  `openRouterPassthroughApiKeyEnvName`, `mandatedRefusalPrefix`; defaults in code).
- `#[ArgusLanguageModel]` keeps its 2.x parameters in place and adds `aiUsageScope`, `fallbackAIModelName`, `apiMode`
  (`ArgusOpenAIApiMode`), `reasoningEffort` (`ArgusOpenAIReasoningEffort`), `preferredModelTier`,
  `preferredModelVendor`, `maxModelBlendedCostPer1MUsd`, `modelScope`, `enforceBudget`,
  `providerPrioritizationStrategy`, `fastMode`.
- OpenAI Responses API mode, reasoning effort (`reasoning: {effort}` towards OpenRouter), provider routing (`provider`
  block), service tier for fast-mode models, tool calls, a declared system prompt in the `system` role with the task
  prompt leading the user content (`<user-data>` envelope), a 503 fallback model, image parts given as data URLs.
- `AIImageModelSetting::getEstimatedCostsForImages()`.
- PHPUnit tests for payload shapes, gate stages and usage recording.

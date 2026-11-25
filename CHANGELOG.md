# Changelog

## 3.8.2

- **The price per session second is read for every model type.** `settings.costsPerSessionSecondInUSD` of an `AUDIO`
  (or `EMBEDDINGS`) catalog entry is hydrated (an `AILanguageModelSetting` carrying only the price), so a session-only
  model such as a realtime voice model is priced by `AISessionUsageService::recordSessionSeconds()`. Before, only
  `LANGUAGE` and `IMAGE` entries kept it, and an application restored it with an override. File a session-only model
  as `AUDIO`: only `LANGUAGE` models are candidates of the automatic model choice, so it never answers a text turn. An
  entry of another type without the key keeps no settings, as before. Tests.

## 3.8.1

- Catalog: GPT-6 Luna and GPT-6.2 Sol state their reasoning carry-over (`capped` at 1,000) under
  `agenticUseCase.previousReasoningByWireApi` for `AIModel::AGENT_WIRE_API_RESPONSES` instead of model-wide. Luna was
  measured on the Responses API (the comparison arm ran over Chat Completions with carry-over off); Sol takes the same
  value by decision without a measurement of its own. On Chat Completions (also the catalog default wire of both) the
  agent's defaults now apply. Kimi K3.1 Pro stays model-wide. A host that wants the old behaviour sets
  `previousReasoning` in its own `models.php` copy.

## 3.8.0

- The carry-over of a model's own earlier reasoning per wire API, additive: `agenticUseCase.previousReasoningByWireApi`
  (`'<wire api>' => <same shape as agenticUseCase.previousReasoning>`, keys `AIModel::AGENT_WIRE_API_*`; value objects
  `AIModelWireApiPreviousReasoningCarryOver` in the set `AIModelWireApiPreviousReasoningCarryOvers` on
  `AIModelAgenticUseCaseConfig::$previousReasoningByWireApi`). For a value measured on one wire only: on a call over that
  wire it replaces the model-wide `previousReasoning` as a whole; on every other wire the model-wide value applies. An
  entry without the key behaves as before.
- The one resolver: `AIModel::getAgenticPreviousReasoningCarryOver(?string $agentWireApi = null)` (also
  `AIModelAgenticUseCaseConfig::getPreviousReasoningForAgentWireApi()`). An agent passes the wire API the call speaks
  after any fallback; without an argument the model's catalog `agentWireApi` decides, so existing callers keep working.
- Catalog load: a known wire key counts in any case; an unknown key is kept as written (a wire a newer agent speaks)
  and logged as a warning; a key that is no string, a value that is no array or one without a valid field is skipped.
  Nothing throws.
- Catalog: Claude Opus 5.5 `all` moved under `messages` (measured on the Messages API only); on another wire the
  agent's defaults apply. Kimi K3.1 Pro (`currentTurn`), GPT-6 Luna and GPT-6.2 Sol (`capped` at 1,000) stay model-wide:
  the catalog does not record the wire they were measured on. A host that knows it states them per wire in its own
  `models.php` copy.

## 3.7.0

- Booking of session seconds (a realtime voice session billed by time), additive: `AISessionUsageService::recordSessionSeconds(AIModelCall, float $seconds, ?MoneyAmount $costsPerSessionSecond)`
  hands the usage to `AIModelCallUsageRecorderService` like a priced token answer; `AIModelCallUsage::$sessionSeconds`
  (0 for token calls), the catalog key `settings.costsPerSessionSecondInUSD` for language and image models
  (`AIModelSetting::$costsPerSessionSecond`, `getEstimatedCostsForSessionSeconds()`). A session without a price books
  nothing. A host recorder that books into budget pots reads `sessionSeconds` and `costs` as it reads tokens.

## 3.6.0

- **Changed:** the carry-over of a model's own earlier reasoning moved into the agentic block of the catalog:
  `agenticUseCase.previousReasoning`, the value object `AIModelPreviousReasoningCarryOver` on
  `AIModelAgenticUseCaseConfig::$previousReasoning` (`AIModel::getAgenticPreviousReasoningCarryOver()`). Fields, all
  optional (null = the agent's next source decides): `carryOver` (`off`, `currentTurn`, `capped`, `all`; constants
  `AIModelPreviousReasoningCarryOver::CARRY_OVER_*`), and for `capped` `capTokens` (max), `keptTokensAtCut` (min: at a
  cut, whole steps are kept from the newest back until at least this many reasoning tokens), `keptStepsAtCut` and
  `headroomTokensAfterCut` (after a cut, the next one waits until the carried sum exceeds max(cap, kept + headroom)).
  An invalid value reads as unset. The top-level key `previousReasoningCarryOver`, `AIModel::$previousReasoningCarryOver`,
  `AIModel::getPreviousReasoningCarryOver()` and `AIModel::PREVIOUS_REASONING_CARRY_OVER*` are removed, without an alias.
- Catalog: Kimi K3.1 Pro keeps `currentTurn` (moved); GPT-6 Luna and GPT-6.2 Sol `capped` at 1,000 reasoning tokens
  (measured on Luna: the more of its own earlier reasoning it sees, the more it reasons per call and the slower it
  answers; a cap of 1,000 instead of 4,000 kept the first call of a turn short at equal quality); Claude Opus 5.5 `all`
  (with its earlier thinking replayed it thinks less per call; the replay is read from the prompt cache, a cut rewrites
  the cache from the first removed step).
- **Host steps:** update `mgamadeus/ddd-agents` to 0.3.3 or newer together with this release (older versions call the
  removed getter; the package declares the conflict). A host catalog that sets `previousReasoningCarryOver` on a model
  moves it to `'agenticUseCase' => ['previousReasoning' => ['carryOver' => …]]` — the old key is no longer read.

## 3.5.0

- Agent wire API `messages` (`AIModel::AGENT_WIRE_API_MESSAGES`, in `CATALOG_AGENT_WIRE_APIS`): the Anthropic Messages
  API as a third value of the catalog's `settings.agentWireApi`. The key names the wire a model works best on:
  `messages` for the Claude models probed on it (Opus 5.5, Sonnet 5.5, Haiku 5.5, Fable 5.1, Fable 5.2), `responses`
  where a host sets it for an OpenAI model, absent = Chat Completions. Claude Sonnet 5 (refuses mid-conversation tool
  additions) and Claude Haiku 4.5 (no adaptive thinking) stay on Chat Completions. Host-overridable per model.
- **Changed:** `AIModel::getAgentWireApi()` returns the catalog's `settings.agentWireApi` as written (absent or empty
  = Chat Completions). It no longer maps the value onto `CATALOG_AGENT_WIRE_APIS` and no longer logs and replaces an
  unknown one: this package stores the facts an agent reads and checks none of them. The agent that reads the value
  decides what it speaks (mgamadeus/ddd-agents 0.2.0 speaks Chat Completions for a value it does not know and logs
  it), so a later wire API needs no release of this package. A caller that compared the result with a constant and
  wrote the catalog value in another case or with blanks uses `AIModel::canonicalAgentWireApi()`.
- Hooks for an answer shape of another wire API on the language-model trait: `isAnswerObjectWithoutEnvelope()`
  (asked by `unwrapAIModelResponse()`; default: an object with `choices`), `extractAnswerText()` and
  `extractAnswerUsage()` (defaults: the Responses readers). A repo that speaks a wire API the trait does not compose
  (the agent egress on the Messages API) overrides them together with `extractToolCallsFromResponse()`; the trait
  itself reads no Messages answer.
- **Changed:** the Responses readers (`extractOpenAIResponsesText()`, `extractOpenAIResponsesUsage()`) read only a
  Responses answer (`isOpenAIResponsesAnswer()`: `object: "response"`, an `output` list without `choices`, or
  `output_text`). Before, any answer with `usage.input_tokens` was booked as Responses usage; an answer of another
  wire API names its usage with the same keys and counts it differently (Messages: the uncached remainder only).
- Model catalog: Claude Sonnet 5.5 (`MODEL_ANTHROPIC_CLAUDE_SONNET_5_5`, $2 / $10 per 1M, cache read $0.10), Claude
  Haiku 5.5 (`MODEL_ANTHROPIC_CLAUDE_HAIKU_5_5`, $0.10 / $0.50 per 1M up to 100K prompt tokens, $0.50 / $2.50 above,
  cache read $0.01) and Claude Fable 5.2 (`MODEL_ANTHROPIC_CLAUDE_FABLE_5_2`, $8 / $40 per 1M, cache read $0.20), each
  with 1M context, 128K output, Anthropic cache markers and no invented speed or benchmark row.
  `supportsForcedToolChoice` is false for Sonnet 5.5 and Fable 5.2 and true for Haiku 5.5 (probed).
- **Host step:** update `mgamadeus/ddd-agents` to 0.2.0 or newer together with this release. Older agent versions
  refuse a conversation on a model whose catalog entry names `messages`; Composer blocks that pair
  (`conflict: mgamadeus/ddd-agents <0.2.0`). A host that stays on ddd-agents 0.1.x stays on ddd-ai 3.4.x.

## 3.4.8

- Model catalog `supportsForcedToolChoice` (?bool, explicit on every tool model, host-overridable):
  `AIModel::acceptsForcedToolChoice()` is false for Claude Opus 5.5 and Claude Fable 5.1, which answer a forced
  `tool_choice` (`required` or a named function; Messages API `any` / a named tool) with a 400 (`tool_choice: type
  "tool" and "any" are not supported for this model.`); null = not decided (forced as before).

## 3.4.7

- Model catalog `previousReasoningCarryOver` (`AIModel::PREVIOUS_REASONING_CARRY_OVERS`: off, currentTurn, capped, all;
  host-overridable): whether an agent sends the model's own reasoning items of earlier calls back
  (`AIModel::getPreviousReasoningCarryOver()`, null = the caller's default). Kimi K3.1 Pro: `currentTurn` (it keeps its
  verdicts in its reasoning, not in its visible text).

## 3.4.6

- Model catalog: `transportTimeoutSeconds` (int, optional, overridable per model in the host's
  `config/app/AI/models.php`): the total time one HTTP attempt to the model may take (`AIModel::getTransportTimeoutSeconds()`,
  default `DEFAULT_TRANSPORT_TIMEOUT_SECONDS` 300). Kimi K3.1 Pro: 120 s — a hanging host behind OpenRouter held the
  connection for 300 s with padding bytes, so only a total timeout per attempt fires.
- Language-model repos: `$providerOrderRotation` rotates the OpenRouter `order` of a request
  (`AIModelProviderFilters::rotateOrderOfProviderBlock()`, only `order` changes — `only`, `ignore`, `quantizations`,
  `data_collection` and `allow_fallbacks` stay; never on a console pin), for a retry that should ask the next host
  first; `$lastResponseStatusCode` and `getLastResponseHeaders()` describe the last response.

## 3.4.5

- Model catalog: `supportsParallelToolCalls` (bool, explicit per language model, overridable per model in the host's
  `config/app/AI/models.php`; read through `AIModel::sendsParallelToolCalls()`). A request with tools carries
  `parallel_tool_calls: true` only when it is not false. Kimi K3.1 Pro: false — none of its OpenRouter endpoints accepts
  the parameter, and with its catalog `requireParameters` OpenRouter answered every tool call with 404 "No endpoints found
  that can handle the requested parameters". Every other language model with tools: true (as sent until now). Undecided
  (null, sent as before): Perplexity Sonar and Typesafe JEV 1.13 (no tools), GPT-5 Search API (no OpenRouter route),
  o3-mini, o4-mini and Grok 4.1 Fast (no longer listed on OpenRouter).

## 3.4.4

- Model catalog: Moonshot AI Kimi K3.1 Pro (`AIModel::MODEL_MOONSHOT_KIMI_K3_1_PRO`, OpenRouter
  `moonshotai/kimi-k3.1-pro`): reasoning + vision, 1M context, output capped at 128K (the lowest allowed host);
  $2.00 / $10.00 per 1M, cached input $0.20, automatic prefix caching. No agent tier. Its catalog routing sends it only
  to US/EU full-precision hosts (DeepInfra, Fireworks, Together, Amazon Bedrock; never Moonshot AI or Alibaba), without
  fallback, `data_collection: deny`, `require_parameters: true`, quantizations fp16 / bf16 / fp8.
- Provider routing per model: the catalog's `providerFilters` also take `only`, `dataCollection`, `requireParameters`
  and `quantizations` (written as OpenRouter's `only`, `data_collection`, `require_parameters`, `quantizations`). A
  host overrides them per model in its own `config/app/AI/models.php`. The OpenAI Responses body towards OpenRouter or
  the LiteLLM proxy now carries the same `provider` block as the Chat Completions body (it carried none).
- Prompt caching: the Claude models (Opus 5.5, Sonnet 5, Fable 5.1, Haiku 4.5) declare `anthropicCacheControl` with
  write prices 1.25x (default 5 min) and 2x (`AIModel::PROMPT_CACHE_WRITE_TTL_ONE_HOUR`).

## 3.4.3

- Model catalog: OpenAI GPT-6.2 Sol (`AIModel::MODEL_OPENAI_GPT6_2_SOL`, OpenRouter `openai/gpt-6.2-sol`), released
  2026-11-17: reasoning + vision, 1.05M context, 128K output; $2.00 / $10.00 per 1M, cached input $0.10, above 272K
  prompt tokens $4.00 / $15.00 (same as GPT-6.1 Sol, which stays). No agent tier (no weighted agentic benchmark
  catalogued) and no fast mode (not verified).

## 3.4.1

- `ArgusAILanguageModelTrait::$lastRequestPayload` holds the payload of the last call on every request shape: the
  Perplexity, Gemini (`generation_config`) and OpenAI Responses API bodies set it like the Chat Completions body. Before,
  those three left it unset, so it read null or the payload of an earlier Chat Completions call of the same object.

## 3.4.0

- Console commands in `DDD\Symfony\Commands\AI\`, registered without configuration; an application replaces one by a
  subclass that repeats `#[AsCommand]` with the same name (`FrameworkCommandsYieldPass` of `mgamadeus/ddd`):
  - `app:ai-model:tier-picks [--vendor=…]`: the AIModelsService pick per tier and per scope and the Pareto frontier.
    A scope with a pinned default (AGENTIC, COMPACTION, ORCHESTRATOR) is printed as "pinned default of the scope"
    without a cost; `getModelForScope()` records no decision for it, so the decision of the previous selection is not
    shown in its row. An unknown `--vendor` fails the command.
  - `app:ai-models:provider-endpoints <model>`: the OpenRouter providers serving a catalog model or a raw
    `author/model` id (tool support, uptime, price, quantization), cheapest first; `--tools-only`, `--min-uptime`,
    `--json`. A raw id of another shape is refused before any request.
- `ModelTierOptionTrait`: `--model`, `--tier`, `--vendor` for a command that runs a language model, and
  `resolveEffectiveModelName()` (explicit model, else the tier's pick with the vendor as soft preference, else null).
  `addModelTierOptions()` keeps a `--model` the command declared before; an unknown tier or vendor throws
  `InvalidOptionException`.
- `ModelSelectionContext::uniqueKey()` includes `appliesAgentLoopClassification` and `requiresAgentEligible`: two
  contexts that differ only there no longer share a cached pick.
- Skill: the default compaction threshold per context window (`effectiveContextTokens`) and the console commands.

## 3.3.0

- Model catalog: OpenAI GPT-6.1 Sol (`AIModel::MODEL_OPENAI_GPT6_1_SOL`, OpenRouter `openai/gpt-6.1-sol`): reasoning +
  vision, 1.05M context, 128K output; $2.00 / $10.00 per 1M, cached input $0.10, above 272K prompt tokens
  $4.00 / $15.00. No agent tier (no weighted agentic benchmark catalogued yet) and no fast mode (not verified).

## 3.2.1

- The model catalog's `settings.agentWireApi` and `settings.agentReasoningContext` reach the model: `AIModelsService`
  did not read them, so `AIModel::getAgentWireApi()` answered Chat Completions and `getAgentReasoningContext()` null
  for every model.

## 3.2.0

Requires mgamadeus/ddd ^3.19.

- `AIUsageEnvelopesService`: the open AI usage envelopes of the process (nesting, `openEnvelope()` /
  `closeEnvelope()`), the AI-budget bypass grants riding on them (`AIBudgetBypassGrant`, restore as
  `AIBudgetBypassGrantRestore`) and recordings of booked costs (`startRecordingBookedCosts()`, `AIUsageBookedCosts`
  with one `AIUsageScopeBookedCost` per usage scope). The base keeps everything in memory; an application persists
  envelopes by overriding `persistEnvelopeOpening()` / `persistEnvelopeClosing()`, and decides `isEnvelopeScope()` /
  `isBookedCostCountedElsewhere()` (config `AI.usageScopesCountedElsewhere`). The service is a worker state resetter:
  a Messenger worker empties it before every consumed job.
- `AIUsageEnvelopeContext`, `AIUsageInitiator`, the `WithAIEnvelope` attribute (declares the envelope scope of a class
  or method; the application or a module opens the envelope from it).
- Exceptions: `MissingAIEnvelopeException`, `MissingAIScopeException`, `AIScopeNotEligibleException` (wire code
  `AI_SCOPE_NOT_ELIGIBLE`), `AIModelCallFailedException`, `AIModelCallTransportFailedException`.
- Model catalog: `settings.agentWireApi` (`chatCompletions` / `responses`) and `settings.agentReasoningContext`
  (`all_turns` / `current_turn` / `auto`) on `AILanguageModelSetting`, read through `AIModel::getAgentWireApi()` and
  `getAgentReasoningContext()` (an unknown value is logged and answers the default).

## 3.1.2

- The repository moved to the ddd-framework organisation: github.com/ddd-framework. Links updated; no code changes.

## 3.1.1

- `getLastLoadFailureReason()` keeps the upstream error message when a gateway answers an upstream error with HTTP 2xx
  and an `error` / `message` body (`no parseable answer: <message>`); 3.1.0 returned only `no parseable answer`.

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

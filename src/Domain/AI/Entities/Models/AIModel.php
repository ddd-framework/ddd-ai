<?php

declare (strict_types=1);

namespace DDD\Domain\AI\Entities\Models;

use DDD\Domain\AI\Entities\Models\Benchmarks\AIModelBenchmarks;
use DDD\Domain\AI\Entities\Models\Settings\AIImageModelSetting;
use DDD\Domain\AI\Entities\Models\Settings\AILanguageModelSetting;
use DDD\Domain\AI\Entities\Models\Settings\AIModelAgenticUseCaseConfig;
use DDD\Domain\AI\Entities\Models\Settings\AIModelProviderFilters;
use DDD\Domain\AI\Entities\Models\Settings\AIModelSetting;
use DDD\Domain\AI\Entities\Models\Speed\AIModelSpeedMeasurements;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Domain\AI\Services\AIPromptsService;
use DDD\Domain\Common\Entities\Money\MoneyAmount;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Common\Entities\MediaItems\GenericMediaItem;
use DDD\Domain\Common\Entities\MediaItems\GenericMediaItemContent;
use DDD\Domain\Common\Entities\MediaItems\Photo;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use Throwable;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use Psr\Cache\InvalidArgumentException;
use ReflectionClassConstant;
use ReflectionException;

/**
 * @method static AIModelsService getService()
 */
class AIModel extends Entity
{
    use ChangeHistoryTrait;

    /** @var string Large Language Models */
    public const string TYPE_LANGUAGE = 'LANGUAGE';

    /** @var string Image Models */
    public const string TYPE_IMAGE = 'IMAGE';

    /** @var string Audio Models */
    public const string TYPE_AUDIO = 'AUDIO';

    /** @var string Embeddings Models */
    public const string TYPE_EMBEDDINGS = 'EMBEDDINGS';

    /** @var string Model Vendor OpenAI */
    public const string VENDOR_OPENAI = 'OPENAI';

    /** @var string Model Vendor Google */
    public const string VENDOR_GOOGLE = 'GOOGLE';

    /** @var string Model Vendor Meta */
    public const string VENDOR_META = 'META';

    /** @var string Model Vendor Black Forest Labs */
    public const string VENDOR_BLACK_FOREST_LABS = 'BLACK_FOREST_LABS';

    /** @var string Model Vendor FALAI */
    public const string VENDOR_FALAI = 'FALAI';

    /** @var string Model Vendor Perplexity */
    public const string VENDOR_PERPLEXITY = 'PERPLEXITY';

    /** @var string Model Vendor Anthropic */
    public const string VENDOR_ANTHROPIC = 'ANTHROPIC';

    /** @var string Model Vendor xAI (Grok) */
    public const string VENDOR_XAI = 'XAI';

    /** @var string Model Vendor MiniMax (China; served via Western OpenRouter providers) */
    public const string VENDOR_MINIMAX = 'MINIMAX';

    /** @var string Model Vendor Moonshot AI / Kimi (China; served via Western OpenRouter providers) */
    public const string VENDOR_MOONSHOT = 'MOONSHOT';

    /** @var string Model Vendor Alibaba / Qwen (China; served via Western OpenRouter providers) */
    public const string VENDOR_ALIBABA = 'ALIBABA';

    /** @var string Model Vendor Z.ai / Zhipu AI / GLM (China; served via Western OpenRouter providers) */
    public const string VENDOR_ZAI = 'ZAI';

    /** @var string Model Vendor TypeSafe AI — System One DECISION models (Jev): typed answers with probabilities, no text */
    public const string VENDOR_TYPESAFE = 'TYPESAFE';

    /**
     * @var string Reasoning effort: none (fastest, minimal thinking)
     * @description Use when you want maximum speed and minimal reasoning.
     */
    public const string REASONING_EFFORT_NONE = 'none';

    /**
     * @var string Reasoning effort: minimal (faster than low, almost no thinking)
     * @description Use when you want near-no-reasoning behavior but still slightly more than NONE.
     */
    public const string REASONING_EFFORT_MINIMAL = 'minimal';

    /**
     * @var string Reasoning effort: low (fast, light reasoning)
     * @description Use for fast one-shot answers with some light reasoning.
     */
    public const string REASONING_EFFORT_LOW = 'low';

    /**
     * @var string Reasoning effort: medium (balanced)
     * @description Default-like balance between speed and reasoning quality.
     */
    public const string REASONING_EFFORT_MEDIUM = 'medium';

    /**
     * @var string Reasoning effort: high (slower, deeper reasoning)
     * @description Use for complex multi-step tasks requiring deeper reasoning.
     */
    public const string REASONING_EFFORT_HIGH = 'high';

    /**
     * @var string Reasoning effort: xhigh (slowest, maximum reasoning)
     * @description Use for hardest reasoning tasks; highest latency.
     */
    public const string REASONING_EFFORT_XHIGH = 'xhigh';

    /**
     * @var string OpenAI GPT-4o Model
     * @description Advanced multimodal model with vision and text capabilities. Balances performance and cost for diverse tasks.
     * @usage Ideal for tasks requiring image processing (e.g., analyzing charts, OCR) or complex text generation (e.g., summarization, translation). Suitable for most modern applications.
     * @notes Training cutoff: October 2024. Supports vision and basic reasoning. Cost-efficient compared to GPT-4, with 128K context length.
     */
    public const string MODEL_OPENAI_GPT4_O = 'OPENAI.GPT4_O';

    /**
     * @var string OpenAI GPT-4o Mini Model
     * @description Lightweight, cost-efficient multimodal model with vision support. Optimized for high-throughput, simple tasks.
     * @usage Use for cost-sensitive applications like chatbots, quick text processing, or lightweight image analysis. Best for high-volume, low-complexity tasks.
     * @notes Training cutoff: October 2024. 128K context, vision-enabled. Extremely low cost makes it ideal for scaling.
     */
    public const string MODEL_OPENAI_GPT4_O_MINI = 'OPENAI.GPT4_O_MINI';

    /**
     * @var string OpenAI GPT-5 Model
     * @description Flagship multimodal model with advanced reasoning and vision. Designed for complex, high-stakes tasks.
     * @usage Use for demanding tasks like scientific analysis, multi-step reasoning, or large-scale multimodal processing (e.g., video/text analysis). High context length (272K input).
     * @notes Training cutoff: June 2025. Supports advanced reasoning and vision. Higher cost, but powerful for enterprise-grade applications.
     */
    public const string MODEL_OPENAI_GPT5 = 'OPENAI.GPT5';

    /**
     * @var string OpenAI GPT-5 Mini Model
     * @description Optimized version of GPT-5 with similar capabilities but lower cost. Multimodal with strong reasoning.
     * @usage Use for cost-efficient complex tasks requiring reasoning or vision, such as automated code review or detailed image analysis. Good balance of power and cost.
     * @notes Training cutoff: June 2025. 272K context, vision and reasoning enabled. More affordable than GPT-5.
     */
    public const string MODEL_OPENAI_GPT5_MINI = 'OPENAI.GPT5_MINI';

    /**
     * @var string OpenAI GPT-5 Nano Model
     * @description Ultra-lightweight GPT-5 variant for high-throughput, multimodal tasks with minimal cost.
     * @usage Use for high-volume tasks like real-time chat, lightweight vision tasks, or simple reasoning. Ideal for scaling with budget constraints.
     * @notes Training cutoff: June 2025. 272K context, vision and reasoning enabled. Cheapest in GPT-5 series.
     */
    public const string MODEL_OPENAI_GPT5_NANO = 'OPENAI.GPT5_NANO';

    /**
     * @var string OpenAI GPT-4.1 Model
     * @description Long-context multimodal model with improved performance over GPT-4o. Focused on large-scale text and vision tasks.
     * @usage Use for tasks requiring massive context (1M tokens), like document analysis, codebases, or image-based data extraction. Not optimized for reasoning.
     * @notes Training cutoff: October 2024. 1M context, vision-enabled, no advanced reasoning. Cost-efficient for large inputs.
     */
    public const string MODEL_OPENAI_GPT4_1 = 'OPENAI.GPT4_1';

    /**
     * @var string OpenAI GPT-4.1 Mini Model
     * @description Smaller version of GPT-4.1 with long context and vision, optimized for efficiency.
     * @usage Use for large-context tasks (e.g., summarizing long documents, analyzing images) where cost is a concern but high context is needed.
     * @notes Training cutoff: October 2024. 1M context, vision-enabled, no advanced reasoning. More affordable than GPT-4.1.
     */
    public const string MODEL_OPENAI_GPT4_1_MINI = 'OPENAI.GPT4_1_MINI';

    /**
     * @var string OpenAI GPT-4.1 Nano Model
     * @description Ultra-efficient GPT-4.1 variant for large-context, low-cost tasks with vision support.
     * @usage Use for high-throughput tasks with large inputs (e.g., batch processing of documents or images). Ideal for cost-sensitive, large-scale applications.
     * @notes Training cutoff: October 2024. 1M context, vision-enabled, no advanced reasoning. Cheapest in GPT-4.1 series.
     */
    public const string MODEL_OPENAI_GPT4_1_NANO = 'OPENAI.GPT4_1_NANO';

    /**
     * @var string OpenAI o3 Model
     * @description Advanced reasoning model with multimodal capabilities, designed for complex problem-solving.
     * @usage Use for tasks requiring deep reasoning, such as mathematical proofs, logical analysis, or multimodal reasoning with images. High context (200K input).
     * @notes Training cutoff: April 2025. 300K total context, vision and advanced reasoning enabled. High compute cost for premium performance.
     */
    public const string MODEL_OPENAI_O3 = 'OPENAI.O3';

    /**
     * @var string OpenAI o3 Mini Model
     * @description Cost-efficient reasoning model with multimodal support, slightly less powerful than o3.
     * @usage Use for reasoning-heavy tasks (e.g., STEM problems, code debugging) or vision-based reasoning where cost is a factor. High context (200K input).
     * @notes Training cutoff: April 2025. 300K total context, vision and advanced reasoning enabled. More affordable than o3.
     */
    public const string MODEL_OPENAI_O3_MINI = 'OPENAI.O3_MINI';

    /**
     * @var string OpenAI o4 Mini Model
     * @description Lightweight reasoning model with multimodal support, optimized for speed and cost.
     * @usage Use for fast, reasoning-based tasks with vision (e.g., quick data analysis, image-based queries) in cost-sensitive applications. High context (200K input).
     * @notes Training cutoff: April 2025. 300K total context, vision and advanced reasoning enabled. Cheapest in o-series.
     */
    public const string MODEL_OPENAI_O4_MINI = 'OPENAI.O4_MINI';

    /**
     * @var string OpenAI GPT-OSS-120B Model
     * @description Open-weight 117B MoE model (5.1B active) for high-reasoning tasks. Optimized for single H100 GPU with MXFP4 quantization. Supports function calling and structured outputs.
     * @usage Use for agentic workflows, complex reasoning, or structured data tasks requiring high performance. Ideal for production-grade applications with 131K context.
     * @notes Training cutoff: ~2025. Text-only, Apache 2.0 license. Extremely cost-efficient via OpenRouter.
     */
    public const string MODEL_OPENAI_GPT_OSS_120B = 'OPENAI.GPT_OSS_120B';

    /**
     * @var string OpenAI GPT-OSS-20B Model
     * @description Open-weight 21B MoE model (3.6B active) for low-latency inference. Deployable on consumer hardware, supports reasoning and function calling.
     * @usage Use for cost-efficient reasoning, fine-tuning, or agentic tasks like chatbots or tool use. Suitable for lightweight setups with 131K context.
     * @notes Training cutoff: ~2025. Text-only, Apache 2.0 license. Ultra-low cost via OpenRouter.
     */
    public const string MODEL_OPENAI_GPT_OSS_20B = 'OPENAI.GPT_OSS_20B';

    /**
     * @var string OpenAI GPT-5.4 Model
     * @description Current GPT-5.4 flagship model.
     */
    public const string MODEL_OPENAI_GPT5_4 = 'OPENAI.GPT5_4';

    /**
     * @var string OpenAI GPT-5.4 Pro Model
     * @description Highest-performance GPT-5.4 Pro variant.
     */
    public const string MODEL_OPENAI_GPT5_4_PRO = 'OPENAI.GPT5_4_PRO';

    /**
     * @var string OpenAI GPT-5.4 Mini Model
     * @description Fast, cost-efficient GPT-5.4 variant close to flagship performance. Ideal for coding assistants and subagents.
     * @usage Use for coding assistance, subagent orchestration, or complex tasks where speed and cost matter. 400K context (272K input, 128K output).
     * @notes Training cutoff: August 2025. Reasoning model with vision. $0.75/$4.50 per 1M tokens.
     */
    public const string MODEL_OPENAI_GPT5_4_MINI = 'OPENAI.GPT5_4_MINI';

    /**
     * @var string OpenAI GPT-5.4 Nano Model
     * @description Smallest, cheapest GPT-5.4 variant (API-only). Best for classification, extraction, ranking, and subagents.
     * @usage Use for high-volume classification, data extraction, ranking, or lightweight subagent tasks. 400K context (272K input, 128K output).
     * @notes Training cutoff: August 2025. Reasoning model with vision. $0.20/$1.25 per 1M tokens.
     */
    public const string MODEL_OPENAI_GPT5_4_NANO = 'OPENAI.GPT5_4_NANO';

    /**
     * @var string OpenAI text-embedding-3-small
     * @description Efficient embeddings model for semantic search / RAG.
     */
    public const string MODEL_OPENAI_TEXT_EMBEDDING_3_SMALL = 'OPENAI.TEXT_EMBEDDING_3_SMALL';

    /**
     * @var string OpenAI text-embedding-3-large
     * @description Highest-quality embeddings model for retrieval.
     */
    public const string MODEL_OPENAI_TEXT_EMBEDDING_3_LARGE = 'OPENAI.TEXT_EMBEDDING_3_LARGE';

    /**
     * @var string OpenAI GPT-4o Mini Transcribe
     * @description Cost-efficient speech-to-text model.
     */
    public const string MODEL_OPENAI_GPT4_O_MINI_TRANSCRIBE = 'OPENAI.GPT4_O_MINI_TRANSCRIBE';

    /**
     * @var string OpenAI GPT-4o Transcribe
     * @description Higher-accuracy speech-to-text model.
     */
    public const string MODEL_OPENAI_GPT4_O_TRANSCRIBE = 'OPENAI.GPT4_O_TRANSCRIBE';

    /**
     * @var string OpenAI GPT-4o Mini TTS
     * @description Text-to-speech model.
     */
    public const string MODEL_OPENAI_GPT4_O_MINI_TTS = 'OPENAI.GPT4_O_MINI_TTS';

    /**
     * @var string Meta Llama 3.1 8B Instruct Model
     * @description Fast, efficient 8B model for text-based reasoning and structured outputs. Competitive with closed-source models, optimized for high-throughput tasks.
     * @usage Use for cost-sensitive applications like chatbots, text analysis, or structured data generation. 131K context, ideal for scalable deployments.
     * @notes Training cutoff: ~2024. Text-only, subject to Meta’s Acceptable Use Policy. Extremely low cost via OpenRouter.
     */
    public const string MODEL_META_LLAMA_3_1_8B_INSTRUCT = 'META.LLAMA_3_1_8B_INSTRUCT';

    /** @var string FluxAI 1.1 Pro Model */
    public const string MODEL_FLUXAI_1_1_PRO = 'FLUXAI.1.1_PRO';

    /** @var string FluxAI 1-Schnell Model */
    public const string MODEL_FLUXAI_1_SCHNELL = 'FLUXAI.1_SCHNELL';

    /** @var string FALAI Model SANA SPRINT */
    public const string MODEL_FALAI_SANA_SPRINT = 'FALAI.SANA.SPRINT';

    /** @var string FALAI MODEL FLUX 1 SCHNELL */
    public const string MODEL_FALAI_FLUX_1_SCHNELL = 'FALAI.FLUX_1_SCHNELL';

    /** @var string FALAI MODEL RUNDIFFUSION JUGGERNAUT FLUX LIGHTNING */
    public const string MODEL_FALAI_RUNDIFFUSION_JUGGERNAUT_FLUX_LIGHTNING = 'FALAI.RUNDIFFUSION_JUGGERNAUT_FLUX_LIGHTNING';

    /** @var string FALAI MODEL SANA V1.5 1.6B */
    public const string MODEL_FALAI_SANA_V1_5_1_6B = 'FALAI.SANA_V1_5_1_6B';

    /** @var string FALAI MODEL SANA V1.5 4.8B */
    public const string MODEL_FALAI_SANA_V1_5_4_8B = 'FALAI.SANA_V1_5_4_8B';

    /** @var string FALAI MODEL LUMA PHOTON */
    public const string MODEL_FALAI_LUMA_PHOTON = 'FALAI.LUMA_PHOTON';

    /** @var string FALAI MODEL FLUX 1 DEV */
    public const string MODEL_FALAI_FLUX_1_DEV = 'FALAI.FLUX_1_DEV';

    /** @var string FALAI MODEL IMAGEN3 FAST */
    public const string MODEL_FALAI_IMAGEN3_FAST = 'FALAI.IMAGEN3_FAST';

    /** @var string FALAI MODEL STABLE DIFFUSION V3 MEDIUM */
    public const string MODEL_FALAI_STABLE_DIFFUSION_V3_MEDIUM = 'FALAI.STABLE_DIFFUSION_V3_MEDIUM';

    /** @var string FALAI MODEL FLUX PRO V1.1 ULTRA */
    public const string MODEL_FALAI_FLUX_PRO_V1_1_ULTRA = 'FALAI.FLUX_PRO_V1_1_ULTRA';

    /** @var string FALAI MODEL RECRAFT V3 TEXT TO IMAGE */
    public const string MODEL_FALAI_RECRAFT_V3_TEXT_TO_IMAGE = 'FALAI.RECRAFT_V3_TEXT_TO_IMAGE';

    /** @var string FALAI MODEL IMAGEN4 PREVIEW */
    public const string MODEL_FALAI_IMAGEN4_PREVIEW = 'FALAI.IMAGEN4_PREVIEW';

    /** @var string FALAI MODEL RUNDIFFUSION PHOTO FLUX */
    public const string MODEL_FALAI_RUNDIFFUSION_PHOTO_FLUX = 'FALAI.RUNDIFFUSION_PHOTO_FLUX';

    /** @var string FALAI MODEL FLUX PRO NEW */
    public const string MODEL_FALAI_FLUX_PRO_NEW = 'FALAI.FLUX_PRO_NEW';

    /** @var string FALAI MODEL DREAMO */
    public const string MODEL_FALAI_DREAMO = 'FALAI.DREAMO';

    /** @var string FALAI MODEL HIDREAM I1 FULL */
    public const string MODEL_FALAI_HIDREAM_I1_FULL = 'FALAI.HIDREAM_I1_FULL';

    /** @var string FALAI MODEL RUNDIFFUSION JUGGERNAUT FLUX PRO */
    public const string MODEL_FALAI_RUNDIFFUSION_JUGGERNAUT_FLUX_PRO = 'FALAI.RUNDIFFUSION_JUGGERNAUT_FLUX_PRO';

    /** @var string FALAI MODEL LUMINA IMAGE V2 */
    public const string MODEL_FALAI_LUMINA_IMAGE_V2 = 'FALAI.LUMINA_IMAGE_V2';

    /** @var string FALAI MODEL FLUX PRO KONTEXT MAX TEXT TO IMAGE */
    public const string MODEL_FALAI_FLUX_PRO_KONTEXT_MAX_TEXT_TO_IMAGE = 'FALAI.FLUX_PRO_KONTEXT_MAX_TEXT_TO_IMAGE';

    /** @var string FALAI MODEL FLUX PRO KONTEXT TEXT TO IMAGE */
    public const string MODEL_FALAI_FLUX_PRO_KONTEXT_TEXT_TO_IMAGE = 'FALAI.FLUX_PRO_KONTEXT_TEXT_TO_IMAGE';

    /** @var string OpenAI DALL-E 2 Image Model */
    public const string MODEL_OPENAI_DALL_E_2 = 'OPENAI.DALL_E_2';

    /** @var string OpenAI DALL-E 3 Image Model */
    public const string MODEL_OPENAI_DALL_E_3 = 'OPENAI.DALL_E_3';

    /** @var string OpenAI GPT Image 1 Image Model */
    public const string MODEL_OPENAI_GPT_IMAGE_1 = 'OPENAI.GPT_IMAGE_1';

    /** @var string Google Gemini 2.5 Flash Image Preview Model */
    public const string MODEL_GOOGLE_GEMINI_2_5_FLASH_IMAGE_PREVIEW = 'GOOGLE.GEMINI_2_5_FLASH_IMAGE_PREVIEW';

    /** @var string Google Gemini 2.5 Pro */
    public const string MODEL_GOOGLE_GEMINI_2_5_PRO = 'GOOGLE.GEMINI_2_5_PRO';

    /** @var string Google Gemini 2.5 Flash */
    public const string MODEL_GOOGLE_GEMINI_2_5_FLASH = 'GOOGLE.GEMINI_2_5_FLASH';

    /** @var string Google Gemini 2.5 Flash Image (non-preview / stable) */
    public const string MODEL_GOOGLE_GEMINI_2_5_FLASH_IMAGE = 'GOOGLE.GEMINI_2_5_FLASH_IMAGE';

    /** @var string Google Gemini 3.0 Flash Preview */
    public const string MODEL_GOOGLE_GEMINI_3_0_FLASH_PREVIEW = 'GOOGLE.GEMINI_3_0_FLASH_PREVIEW';

    /** @var string Google Gemini 3.1 Flash Image Preview */
    public const string MODEL_GOOGLE_GEMINI_3_1_FLASH_IMAGE_PREVIEW = 'GOOGLE.GEMINI_3_1_FLASH_IMAGE_PREVIEW';

    /** @var string Google Gemini 3.1 Flash Lite */
    public const string MODEL_GOOGLE_GEMINI_3_1_FLASH_LITE = 'GOOGLE.GEMINI_3_1_FLASH_LITE';

    /** @var string Google Gemini 3.1 Pro Preview */
    public const string MODEL_GOOGLE_GEMINI_3_1_PRO_PREVIEW = 'GOOGLE.GEMINI_3_1_PRO_PREVIEW';

    /**
     * @var string OpenAI GPT-5 Search API Model
     * @description GPT-5 routed through the Responses API with native `web_search` tool. Use for web-grounded answers with citations.
     */
    public const string MODEL_OPENAI_GPT5_SEARCH_API = 'OPENAI.GPT5_SEARCH_API';

    /**
     * @var string Perplexity Sonar Model
     * @description Perplexity's web-grounded search model with native citations. Returns answers + search results. 128K context.
     */
    public const string MODEL_PERPLEXITY_SONAR = 'PERPLEXITY.SONAR';

    // ===== Anthropic (Claude 4.x) — added 2026-06 =====
    // Opus 4.8 and Sonnet 4.6 were REMOVED in 2.0.0, replaced by Opus 5.5 and Sonnet 5 below. A stored name of
    // either still resolves — see self::RETIRED_MODEL_SUCCESSORS.
    public const string MODEL_ANTHROPIC_CLAUDE_HAIKU_4_5 = 'ANTHROPIC.CLAUDE_HAIKU_4_5';

    // ===== Anthropic (Claude 5 family) — added 2026-09 =====

    /**
     * @var string Anthropic Claude Opus 5.5
     * @description Opus tier of the Claude 5 family (released 2026-09-22): complex reasoning and long-horizon
     *      agentic work. Reasoning + vision, 1M context, 128K output.
     * @usage The Anthropic PREMIUM-tier model. Replaces Opus 4.8, which was REMOVED in 2.0.0 — a stored
     *      'ANTHROPIC.CLAUDE_OPUS_4_8' resolves here via {@see self::RETIRED_MODEL_SUCCESSORS}.
     * @notes $4.00/$20.00 per 1M, cached input read $0.20 — half the Opus 4.8 rate.
     */
    public const string MODEL_ANTHROPIC_CLAUDE_OPUS_5_5 = 'ANTHROPIC.CLAUDE_OPUS_5_5';

    /**
     * @var string Anthropic Claude Sonnet 5
     * @description Sonnet tier of the Claude 5 family: the balance of speed and intelligence. Reasoning + vision,
     *      1M context, 128K output.
     * @usage Replaces Sonnet 4.6, which was REMOVED in 2.0.0 — a stored 'ANTHROPIC.CLAUDE_SONNET_4_6'
     *      resolves here via {@see self::RETIRED_MODEL_SUCCESSORS}.
     * @notes $2.00/$10.00 per 1M, cached input read $0.20.
     */
    public const string MODEL_ANTHROPIC_CLAUDE_SONNET_5 = 'ANTHROPIC.CLAUDE_SONNET_5';

    /**
     * @var string Anthropic Claude Fable 5.1
     * @description The most capable model of the Claude 5 family, for the most demanding reasoning and
     *      long-horizon agentic work. Reasoning + vision, 1M context, 128K output.
     * @notes $10.00/$50.00 per 1M, cached input read $0.25 — the most expensive model in the catalog; pick it
     *      deliberately, not as a default.
     */
    public const string MODEL_ANTHROPIC_CLAUDE_FABLE_5_1 = 'ANTHROPIC.CLAUDE_FABLE_5_1';

    /**
     * @var array A model name that LEFT the catalog, mapped to the successor its stored occurrences resolve to.
     *
     * Model names are persisted — in conversations, messages, compaction nodes, usage logs. Removing a model from
     * the catalog would make every stored row unresolvable ({@see \DDD\Domain\AI\Services\AIModelsService::getAIModelByName()}
     * returns null, or throws with throwErrors), so continuing an old conversation or opening an admin view of an
     * old row would fail. The resolution follows this map instead, and marks the result: the returned model carries
     * {@see self::$resolvedFromRetiredModelName}, so a record of what ran can still say WHICH model ran and never
     * claims the successor did.
     *
     * COSTS are not re-derived from this map. Every cost in this package is computed from a LIVE model at call
     * time and persisted as an amount by the consumer; nothing re-prices a stored row. A retired model's prices
     * are therefore gone with its entry — if a consumer ever needs to re-price history, it must keep its own
     * record of the rate it paid, not read today's catalog.
     */
    public const array RETIRED_MODEL_SUCCESSORS = [
        'ANTHROPIC.CLAUDE_OPUS_4_8' => self::MODEL_ANTHROPIC_CLAUDE_OPUS_5_5,
        'ANTHROPIC.CLAUDE_SONNET_4_6' => self::MODEL_ANTHROPIC_CLAUDE_SONNET_5,
    ];

    // ===== xAI (Grok) — added 2026-06 =====
    public const string MODEL_XAI_GROK_4_3 = 'XAI.GROK_4_3';

    public const string MODEL_XAI_GROK_4_20 = 'XAI.GROK_4_20';

    public const string MODEL_XAI_GROK_4_1_FAST = 'XAI.GROK_4_1_FAST';

    // ===== Newer OpenAI / Google — added 2026-06 =====
    public const string MODEL_OPENAI_GPT5_5 = 'OPENAI.GPT5_5';

    public const string MODEL_OPENAI_GPT5_5_PRO = 'OPENAI.GPT5_5_PRO';

    // ===== OpenAI GPT-5.6 family (Sol / Terra / Luna) — added 2026-08 =====

    /**
     * @var string OpenAI GPT-5.6 Luna Model
     * @description Budget tier of the GPT-5.6 family (GA 2026-07-09). Reasoning + vision, 1.05M context.
     * @usage Use for high-volume chat, classification and agentic tool-calling where cost per turn matters.
     * @notes Reasoning model. $0.10/$0.60 per 1M via OpenRouter (cached input $0.01) — see the config entry.
     */
    public const string MODEL_OPENAI_GPT5_6_LUNA = 'OPENAI.GPT5_6_LUNA';

    // ===== OpenAI GPT-6 family (Sol / Luna) — added 2026-09 =====

    /**
     * @var string OpenAI GPT-6 Sol Model
     * @description Workhorse tier of the GPT-6 family (released 2026-09-22): complex, repeated professional work —
     *      feature building, code review, debugging, data analysis. Reasoning + vision, 1.05M context, 128K output.
     * @usage The PREMIUM-tier OpenAI agent model; sustained agentic tool-calling where quality dominates cost.
     * @notes Reasoning model (effort none…max). $2.00/$10.00 per 1M, cached input $0.20 (OpenRouter = OpenAI list).
     *      Cutoff 2026-04-20. Half the GPT-5.6 Sol price.
     */
    public const string MODEL_OPENAI_GPT6_SOL = 'OPENAI.GPT6_SOL';

    /**
     * @var string OpenAI GPT-6 Luna Model
     * @description Budget tier of the GPT-6 family (released 2026-09-22), successor of GPT-5.6 Luna: high-volume chat,
     *      classification, extraction and agentic tool-calling. Reasoning + vision, 1.05M context, 128K output.
     * @usage The CHEAP-tier OpenAI agent model and the pinned AGENTIC / COMPACTION default since 2026-09-24.
     * @notes Reasoning model (effort none…max). $0.10/$0.50 per 1M, cached input $0.01 (OpenRouter = OpenAI list).
     *      Cutoff 2026-05-18. Same input and cached price as the OpenRouter rate we paid for 5.6 Luna, output −17%;
     *      against OpenAI's 5.6 list price −50% input, −58% output.
     */
    public const string MODEL_OPENAI_GPT6_LUNA = 'OPENAI.GPT6_LUNA';

    public const string MODEL_GOOGLE_GEMINI_3_5_FLASH = 'GOOGLE.GEMINI_3_5_FLASH';

    /**
     * @var string Google Gemini 3.6 Flash Model
     * @description Successor to 3.5 Flash (GA 2026-07-21). Same 1.05M context and price-in, ~17% cheaper output,
     *      ~27% faster, far stronger long-context retrieval. Native parallel function calling.
     * @usage The default STANDARD-tier Google agent model — sustained agentic tool-calling and coding.
     * @notes Reasoning model with vision. $1.50/$7.50 per 1M (cached in $0.15). Cutoff March 2026.
     */
    public const string MODEL_GOOGLE_GEMINI_3_6_FLASH = 'GOOGLE.GEMINI_3_6_FLASH';

    /**
     * @var string Google Gemini 3.8 Flash Model
     * @description The newest Flash model (GA 2026-09-02), successor of 3.7 Flash. Search grounding, function
     *      calling, structured outputs, thinking low/medium/high (NOT minimal). Multimodal in (text, image, video,
     *      audio, PDF), text out. 1M context, 64K output.
     * @notes INTRODUCTORY price $0.75/$3.75 per 1M (cached $0.075) through 2026-12-31; $1.50/$7.50 (cached $0.15)
     *      from 2027-01-01 — the catalog books what is charged TODAY, and a dated test fails when the window ends.
     *      Search grounding is billed separately per query the model runs ($14 per 1,000).
     */
    public const string MODEL_GOOGLE_GEMINI_3_8_FLASH = 'GOOGLE.GEMINI_3_8_FLASH';

    public const string MODEL_GOOGLE_GEMINI_2_5_FLASH_LITE = 'GOOGLE.GEMINI_2_5_FLASH_LITE';

    // ===== Chinese open-weight agentic candidates (served via Western OpenRouter providers) — added 2026-06 =====
    public const string MODEL_MINIMAX_M3 = 'MINIMAX.M3';

    public const string MODEL_ALIBABA_QWEN3_235B_INSTRUCT = 'ALIBABA.QWEN3_235B_INSTRUCT';

    public const string MODEL_MOONSHOT_KIMI_K2 = 'MOONSHOT.KIMI_K2';

    public const string MODEL_MOONSHOT_KIMI_K3 = 'MOONSHOT.KIMI_K3';

    public const string MODEL_ZAI_GLM_5_2 = 'ZAI.GLM_5_2';

    /**
     * @var string TypeSafe Jev 1.13 — a System One DECISION model (choice/score/noul with probabilities, never text),
     * served by OpenRouter's `/api/v1/systemone` route. Pinned to the exact version on purpose: a decision model's
     * probabilities may drift between versions.
     */
    public const string MODEL_TYPESAFE_JEV_1_13 = 'TYPESAFE.JEV_1_13';

    /** @var string The type of the Model */
    #[Choice(callback: [self::class, 'getModelTypes'])]
    public string $type;

    /** @var string The vendor of the Model */
    #[Choice(callback: [self::class, 'getModelVendors'])]
    public string $vendor;

    /**
     * @var string|null Set when this model was reached through a RETIRED name ({@see self::RETIRED_MODEL_SUCCESSORS}):
     * it holds the retired name that was asked for. Null on every normal lookup. Read it before writing a record of
     * what ran — the object describes the SUCCESSOR, not the model that produced the stored row.
     */
    #[HideProperty]
    public ?string $resolvedFromRetiredModelName = null;

    /** @var string The name of the Model */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_UNIQUE)]
    #[Choice(callback: [self::class, 'getModelNames'])]
    public string $name;

    /** @var string Description of the model and its capabilities */
    public ?string $description;

    /** @var string The external id of the model at the AI service provider */
    public string $externalId;

    /** @var string|null The external id of the model on OpenRouter (if available) */
    public ?string $openRouterExternalId = null;

    /** @var bool|null If true, model is a reasoning model, applicable only to Text models */
    public ?bool $isReasoningModel = false;

    /** @var bool|null If true, model accepts multi-modal input and can process images */
    public ?bool $hasVisionCapabilities = false;

    /**
     * @var bool If true, the model performs reliable NATIVE function/tool-calling through the OpenAI Chat-Completions
     *      egress (the agent loop's transport). Defaults true. Set false for models whose tool-call format does NOT
     *      survive that egress — e.g. gpt-oss (harmony channel markers like `<|channel|>commentary` leak into the
     *      tool name and every call fails). Used by agentic model selection (AIModelsService) to exclude such models
     *      from the tool-using loop — orthogonal to the capability score.
     */
    public bool $supportsNativeToolCalling = true;

    /**
     * @var bool If true, the provider offers a FAST (priority-processing) service tier for this model and the egress
     *      may request it per call — OpenAI's `service_tier: "priority"` (OpenRouter passes it through and echoes the
     *      tier served; verified 2026-10-01 for GPT-6 Luna). A caller asks for it per conversation purpose; the
     *      request carries the tier only when this flag is true. Default false: no tier field is sent.
     */
    public bool $supportsFastMode = false;

    /**
     * @var float|null The price factor of the fast tier against the catalog price (OpenAI priority = 2.0 — measured
     *      on the same prompt: $1.8e-6 priority vs $9e-7 default for 9 input tokens). Applied to the ESTIMATED cost
     *      of a call sent in fast mode when the provider reports no cost of its own. Null = the catalog price.
     */
    public ?float $fastModePriceMultiplier = null;

    /** @var string OpenAI GPT-5.6+ style: explicit `prompt_cache_breakpoint` markers on content parts */
    public const string PROMPT_CACHE_MODE_EXPLICIT_BREAKPOINTS = 'explicitBreakpoints';

    /** @var string Automatic prefix caching: the request carries no cache fields */
    public const string PROMPT_CACHE_MODE_AUTOMATIC_PREFIX = 'automaticPrefix';

    /** @var string Anthropic `cache_control` markers */
    public const string PROMPT_CACHE_MODE_ANTHROPIC_CACHE_CONTROL = 'anthropicCacheControl';

    /** @var string No prompt cache the caller can steer: send no cache fields */
    public const string PROMPT_CACHE_MODE_NONE = 'none';

    /** @var string A prompt-cache write with the provider's default lifetime (30 min on OpenAI) */
    public const string PROMPT_CACHE_WRITE_TTL_DEFAULT = 'default';

    /** @var string A prompt-cache write with the 12 h lifetime (`prompt_cache_breakpoint.ttl = 12h`) */
    public const string PROMPT_CACHE_WRITE_TTL_TWELVE_HOURS = '12h';

    /** @var string Cheapest agentic tier — high-volume / simple tool-use. */
    public const string AGENT_TIER_CHEAP = 'CHEAP';

    /** @var string Everyday-default agentic tier — fast, reliable tool-calling, balanced cost. */
    public const string AGENT_TIER_STANDARD = 'STANDARD';

    /** @var string Top agentic tier — hardest multi-step tasks; latency/cost acceptable. */
    public const string AGENT_TIER_PREMIUM = 'PREMIUM';

    /**
     * @var string|null Curated agentic tier this model belongs to (an AGENT_TIER_* value), or null = not classified
     *      for the agent loop (never auto-selected by AIModelsService). Manual classification by human judgement — the
     *      single source of truth for tiered model selection (see AIModelsService). Distinct from the capability score:
     *      a model's tier is a deliberate placement, not a function of its (coverage-skewed) benchmark blend.
     */
    public ?string $agentTier = null;

    /**
     * @var bool Whether the model is eligible for the agent loop AT ALL. Defaults true. Set false to HARD-disqualify
     *      a model from any agentic use (AIModelsService never selects it), based on observed agentic-fit failures that
     *      are NOT a tool-call-format issue (that is {@see $supportsNativeToolCalling}) — e.g. a model that
     *      fabricates tool calls / fakes success, or that cannot complete a multi-step tool chain at all. Distinct
     *      from {@see $agentTier} (which only controls *which* tier): an ineligible model is out regardless of tier.
     */
    public bool $agentEligible = true;

    /**
     * @var int|null The model's HARD context window in tokens (vendor capability). Null = unknown. Upper bound for the
     *      compaction threshold (capped at ~80% of this) and for budget math.
     */
    public ?int $contextWindowTokens = null;

    /**
     * @var int|null THE COMPACTION THRESHOLD for this model, in tokens: the working-context size at which compaction
     *      fires for a conversation on this model. ABSOLUTE — not a fraction of the window (context-rot is absolute, so
     *      a bigger window does NOT mean compact later). This one value drives the whole compaction tree shape: the
     *      leaf-segment size, the raw verbatim tail and the summary budget are all DERIVED from it (by the consumer's
     *      compaction-budget calculation). It is the
     *      model's reliable-reasoning length (RULER/MRCR/context-rot grounded), well below the hard window. A
     *      per-conversation `CompactionSettings::$triggerTokens` overrides it; null = the global default applies.
     */
    public ?int $effectiveContextTokens = null;

    /**
     * @var AIModelAgenticUseCaseConfig|null Per-model settings for the AGENTIC use case (reasoning effort, temperature),
     *      applied only when the agentic egress opts in. Rides with the AIModelsService's dynamic selection; null = no
     *      agentic-specific overrides (provider defaults). See {@see AIModelAgenticUseCaseConfig}.
     */
    public ?AIModelAgenticUseCaseConfig $agenticUseCase = null;

    /**
     * @var AIModelProviderFilters|null Per-model OpenRouter PROVIDER-ROUTING filter (order / ignore / allow_fallbacks),
     *      applied to the `provider` block on the OpenRouter / LiteLLM-proxy egress when present — composes with and
     *      takes precedence over the dynamic sort/require_parameters. Pins a model to a specific upstream provider when
     *      it leaks/misbehaves on another (canonical case: Qwen3-235B → DeepInfra, never Google). null = OpenRouter
     *      picks freely. See {@see AIModelProviderFilters}.
     */
    public ?AIModelProviderFilters $providerFilters = null;

    /**
     * @var AILanguageModelSetting|AIImageModelSetting The Settings of the Model.
     *      Hydrated as AILanguageModelSetting for TYPE_LANGUAGE / TYPE_AUDIO / TYPE_EMBEDDINGS,
     *      and as AIImageModelSetting for TYPE_IMAGE.
     */
    public AILanguageModelSetting|AIImageModelSetting $settings;

    /**
     * @var AIModelBenchmarks|null Agentic-capability benchmark evidence (BFCL, τ-bench, GAIA, …), stored parallel
     *      to {@see $settings}. Null = unrated. Blend via {@see getAgenticIntelligenceScore()}.
     */
    public ?AIModelBenchmarks $benchmarks = null;

    /**
     * The model's blended 0–100 agentic-intelligence score (how good it is at multi-step, tool-using agent work),
     * derived from its {@see $benchmarks}. Null when the model is unrated. Use to select models by agentic
     * capability (e.g. cheapest model above a threshold for a task tier) — distinct from raw chat quality and cost.
     */
    public function getAgenticIntelligenceScore(): ?int
    {
        return $this->benchmarks?->getAgenticIntelligenceScore();
    }

    /**
     * @var AIModelSpeedMeasurements|null Output-speed evidence (tokens/sec [+ TTFT]) per measurement source
     *      (OpenRouter = our routing path / canonical, Artificial Analysis = cross-check), stored parallel to
     *      {@see $settings} and {@see $benchmarks}. Null = unmeasured. See {@see getTokensPerSecond()} / {@see getSpeedScore()}.
     */
    public ?AIModelSpeedMeasurements $speed = null;

    /**
     * The model's canonical (OpenRouter-preferred) output throughput in tokens/sec, from its {@see $speed}
     * measurements. Null when the model is unmeasured. The raw absolute speed number.
     */
    public function getTokensPerSecond(): ?float
    {
        return $this->speed?->getTokensPerSecond();
    }

    /**
     * The model's normalised 0–100 speed score derived from its canonical tokens/sec (see
     * {@see AIModelSpeedMeasurements::getSpeedScore()}). Null when unmeasured. Use alongside
     * {@see getAgenticIntelligenceScore()} to trade off capability vs latency when selecting a model.
     */
    public function getSpeedScore(): ?int
    {
        return $this->speed?->getSpeedScore();
    }

    /**
     * Return all model types based on class constants
     * @return array
     * @throws ReflectionException
     */
    public static function getModelTypes()
    {
        $reflectionClass = static::getReflectionClass();
        $constants = $reflectionClass->getConstants(ReflectionClassConstant::IS_PUBLIC);
        $modelConstants = [];

        foreach ($constants as $name => $value) {
            if (strpos($name, 'TYPE_') === 0) {
                $modelConstants[] = $value;
            }
        }

        return $modelConstants;
    }

    /**
     * Return all model vendors based on class constants
     * @return array
     * @throws ReflectionException
     */
    public static function getModelVendors()
    {
        $reflectionClass = static::getReflectionClass();
        $constants = $reflectionClass->getConstants(ReflectionClassConstant::IS_PUBLIC);
        $modelConstants = [];

        foreach ($constants as $name => $value) {
            if (strpos($name, 'VENDOR_') === 0) {
                $modelConstants[] = $value;
            }
        }

        return $modelConstants;
    }

    /**
     * Returns all model names based on class constants
     * @return array
     * @throws ReflectionException
     */
    public static function getModelNames()
    {
        $reflectionClass = static::getReflectionClass();
        $constants = $reflectionClass->getConstants(ReflectionClassConstant::IS_PUBLIC);
        $modelConstants = [];

        foreach ($constants as $name => $value) {
            if (strpos($name, 'MODEL_') === 0) {
                $modelConstants[] = $value;
            }
        }

        return $modelConstants;
    }

    /**
     * @return string
     */
    public function uniqueKey(): string
    {
        $key = $this->id ?? null;
        if (!$key) {
            $key = $this->name;
        }
        return self::uniqueKeyStatic($key);
    }

    /**
     * Calculates and returns estimated costs for given Prompt
     *
     * @param AIPrompt $aiPrompt
     * @param string|array|null $userContent
     * @return MoneyAmount|null
     */
    public function getEstimatedCostsForPrompt(AIPrompt &$aiPrompt, string|array|null $userContent = null): ?MoneyAmount
    {
        $inputTokens = $aiPrompt->getEstimatedInputTokens();
        if ($userContent) {
            $userContentString = '';
            if (is_array($userContent)) {
                foreach ($userContent as $element) {
                    if (isset($element['type']) && $element['type'] == 'text' && isset($element['text'])) {
                        $userContentString .= $element['text'];
                    } elseif (isset($element['type']) && $element['type'] == 'image_url' && isset($element['image_url']['image'])) {
                        /** @var Photo $photo */
                        $photo = $element['image_url']['image'];
                        /** @var GenericMediaItemContent $mediaItemContent */
                        $mediaItemContent = $photo->mediaItemContent;

                        // Calculate costs for image based on OpenAI's guidelines
                        $detail = $element['detail'] ?? 'auto'; // Assume 'low' detail if not specified
                        $inputTokens += $this->calculateImageTokenCost($photo, $detail);
                    } elseif (isset($element['role']) && isset($element['content']) && is_array($element['content'])) {
                        // Agent path: userContent is a {role, content[]} messages array, so image parts are
                        // NESTED in the message content — the top-level branches above never see them and an
                        // image-bearing turn is priced at ZERO. Descend one level and price
                        // every image part at the MEASURED per-image cost (a large floor that barely scales
                        // with pixels; arithmetic on dimensions buys nothing at the small end).
                        foreach ($element['content'] as $contentPart) {
                            if (!is_array($contentPart)) {
                                continue;
                            }
                            if (($contentPart['type'] ?? null) === 'text' && isset($contentPart['text'])) {
                                $userContentString .= $contentPart['text'];
                            } elseif (($contentPart['type'] ?? null) === 'image_url') {
                                $inputTokens += $this->getEstimatedImageInputTokens();
                            }
                        }
                    }
                }
            } else {
                $userContentString = $userContent;
            }
            $inputTokens += AIPromptsService::getTokenCountForString($userContentString);
        }
        // we assume output tokens will costs estimat
        $outputTokens = $aiPrompt->getEstimatedOuputTokens();
        return $this->getEstimatedCostsForTokens($inputTokens, $outputTokens);
    }


    /**
     * Calculate token count for a given Photo/GenericMediaItem object based on detail level
     *
     * @param Photo|GenericMediaItem $photo The media item to calculate cost for
     * @param string $detail The detail level ('low', 'high', or 'auto')
     * @return int The token count for the given media item and detail level
     */
    public function calculateImageTokenCost(Photo|GenericMediaItem $photo, string $detail = 'auto'): int
    {
        /** @var GenericMediaItemContent $mediaItemContent */
        if (!isset($photo->mediaItemContent)) {
            return 0;
        }
        $mediaItemContent = $photo->mediaItemContent;
        $width = $mediaItemContent->width;
        $height = $mediaItemContent->height;
        $tokenCount = 0;

        if ($detail === 'low' || ($detail === 'auto' && max($width, $height) <= 512)) {
            $tokenCount = 65; // Fixed cost for low detail images
        } else {
            // For high detail or auto (if image is larger than 512px in any dimension)
            $numSquares = ceil(max($width, $height) / 512);
            $tokenCount = $numSquares * 129; // 129 tokens per 512px square in high detail
        }

        return (int)$tokenCount;
    }

    /**
     * The MEASURED per-image input-token cost for the pre-call budget gate on the agent path, where the image
     * arrives as a resolved wire part with no Photo/dimensions to feed {@see calculateImageTokenCost()}. A
     * probe returned 1,158 vs 69 prompt tokens for one 2×2 PNG in a tool result on Gemini 3.5 Flash — ≈1,089
     * tokens — so the per-image cost has a large floor that barely scales with the picture;
     * pricing per-part at this flat measured value beats dimension arithmetic that undercounts at the small end.
     * Override per model family once each family is probed.
     */
    public function getEstimatedImageInputTokens(): int
    {
        return 1100;
    }

    /** The catalog's prompt-cache mode for this model; {@see self::PROMPT_CACHE_MODE_NONE} when it declares none. */
    public function getPromptCacheMode(): string
    {
        $settings = $this->settings;
        $promptCacheMode = $settings instanceof AILanguageModelSetting ? $settings->promptCacheMode : null;
        return $promptCacheMode ?? self::PROMPT_CACHE_MODE_NONE;
    }

    /** Whether a request for this model may carry explicit `prompt_cache_breakpoint` markers. */
    public function usesExplicitPromptCacheBreakpoints(): bool
    {
        return $this->getPromptCacheMode() === self::PROMPT_CACHE_MODE_EXPLICIT_BREAKPOINTS;
    }

    /** The write price multiple for $writeTtl; null when the catalog declares none (= the plain input rate). */
    public function getPromptCacheWritePriceMultiplier(string $writeTtl = self::PROMPT_CACHE_WRITE_TTL_DEFAULT): ?float
    {
        $settings = $this->settings;
        if (!($settings instanceof AILanguageModelSetting) || $settings->promptCacheWritePrices === null) {
            return null;
        }
        return $settings->promptCacheWritePrices->getPriceMultiplierForTtl($writeTtl);
    }

    /**
     * Calculates and returns estimated costs for given in and output token count.
     *
     * Supports optional cached-token discounts, per-web-search-call flat fees,
     * tiered pricing (above/below the inputTierThresholdTokens threshold), and
     * per-request variant fees (e.g. search context size for Perplexity Sonar).
     *
     * When $cachedInputTokens > 0 and the model has a cached rate configured,
     * the input cost is split: cached tokens at the reduced rate, remaining at full rate.
     * The $webSearchCallCount fee is added on top when the model has a web search fee.
     * $cacheWriteTokens are part of the uncached tokens and cost the input rate times the model's write multiple for
     * $cacheWriteTtl ({@see getPromptCacheWritePriceMultiplier()}; the plain rate when it declares none).
     *
     * @param int $promptTokens Total prompt tokens (including any cached portion)
     * @param int $outputTokens Completion tokens
     * @param string|null $pricingVariant Optional pricing variant key (e.g. 'search_context_size.medium')
     * @param int $cachedInputTokens Tokens served from prompt cache (default 0)
     * @param int $webSearchCallCount Number of web_search_call tool invocations (default 0)
     * @param int $cacheWriteTokens Uncached tokens written to the prompt cache (default 0)
     * @param string $cacheWriteTtl The lifetime of those writes (default {@see self::PROMPT_CACHE_WRITE_TTL_DEFAULT})
     * @return MoneyAmount|null Estimated cost, or null when rates are not configured
     */
    public function getEstimatedCostsForTokens(
        int $promptTokens,
        int $outputTokens,
        ?string $pricingVariant = null,
        int $cachedInputTokens = 0,
        int $webSearchCallCount = 0,
        int $cacheWriteTokens = 0,
        string $cacheWriteTtl = self::PROMPT_CACHE_WRITE_TTL_DEFAULT,
    ): ?MoneyAmount {
        $amount = $this->calculateEstimatedCostAmount(
            $promptTokens,
            $outputTokens,
            $pricingVariant,
            $cachedInputTokens,
            $webSearchCallCount,
            $cacheWriteTokens,
            $cacheWriteTtl,
        );
        if ($amount === null) {
            return null;
        }
        return new MoneyAmount($amount, 'USD');
    }

    /**
     * Returns the estimated cost in USD as a plain float, without constructing a MoneyAmount.
     *
     * Useful for unit tests that cannot instantiate MoneyAmount via its constructor
     * (DDD container not available). All calculation logic lives here; the public
     * getEstimatedCostsForTokens() wraps this in a MoneyAmount.
     *
     * @param int $promptTokens Total prompt tokens (including any cached portion)
     * @param int $outputTokens Completion tokens
     * @param string|null $pricingVariant Optional pricing variant key
     * @param int $cachedInputTokens Tokens served from prompt cache (default 0)
     * @param int $webSearchCallCount Number of web_search_call tool invocations (default 0)
     * @param int $cacheWriteTokens Uncached tokens written to the prompt cache (default 0)
     * @param string $cacheWriteTtl The lifetime of those writes
     * @return float|null Estimated cost in USD, or null when rates are not configured
     */
    public function calculateEstimatedCostAmount(
        int $promptTokens,
        int $outputTokens,
        ?string $pricingVariant = null,
        int $cachedInputTokens = 0,
        int $webSearchCallCount = 0,
        int $cacheWriteTokens = 0,
        string $cacheWriteTtl = self::PROMPT_CACHE_WRITE_TTL_DEFAULT,
    ): ?float {
        $settings = $this->settings;

        // Image-only settings have no token rates; nothing to estimate token-wise.
        if (!($settings instanceof AILanguageModelSetting)) {
            return null;
        }

        $hasTierInput = isset(
            $settings->inputTierThresholdTokens,
            $settings->costsPer1000KInputTokens,
            $settings->costsPer1000InputTokensAboveThreshold,
        );
        $hasTierOutput = isset(
            $settings->inputTierThresholdTokens,
            $settings->costsPer1000KOutputTokens,
            $settings->costsPer1000OutputTokensAboveThreshold,
        );
        $hasTiers = $hasTierInput && $hasTierOutput;

        if ($hasTiers) {
            $isAbove = $promptTokens > $settings->inputTierThresholdTokens;

            $fullInputRate = $isAbove
                ? $settings->costsPer1000InputTokensAboveThreshold->amount
                : $settings->costsPer1000KInputTokens->amount;

            $outputRate = $isAbove
                ? $settings->costsPer1000OutputTokensAboveThreshold->amount
                : $settings->costsPer1000KOutputTokens->amount;

            $inputCost = $this->calculateInputCost(
                $settings, $promptTokens, $cachedInputTokens, $fullInputRate, $cacheWriteTokens, $cacheWriteTtl
            );
            $totalCosts = $inputCost + ($outputTokens / 1000) * $outputRate;
            $totalCosts += $this->getVariantFeeAmount($settings, $pricingVariant);
            $totalCosts += $this->getWebSearchFeeAmount($settings, $webSearchCallCount);
            return $totalCosts;
        }

        if (!($settings->costsPer1000KInputTokens ?? null) || !($settings->costsPer1000KOutputTokens ?? null)) {
            return null;
        }

        $fullInputRate = $settings->costsPer1000KInputTokens->amount;
        $inputCost = $this->calculateInputCost(
            $settings, $promptTokens, $cachedInputTokens, $fullInputRate, $cacheWriteTokens, $cacheWriteTtl
        );
        $totalCosts = $inputCost + ($outputTokens / 1000) * $settings->costsPer1000KOutputTokens->amount;
        $totalCosts += $this->getVariantFeeAmount($settings, $pricingVariant);
        $totalCosts += $this->getWebSearchFeeAmount($settings, $webSearchCallCount);
        return $totalCosts;
    }

    /**
     * Calculates input token cost, applying cached token discount when available and the write premium on the
     * uncached tokens written to the prompt cache.
     *
     * @param AILanguageModelSetting $settings Model settings
     * @param int $promptTokens Total prompt tokens
     * @param int $cachedInputTokens Tokens served from cache
     * @param float $fullInputRate Full input rate per 1K tokens
     * @param int $cacheWriteTokens Uncached tokens written to the cache (capped at the uncached tokens)
     * @param string $cacheWriteTtl The lifetime of those writes
     * @return float Input cost in USD
     */
    protected function calculateInputCost(
        AILanguageModelSetting $settings,
        int $promptTokens,
        int $cachedInputTokens,
        float $fullInputRate,
        int $cacheWriteTokens = 0,
        string $cacheWriteTtl = self::PROMPT_CACHE_WRITE_TTL_DEFAULT,
    ): float {
        $cachedCost = 0.0;
        $nonCachedTokens = $promptTokens;
        if (
            $cachedInputTokens > 0
            && $settings->costsPer1000KCachedInputTokens !== null
        ) {
            $cachedRate = $settings->costsPer1000KCachedInputTokens->amount;
            // Guard against API returning cachedInputTokens > promptTokens
            $actualCached = min($cachedInputTokens, $promptTokens);
            $nonCachedTokens = max(0, $promptTokens - $actualCached);
            $cachedCost = ($actualCached / 1000) * $cachedRate;
        }
        $writtenTokens = min(max(0, $cacheWriteTokens), $nonCachedTokens);
        $writePriceMultiplier = $this->getPromptCacheWritePriceMultiplier($cacheWriteTtl) ?? 1.0;
        return (($nonCachedTokens - $writtenTokens) / 1000) * $fullInputRate
            + ($writtenTokens / 1000) * $fullInputRate * $writePriceMultiplier
            + $cachedCost;
    }

    /**
     * Returns the flat web search fee for the given call count.
     *
     * @param AILanguageModelSetting $settings Model settings
     * @param int $webSearchCallCount Number of web search calls
     * @return float Fee in USD (0.0 if not applicable)
     */
    protected function getWebSearchFeeAmount(
        AILanguageModelSetting $settings,
        int $webSearchCallCount,
    ): float {
        if (
            $webSearchCallCount > 0
            && $settings->costsPerWebSearchCall !== null
        ) {
            return $webSearchCallCount * $settings->costsPerWebSearchCall->amount;
        }
        return 0.0;
    }

    /**
     * Returns the per-request variant fee for the given pricing variant.
     *
     * @param AILanguageModelSetting $settings Model settings
     * @param string|null $pricingVariant Pricing variant key (e.g. 'search_context_size.medium')
     * @return float Fee in USD (0.0 if no variant set or variant not configured)
     */
    protected function getVariantFeeAmount(AILanguageModelSetting $settings, ?string $pricingVariant): float
    {
        if ($pricingVariant === null) {
            return 0.0;
        }
        return $settings->getRequestFeeForVariant($pricingVariant)?->amount ?? 0.0;
    }

    /**
     * @return string Removes any ```JSON / HTML etc. markup
     */
    public static function removeProgrammingLanguageMarkup(string $input): string
    {
        // Regular expression to match and optionally remove Markdown code blocks with ```html
        $pattern = '/```[a-zA-Z]+\n?(.*?)```/s';

        // Replace the matched pattern with just the content inside the code block, or the entire text if no code block is present
        $cleanedText = preg_replace($pattern, '$1', $input);
        return $cleanedText;
    }
    // ============================================================
    // Agent egress: wire API and reasoning context per model (catalog `settings` keys)
    // ============================================================

    /** The OpenAI Chat-Completions API (`/chat/completions`). */
    public const string AGENT_WIRE_API_CHAT_COMPLETIONS = 'chatCompletions';

    /** The OpenAI Responses API (`/responses`). */
    public const string AGENT_WIRE_API_RESPONSES = 'responses';

    /** @var string[] The wire APIs a model's catalog entry may name. */
    public const array CATALOG_AGENT_WIRE_APIS = [self::AGENT_WIRE_API_CHAT_COMPLETIONS, self::AGENT_WIRE_API_RESPONSES];

    /** Every reasoning item the request carries is rendered, earlier turns' included. */
    public const string REASONING_CONTEXT_ALL_TURNS = 'all_turns';

    /** Only the reasoning items since the newest user message are rendered. */
    public const string REASONING_CONTEXT_CURRENT_TURN = 'current_turn';

    /** The provider picks per model. */
    public const string REASONING_CONTEXT_AUTO = 'auto';

    /** @var string[] The `reasoning.context` values the OpenAI / OpenRouter APIs accept. */
    public const array REASONING_CONTEXTS = [self::REASONING_CONTEXT_ALL_TURNS, self::REASONING_CONTEXT_CURRENT_TURN, self::REASONING_CONTEXT_AUTO];

    /**
     * The wire API an agent speaks to this model by default: the catalog's `settings.agentWireApi`
     * ({@see AILanguageModelSetting::$agentWireApi}). Absent = {@see self::AGENT_WIRE_API_CHAT_COMPLETIONS}; an unknown
     * value too (logged as a warning).
     *
     * @return string One of {@see self::CATALOG_AGENT_WIRE_APIS}
     */
    public function getAgentWireApi(): string
    {
        $agentWireApi = $this->settings instanceof AILanguageModelSetting ? $this->settings->agentWireApi : null;
        if ($agentWireApi === null) {
            return static::AGENT_WIRE_API_CHAT_COMPLETIONS;
        }
        $canonicalAgentWireApi = static::canonicalAgentWireApi($agentWireApi);
        if ($canonicalAgentWireApi === null) {
            $this->logUnknownAgentSetting('agentWireApi', $agentWireApi, 'the agent speaks Chat Completions');
            return static::AGENT_WIRE_API_CHAT_COMPLETIONS;
        }
        return $canonicalAgentWireApi;
    }

    /**
     * The `reasoning.context` an agent pins for this model: the catalog's `settings.agentReasoningContext`
     * ({@see AILanguageModelSetting::$agentReasoningContext}). Null when the entry names none, or names an unknown
     * value (logged as a warning) — then the request carries no `reasoning.context`.
     *
     * @return string|null One of {@see self::REASONING_CONTEXTS}
     */
    public function getAgentReasoningContext(): ?string
    {
        $agentReasoningContext = $this->settings instanceof AILanguageModelSetting ? $this->settings->agentReasoningContext : null;
        if ($agentReasoningContext === null) {
            return null;
        }
        if (!in_array($agentReasoningContext, static::REASONING_CONTEXTS, true)) {
            $this->logUnknownAgentSetting('agentReasoningContext', $agentReasoningContext, 'the agent sends no reasoning.context');
            return null;
        }
        return $agentReasoningContext;
    }

    /**
     * @param string|null $value `chatCompletions` / `responses` in any case, with surrounding blanks
     * @return string|null The constant of {@see self::CATALOG_AGENT_WIRE_APIS}; null for anything else
     */
    public static function canonicalAgentWireApi(?string $value): ?string
    {
        $normalized = strtolower(trim((string)$value));
        foreach (static::CATALOG_AGENT_WIRE_APIS as $agentWireApi) {
            if ($normalized === strtolower($agentWireApi)) {
                return $agentWireApi;
            }
        }
        return null;
    }

    /**
     * Logs a catalog `settings` value the agent egress does not know. A read without a container still answers.
     *
     * @param string $settingName
     * @param string $settingValue
     * @param string $consequence What the agent does instead
     * @return void
     */
    protected function logUnknownAgentSetting(string $settingName, string $settingValue, string $consequence): void
    {
        try {
            DDDService::instance()->getLogger()->warning(
                '[AIModel] Unknown settings.' . $settingName . ' in the model catalog — ' . $consequence,
                ['model' => $this->name ?? null, $settingName => $settingValue]
            );
        } catch (Throwable) {
        }
    }

    //public static function estimateCost(int $inputTokens, int $ouputTokens, string $model)
}

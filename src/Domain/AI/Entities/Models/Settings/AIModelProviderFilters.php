<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Models\Settings;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Per-model OpenRouter PROVIDER-ROUTING filter, scoped to the OpenRouter / LiteLLM-proxy egress only. When a model
 * entry carries one in the catalog, the OpenRouter egress MERGES it into the request's `provider` block via
 * {@see self::applyToProviderBlock()}, composing with (and taking precedence over) the dynamic
 * `sort`/`require_parameters` the egress adds. Absent → OpenRouter picks the provider freely.
 *
 * Why this exists: some models leak / misbehave on a SPECIFIC upstream provider, so a model must be able to declare
 * "route me to provider X, never fall back". The canonical case is Qwen3-235B-Instruct, whose tool-calls leak as plain
 * text ~30 % of the time on the Google (Vertex) endpoint but 0 % on DeepInfra (measured) — see the catalog comment on
 * that model.
 *
 * Field semantics map 1:1 to OpenRouter's `provider` routing keys:
 *  - `order`          — provider PRIORITY list (lowercase OpenRouter slugs, e.g. `deepinfra`, `google-vertex`). This is
 *                       the one our LiteLLM proxy actually honors (it STRIPS `only`); `order` even overrides the
 *                       proxy's tools→Google default. With a single slug + `allowFallbacks:false` it is a hard pin.
 *  - `ignore`         — providers to skip entirely.
 *  - `allowFallbacks` — false = never silently fall back to a provider outside `order` (a wrong slug then errors with
 *                       OpenRouter "No endpoint", which is the correct loud failure for a misconfigured pin).
 *  - `only`           — the providers allowed at all (OpenRouter slugs). The LiteLLM proxy strips it, so an entry
 *                       that must hold on that egress too also lists the same slugs in `order` with `allowFallbacks`
 *                       false.
 *  - `dataCollection` — `deny` = only providers that do not store or train on the data; null = OpenRouter's default.
 *  - `requireParameters` — true = only providers that support every parameter of the request. Off by default because
 *                       for some models no endpoint qualifies (404); an entry opts in when its allowed hosts do.
 *  - `quantizations`  — the weight precisions allowed (`fp16`, `bf16`, `fp8`, `fp4`, `int8`, … as OpenRouter's
 *                       endpoints API names them; `unknown` for hosts that do not publish one).
 *
 * A host overrides the block of one model in its own `config/app/AI/models.php` (`providerFilters` of that model key).
 */
class AIModelProviderFilters extends ValueObject
{
    /**
     * @var array<int, string> Provider priority list (OpenRouter slugs). Empty = no `order` directive.
     */
    public array $order = [];

    /**
     * @var array<int, string> Providers to skip. Empty = no `ignore` directive.
     */
    public array $ignore = [];

    /**
     * @var bool|null false = no fallback outside `order`; null = leave OpenRouter's default (fallbacks allowed).
     */
    public ?bool $allowFallbacks = null;

    /**
     * @var string[] Providers allowed at all (OpenRouter slugs). Empty = no `only` directive.
     */
    public array $only = [];

    /**
     * @var string|null `deny` or `allow`; null = OpenRouter's default
     */
    public ?string $dataCollection = null;

    /**
     * @var bool|null true = only providers supporting every request parameter; null = not sent
     */
    public ?bool $requireParameters = null;

    /**
     * @var string[] Allowed weight precisions (OpenRouter's names). Empty = no `quantizations` directive.
     */
    public array $quantizations = [];

    public const string DATA_COLLECTION_DENY = 'deny';

    public const string DATA_COLLECTION_ALLOW = 'allow';

    /**
     * @param string[] $order
     * @param string[] $ignore
     * @param string[] $only
     * @param string[] $quantizations
     */
    public function __construct(
        array $order = [],
        array $ignore = [],
        ?bool $allowFallbacks = null,
        array $only = [],
        ?string $dataCollection = null,
        ?bool $requireParameters = null,
        array $quantizations = [],
    ) {
        $this->order = $order;
        $this->ignore = $ignore;
        $this->allowFallbacks = $allowFallbacks;
        $this->only = $only;
        $this->dataCollection = $dataCollection;
        $this->requireParameters = $requireParameters;
        $this->quantizations = $quantizations;
        parent::__construct();
    }

    /**
     * Merge THIS filter's directives into an existing OpenRouter `provider` block (which may already carry
     * `sort`/`require_parameters`). The directives take precedence over `sort` (OpenRouter applies `order` first;
     * verified: `order:[deepinfra]` + `sort:throughput` → DeepInfra). Only non-empty directives are written, so an
     * empty filter is a no-op.
     *
     * @param array<string, mixed> $providerBlock
     * @return array<string, mixed>
     */
    public function applyToProviderBlock(array $providerBlock): array
    {
        if ($this->order !== []) {
            $providerBlock['order'] = $this->order;
        }
        if ($this->ignore !== []) {
            $providerBlock['ignore'] = $this->ignore;
        }
        if ($this->allowFallbacks !== null) {
            $providerBlock['allow_fallbacks'] = $this->allowFallbacks;
        }
        if ($this->only !== []) {
            $providerBlock['only'] = $this->only;
        }
        if ($this->dataCollection !== null) {
            $providerBlock['data_collection'] = $this->dataCollection;
        }
        if ($this->requireParameters !== null) {
            $providerBlock['require_parameters'] = $this->requireParameters;
        }
        if ($this->quantizations !== []) {
            $providerBlock['quantizations'] = $this->quantizations;
        }
        return $providerBlock;
    }

    /**
     * Whether this filter carries any directive at all (else applying it is a no-op).
     */
    public function hasAnyDirective(): bool
    {
        return $this->order !== [] || $this->ignore !== [] || $this->allowFallbacks !== null || $this->only !== []
            || $this->dataCollection !== null || $this->requireParameters !== null || $this->quantizations !== [];
    }
}

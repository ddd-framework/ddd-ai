<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\ModelCalls;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Accounts\Account;

/**
 * One model call as the application's gate and usage recorder see it: which repo class makes it, under which usage
 * scope, with which model and prompt, and who is billed for it.
 *
 * Built fresh by {@see \DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait::buildAIModelCall()} at every stage
 * and never stored: a repo may set the billed account while it builds its content or prompt, and the fallback retry
 * may swap the model, so a stored copy would describe an earlier state of the call.
 */
class AIModelCall extends ValueObject
{
    /** @var string The class of the Argus repo making the call */
    public string $argusClass;

    /** @var string|null The usage scope declared on the repo's attribute (or set at runtime) */
    public ?string $aiUsageScope = null;

    /** @var AIModel|null The resolved model; null before the model is resolved (the first gate stage) */
    public ?AIModel $aiModel = null;

    /** @var AIPrompt|null The registry prompt of the call (not the prompt with its parameters applied) */
    public ?AIPrompt $aiPrompt = null;

    /** @var Account|null The account billed for the call, as set on the repo instance */
    public ?Account $accountBilledForAICosts = null;

    /** @var Entity|null An entity within that account the costs are attributed to, as set on the repo instance */
    public ?Entity $entityWithinAccountBilledForAICosts = null;

    /** @var bool Whether the repo's attribute asks for the budget check */
    public bool $enforceBudget = true;

    /** @var string|null A name of the prompt variant in use, for the usage record; filled only for the recorder */
    public ?string $promptVariantName = null;

    /** @var bool True when reading the prompt variant name for the usage record failed */
    public bool $promptVariantNameFailed = false;

    public function __construct(
        string $argusClass,
        ?string $aiUsageScope = null,
        ?AIModel $aiModel = null,
        ?AIPrompt $aiPrompt = null,
        ?Account $accountBilledForAICosts = null,
        ?Entity $entityWithinAccountBilledForAICosts = null,
        bool $enforceBudget = true,
        ?string $promptVariantName = null,
        bool $promptVariantNameFailed = false,
    ) {
        $this->argusClass = $argusClass;
        $this->aiUsageScope = $aiUsageScope;
        $this->aiModel = $aiModel;
        $this->aiPrompt = $aiPrompt;
        $this->accountBilledForAICosts = $accountBilledForAICosts;
        $this->entityWithinAccountBilledForAICosts = $entityWithinAccountBilledForAICosts;
        $this->enforceBudget = $enforceBudget;
        $this->promptVariantName = $promptVariantName;
        $this->promptVariantNameFailed = $promptVariantNameFailed;
        parent::__construct();
    }
}

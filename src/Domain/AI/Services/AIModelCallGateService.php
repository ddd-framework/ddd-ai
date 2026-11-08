<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\Common\Entities\Money\MoneyAmount;
use DDD\Infrastructure\Services\Service;

/**
 * Decides whether a model call may go out. {@see \DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait} asks it
 * in stages, each of which refuses by throwing; this default lets every call through, so an application without its
 * own rules behaves as before.
 *
 * An application adds its rules (usage-scope requirements, account eligibility, a budget) with a subclass of the same
 * name in its own `App\Domain\AI\Services` namespace; the framework's service lookup returns that subclass.
 *
 * Order for a language model: {@see refuseModelCallWithoutUsageScopeOrEnvelope()} before the model is resolved →
 * {@see refuseModelCallForIneligibleBilledScope()} → when the attribute enforces the budget and
 * {@see needsCostEstimateForBudgetCheck()} answers true: {@see refuseModelCallWithoutBilledAccount()} →
 * {@see loadBudgetSettingsOfBilledAccount()} → the trait's cost estimate → {@see refuseModelCallOverBudget()}.
 * For an image model: {@see refuseModelCallForIneligibleBilledScope()} → when {@see needsCostEstimateForBudgetCheck()}
 * answers true: {@see refuseImageModelCallOverBudget()}.
 */
class AIModelCallGateService extends Service
{
    /**
     * Refuses a call that lacks what the application requires before any model is resolved (e.g. a usage scope, an
     * open usage envelope). Default: nothing required.
     */
    public function refuseModelCallWithoutUsageScopeOrEnvelope(AIModelCall $aiModelCall): void
    {
    }

    /**
     * Refuses a call billed to an account or entity that may not use models (e.g. locked, deleted). Runs after the
     * model is resolved and after the repo's `prepareBilledEntitiesBeforeGates()`. Default: every call is eligible.
     */
    public function refuseModelCallForIneligibleBilledScope(AIModelCall $aiModelCall): void
    {
    }

    /**
     * Whether the budget check runs for this call, which costs a cost estimate first. Default: no budget, so false.
     */
    public function needsCostEstimateForBudgetCheck(AIModelCall $aiModelCall): bool
    {
        return false;
    }

    /**
     * Refuses a budget-checked call that has no account to bill. Default: nothing.
     */
    public function refuseModelCallWithoutBilledAccount(AIModelCall $aiModelCall): void
    {
    }

    /**
     * Loads what the budget decision reads about the billed account, before the cost estimate. Default: nothing.
     */
    public function loadBudgetSettingsOfBilledAccount(AIModelCall $aiModelCall): void
    {
    }

    /**
     * Refuses a language-model call whose estimated cost (null when no model is resolved) does not fit the billed
     * account's budget. Default: nothing.
     */
    public function refuseModelCallOverBudget(AIModelCall $aiModelCall, ?MoneyAmount $estimatedCosts): void
    {
    }

    /**
     * Refuses an image-model call whose estimated cost (null when the model carries no image price) does not fit the
     * billed account's budget. Default: nothing.
     */
    public function refuseImageModelCallOverBudget(AIModelCall $aiModelCall, ?MoneyAmount $estimatedCosts): void
    {
    }
}

<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Support;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\AI\Services\AIModelCallGateService;
use DDD\Domain\Common\Entities\Money\MoneyAmount;

/** A gate that records the stages it was asked, and optionally refuses at one of them */
class RecordingAIModelCallGateService extends AIModelCallGateService
{
    /** @var string[] The stages in the order they were asked */
    public array $askedStages = [];

    public bool $budgetCheckNeeded = false;

    public ?string $refusingStage = null;

    public ?MoneyAmount $lastEstimatedCosts = null;

    protected function recordStage(string $stage): void
    {
        $this->askedStages[] = $stage;
        if ($this->refusingStage === $stage) {
            throw new \RuntimeException('refused at ' . $stage);
        }
    }

    public function refuseModelCallWithoutUsageScopeOrEnvelope(AIModelCall $aiModelCall): void
    {
        $this->recordStage(__FUNCTION__);
    }

    public function refuseModelCallForIneligibleBilledScope(AIModelCall $aiModelCall): void
    {
        $this->recordStage(__FUNCTION__);
    }

    public function needsCostEstimateForBudgetCheck(AIModelCall $aiModelCall): bool
    {
        $this->recordStage(__FUNCTION__);
        return $this->budgetCheckNeeded;
    }

    public function refuseModelCallWithoutBilledAccount(AIModelCall $aiModelCall): void
    {
        $this->recordStage(__FUNCTION__);
    }

    public function loadBudgetSettingsOfBilledAccount(AIModelCall $aiModelCall): void
    {
        $this->recordStage(__FUNCTION__);
    }

    public function refuseModelCallOverBudget(AIModelCall $aiModelCall, MoneyAmount $estimatedCosts): void
    {
        $this->lastEstimatedCosts = $estimatedCosts;
        $this->recordStage(__FUNCTION__);
    }
}

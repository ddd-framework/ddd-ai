<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Support;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait;
use DDD\Domain\AI\Services\AIModelCallGateService;
use DDD\Domain\AI\Services\AIModelCallUsageRecorderService;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;

/**
 * A language-model repo for tests: the task prompt, the user content, the endpoint and the attribute are set per test;
 * the gate and the recorder can be replaced by recording ones.
 */
#[ArgusLoad(loadEndpoint: 'POST:/ai/openRouter/chatCompletions', cacheLevel: ArgusCache::CACHELEVEL_NONE)]
#[ArgusLanguageModel(
    defaultAIModelName: AIModel::MODEL_OPENAI_GPT5_4_MINI,
    temperature: 0.3,
    aiUsageScope: 'TEST.SCOPE',
)]
class ArgusLanguageModelProbe extends ValueObject
{
    use ArgusTrait, ArgusAILanguageModelTrait {
        getAIModelCallGateService as protected getModuleAIModelCallGateService;
        getAIModelCallUsageRecorderService as protected getModuleAIModelCallUsageRecorderService;
    }

    public string|array $probeUserContent = 'Some user text.';

    public string $probeTaskPromptText = 'Classify the text.';

    public ?string $appliedResultText = null;

    public ?AIModelCallGateService $replacedGateService = null;

    public ?AIModelCallUsageRecorderService $replacedUsageRecorderService = null;

    /** Uses another endpoint than the class declares */
    public function useLoadEndpoint(string $loadEndpoint): static
    {
        $this->getArgusSettings()->argusLoad->loadEndpoint = $loadEndpoint;
        return $this;
    }

    /** Uses another attribute than the class declares */
    public function useArgusLanguageModel(ArgusLanguageModel $argusLanguageModel): static
    {
        $this->argusLanguageModel = $argusLanguageModel;
        $this->aiModel = null;
        return $this;
    }

    public function useSystemPrompt(string $systemPromptText): static
    {
        $systemPrompt = new AIPrompt();
        $systemPrompt->name = 'Test.System';
        $systemPrompt->promptText = $systemPromptText;
        $this->aiSystemPrompt = $systemPrompt;
        return $this;
    }

    public function buildLoadPayload(): ?array
    {
        return $this->getLoadPayload();
    }

    public function getUserContent(): string|array
    {
        return $this->probeUserContent;
    }

    public function getAIPromptWithParametersApplied(): AIPrompt
    {
        $taskPrompt = new AIPrompt();
        $taskPrompt->name = 'Test.Task';
        $taskPrompt->promptText = $this->probeTaskPromptText;
        return $taskPrompt;
    }

    protected function applyLoadResult(string $resultText): void
    {
        $this->appliedResultText = $resultText;
    }

    protected function getAIModelCallGateService(): AIModelCallGateService
    {
        return $this->replacedGateService ?? $this->getModuleAIModelCallGateService();
    }

    protected function getAIModelCallUsageRecorderService(): AIModelCallUsageRecorderService
    {
        return $this->replacedUsageRecorderService ?? $this->getModuleAIModelCallUsageRecorderService();
    }
}

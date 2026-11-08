<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\AI\Repo\Argus\Traits;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Services\AIModelCallGateService;
use DDD\Domain\AI\Services\AIModelCallUsageRecorderService;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\AI\Support\ArgusLanguageModelProbe;
use DDD\Tests\AI\Support\InMemoryTestCache;
use DDD\Tests\AI\Support\RecordingAIModelCallGateService;
use DDD\Tests\AI\Support\RecordingAIModelCallUsageRecorderService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** The gate stages a call passes, the usage the recorder gets, and how an answer is read */
class ArgusAILanguageModelTraitGatesAndUsageTest extends TestCase
{
    protected function setUp(): void
    {
        InMemoryTestCache::installForArgusCacheGroups();
    }

    protected function createProbe(bool $enforceBudget = true): ArgusLanguageModelProbe
    {
        return (new ArgusLanguageModelProbe())->useArgusLanguageModel(new ArgusLanguageModel(
            defaultAIModelName: AIModel::MODEL_OPENAI_GPT5_4_MINI,
            temperature: 0.3,
            aiUsageScope: 'TEST.SCOPE',
            enforceBudget: $enforceBudget,
        ));
    }

    public function testTheModuleDefaultsLetEveryCallThroughAndRecordNothing(): void
    {
        self::assertInstanceOf(AIModelCallGateService::class, DDDService::instance()->getService(AIModelCallGateService::class));
        self::assertInstanceOf(AIModelCallUsageRecorderService::class, DDDService::instance()->getService(AIModelCallUsageRecorderService::class));
        self::assertNotNull($this->createProbe()->buildLoadPayload());
    }

    public function testTheStagesRunInOrderWithTheBudgetCheck(): void
    {
        $recordingGate = new RecordingAIModelCallGateService();
        $recordingGate->budgetCheckNeeded = true;
        $argusLanguageModelProbe = $this->createProbe();
        $argusLanguageModelProbe->replacedGateService = $recordingGate;

        $argusLanguageModelProbe->buildLoadPayload();

        self::assertSame(
            [
                'refuseModelCallWithoutUsageScopeOrEnvelope',
                'refuseModelCallForIneligibleBilledScope',
                'needsCostEstimateForBudgetCheck',
                'refuseModelCallWithoutBilledAccount',
                'loadBudgetSettingsOfBilledAccount',
                'refuseModelCallOverBudget',
            ],
            $recordingGate->askedStages
        );
        self::assertGreaterThan(0, $recordingGate->lastEstimatedCosts->amount);
    }

    public function testWithoutBudgetEnforcementTheBudgetStagesAreSkipped(): void
    {
        $recordingGate = new RecordingAIModelCallGateService();
        $recordingGate->budgetCheckNeeded = true;
        $argusLanguageModelProbe = $this->createProbe(enforceBudget: false);
        $argusLanguageModelProbe->replacedGateService = $recordingGate;

        $argusLanguageModelProbe->buildLoadPayload();

        self::assertSame(
            ['refuseModelCallWithoutUsageScopeOrEnvelope', 'refuseModelCallForIneligibleBilledScope'],
            $recordingGate->askedStages
        );
    }

    public function testARefusingStageStopsTheCallBeforeAnyBodyIsBuilt(): void
    {
        $recordingGate = new RecordingAIModelCallGateService();
        $recordingGate->refusingStage = 'refuseModelCallForIneligibleBilledScope';
        $argusLanguageModelProbe = $this->createProbe();
        $argusLanguageModelProbe->replacedGateService = $recordingGate;

        try {
            $argusLanguageModelProbe->buildLoadPayload();
            self::fail('the refusing stage must stop the call');
        } catch (\RuntimeException $refusal) {
            self::assertSame('refused at refuseModelCallForIneligibleBilledScope', $refusal->getMessage());
        }
        self::assertNull($argusLanguageModelProbe->lastRequestPayload);
    }

    public function testAPricedAnswerIsAppliedAndRecordedOnce(): void
    {
        $recordingRecorder = new RecordingAIModelCallUsageRecorderService();
        $argusLanguageModelProbe = $this->createProbe();
        $argusLanguageModelProbe->replacedUsageRecorderService = $recordingRecorder;
        $callResponseData = json_decode('{"choices":[{"message":{"content":"positive"}}],"usage":{"prompt_tokens":1000,"completion_tokens":200}}');

        $argusLanguageModelProbe->handleLoadResponse($callResponseData);

        self::assertSame('positive', $argusLanguageModelProbe->appliedResultText);
        self::assertCount(1, $recordingRecorder->recordedUsages);
        self::assertSame(1000, $recordingRecorder->recordedUsages[0]->inputTokens);
        self::assertSame(200, $recordingRecorder->recordedUsages[0]->outputTokens);
        self::assertGreaterThan(0, $recordingRecorder->recordedUsages[0]->costs->amount);
        self::assertSame('TEST.SCOPE', $recordingRecorder->recordedCalls[0]->aiUsageScope);
    }

    public function testAnAnswerWithoutUsageRecordsNothing(): void
    {
        $recordingRecorder = new RecordingAIModelCallUsageRecorderService();
        $argusLanguageModelProbe = $this->createProbe();
        $argusLanguageModelProbe->replacedUsageRecorderService = $recordingRecorder;
        $callResponseData = json_decode('{"choices":[{"message":{"content":"positive"}}]}');

        $argusLanguageModelProbe->handleLoadResponse($callResponseData);

        self::assertSame('positive', $argusLanguageModelProbe->appliedResultText);
        self::assertSame([], $recordingRecorder->recordedUsages);
    }

    public function testAFailedAnswerIsNotApplied(): void
    {
        $argusLanguageModelProbe = $this->createProbe();
        $callResponseData = json_decode('{"status":500,"message":"upstream down"}');

        $argusLanguageModelProbe->handleLoadResponse($callResponseData);

        self::assertNull($argusLanguageModelProbe->appliedResultText);
        self::assertFalse($argusLanguageModelProbe->getArgusSettings()->isLoadedSuccessfully);
    }

    public function testAFailedAnswerNamesItsReasonAndASuccessClearsIt(): void
    {
        $argusLanguageModelProbe = $this->createProbe();
        $openRouterError = json_decode('{"error":{"message":"The total text input size exceeds 8 MB","code":400}}');
        $payload = [];
        $argusApiOperation = new ArgusApiOperation($argusLanguageModelProbe, 'call-1', 'POST:/ai/openRouter/chatCompletions', $payload);
        $argusApiOperation->responseStatusCode = 400;

        $argusLanguageModelProbe->handleLoadResponse($openRouterError, $argusApiOperation);

        self::assertSame(
            'http 400 from the upstream call: The total text input size exceeds 8 MB',
            $argusLanguageModelProbe->getLastLoadFailureReason()
        );

        $successfulAnswer = json_decode('{"choices":[{"message":{"content":"positive"}}]}');
        $argusLanguageModelProbe->handleLoadResponse($successfulAnswer);

        self::assertNull($argusLanguageModelProbe->getLastLoadFailureReason());
    }

    public function testAnAnswerWithoutTextNamesThat(): void
    {
        $argusLanguageModelProbe = $this->createProbe();
        $emptyAnswer = json_decode('{"choices":[{"message":{"content":""}}]}');

        $argusLanguageModelProbe->handleLoadResponse($emptyAnswer);

        self::assertSame('an answer without text', $argusLanguageModelProbe->getLastLoadFailureReason());
    }

    public function testTheCallKindIsTheUsageScopeAndTheDefaultTimeoutApplies(): void
    {
        $argusLanguageModelProbe = $this->createProbe();

        self::assertSame('TEST.SCOPE', $argusLanguageModelProbe->getArgusCallKind());
        self::assertSame(300.0, $argusLanguageModelProbe->getArgusSettings()->timeout);
    }

    public function testAnUnknownModelThrowsAndLeavesThrowErrorsOff(): void
    {
        $argusLanguageModelProbe = $this->createProbe();
        $findAIModelByNameOrThrow = new ReflectionMethod($argusLanguageModelProbe, 'findAIModelByNameOrThrow');
        $aiModelsService = DDDService::instance()->getService(AIModelsService::class);
        $throwErrorsBefore = $aiModelsService->throwErrors;

        try {
            $findAIModelByNameOrThrow->invoke($argusLanguageModelProbe, 'VENDOR.UNKNOWN_MODEL');
            self::fail('an unknown model must throw');
        } catch (NotFoundException) {
        }
        self::assertSame($throwErrorsBefore, $aiModelsService->throwErrors);
    }
}

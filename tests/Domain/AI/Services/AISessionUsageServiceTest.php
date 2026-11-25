<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Domain\AI\Services;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Models\Settings\AILanguageModelSetting;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Domain\Common\Entities\Money\MoneyAmount;
use DDD\Tests\AI\Support\RecordingAISessionUsageService;
use PHPUnit\Framework\TestCase;

/** Seconds of a session billed by time go through the usage recorder like a token answer, priced per second */
class AISessionUsageServiceTest extends TestCase
{
    public const string SCOPE_VOICE_SESSION = 'TEST.VOICE_SESSION';

    protected function buildModelCallWithPricePerSecond(?float $usdPerSecond): AIModelCall
    {
        $aiModel = new AIModel();
        $aiModel->settings = new AILanguageModelSetting();
        if ($usdPerSecond !== null) {
            $aiModel->settings->costsPerSessionSecond = new MoneyAmount($usdPerSecond, 'USD');
        }
        return new AIModelCall('TEST.VoiceSession', self::SCOPE_VOICE_SESSION, $aiModel);
    }

    public function testSecondsArePricedFromTheCatalogAndRecordedOnce(): void
    {
        $recordingAISessionUsageService = new RecordingAISessionUsageService();

        $aiModelCallUsage = $recordingAISessionUsageService->recordSessionSeconds($this->buildModelCallWithPricePerSecond(0.002), 90.5);

        self::assertSame(90.5, $aiModelCallUsage->sessionSeconds);
        self::assertEqualsWithDelta(0.181, $aiModelCallUsage->costs->amount, 0.000001);
        $recordedUsages = $recordingAISessionUsageService->recordingAIModelCallUsageRecorderService->recordedUsages;
        self::assertCount(1, $recordedUsages);
        self::assertSame($aiModelCallUsage, $recordedUsages[0]);
        self::assertNull($recordedUsages[0]->inputTokens);
        self::assertSame(self::SCOPE_VOICE_SESSION, $recordingAISessionUsageService->recordingAIModelCallUsageRecorderService->recordedCalls[0]->aiUsageScope);
    }

    public function testAPassedPriceReplacesTheCatalogPrice(): void
    {
        $recordingAISessionUsageService = new RecordingAISessionUsageService();

        $aiModelCallUsage = $recordingAISessionUsageService->recordSessionSeconds(
            $this->buildModelCallWithPricePerSecond(0.002),
            10,
            new MoneyAmount(0.01, 'USD')
        );

        self::assertEqualsWithDelta(0.1, $aiModelCallUsage->costs->amount, 0.000001);
    }

    public function testAnUnpricedSessionBooksNothing(): void
    {
        $recordingAISessionUsageService = new RecordingAISessionUsageService();

        $aiModelCallUsage = $recordingAISessionUsageService->recordSessionSeconds($this->buildModelCallWithPricePerSecond(null), 60);

        self::assertNull($aiModelCallUsage->costs);
        self::assertSame(60.0, $aiModelCallUsage->sessionSeconds);
        self::assertSame([], $recordingAISessionUsageService->recordingAIModelCallUsageRecorderService->recordedUsages);
    }

    public function testNegativeSecondsCountAsZero(): void
    {
        $recordingAISessionUsageService = new RecordingAISessionUsageService();

        $aiModelCallUsage = $recordingAISessionUsageService->recordSessionSeconds($this->buildModelCallWithPricePerSecond(0.002), -5);

        self::assertSame(0.0, $aiModelCallUsage->sessionSeconds);
        self::assertSame(0.0, $aiModelCallUsage->costs->amount);
    }

    public function testTheCatalogKeyIsHydratedAsAPricePerSecond(): void
    {
        $aiModelsService = new class extends AIModelsService {
            public function hydrate(array $modelConfig): AIModel
            {
                return $this->createAIModelFromConfig('TEST.SESSION_PRICE_PROBE', $modelConfig);
            }
        };

        $aiModel = $aiModelsService->hydrate(['type' => AIModel::TYPE_LANGUAGE, 'settings' => ['costsPerSessionSecondInUSD' => 0.0015]]);

        self::assertSame(0.0015, $aiModel->settings->costsPerSessionSecond->amount);
    }
}

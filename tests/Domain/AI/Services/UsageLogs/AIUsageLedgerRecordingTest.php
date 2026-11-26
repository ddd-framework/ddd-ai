<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Domain\AI\Services\UsageLogs;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\AI\Entities\ModelCalls\AIModelCallUsage;
use DDD\Domain\AI\Entities\UsageLogs\AIUsageLog;
use DDD\Domain\AI\Services\AIModelCallUsageRecorderService;
use DDD\Domain\AI\Services\UsageLogs\AIUsageLogsService;
use DDD\Domain\Common\Entities\Money\MoneyAmount;
use DDD\DDDBundle;
use DDD\Tests\AI\Support\LedgerEnabledAIModelCallUsageRecorderService;
use DDD\Tests\AI\Support\RecordingAIUsageLogsService;
use DDD\Tests\AI\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * The AI cost ledger's recording: off by default (nothing is booked), on it books every priced call, a call without
 * costs books nothing, and a ledger that cannot write is logged as an error and never fails the call.
 */
class AIUsageLedgerRecordingTest extends TestCase
{
    protected RecordingAIUsageLogsService $recordingAIUsageLogsService;

    protected RecordingLogger $recordingLogger;

    /** @var array<string, object|null> The container's instances before the test, put back afterwards */
    protected array $replacedInstances = [];

    protected function setUp(): void
    {
        $this->recordingAIUsageLogsService = new RecordingAIUsageLogsService();
        $this->recordingLogger = new RecordingLogger();
        $this->replaceContainerInstance(AIUsageLogsService::class, $this->recordingAIUsageLogsService);
        $this->replaceContainerInstance('logger', $this->recordingLogger);
    }

    protected function tearDown(): void
    {
        foreach ($this->replacedInstances as $serviceId => $previousInstance) {
            $this->setContainerInstance($serviceId, $previousInstance);
        }
    }

    public function testTheLedgerIsOffByDefaultAndBooksNothing(): void
    {
        $aIModelCallUsageRecorderService = new AIModelCallUsageRecorderService();

        self::assertFalse($aIModelCallUsageRecorderService->isUsageLogEnabled());
        $aIModelCallUsageRecorderService->recordModelCallUsage($this->buildModelCall(), $this->buildUsage(0.02));

        self::assertSame([], $this->recordingAIUsageLogsService->bookedSourceClasses);
    }

    public function testSwitchedOnEveryPricedCallIsBookedAndACallWithoutCostsIsNot(): void
    {
        $aIModelCallUsageRecorderService = new LedgerEnabledAIModelCallUsageRecorderService();

        $aIModelCallUsageRecorderService->recordModelCallUsage($this->buildModelCall(), $this->buildUsage(0.02));
        $aIModelCallUsageRecorderService->recordModelCallUsage($this->buildModelCall(), new AIModelCallUsage(inputTokens: 10, outputTokens: 5));

        self::assertSame(['App\Domain\AI\Repo\Argus\ArgusTicketAnswer'], $this->recordingAIUsageLogsService->bookedSourceClasses);
    }

    public function testALedgerThatCannotWriteIsLoggedAndNeverFailsTheCall(): void
    {
        $this->recordingAIUsageLogsService->failsOnWrite = true;

        (new LedgerEnabledAIModelCallUsageRecorderService())->recordModelCallUsage($this->buildModelCall(), $this->buildUsage(0.02));

        self::assertCount(1, $this->recordingLogger->lines);
        self::assertStringStartsWith('error [AIUsageLog] the cost of ArgusTicketAnswer (0.02 USD) was not booked: Table AIUsageLogs does not exist', $this->recordingLogger->lines[0]);
    }

    public function testTheFeatureIsTheUsageScopeElseTheRepoNameCutToTheColumn(): void
    {
        // without a kernel the entity's lazy-load set-up cannot run; the feature reads plain properties only
        /** @var AIUsageLog $aIUsageLog */
        $aIUsageLog = (new ReflectionClass(AIUsageLog::class))->newInstanceWithoutConstructor();
        $aIUsageLog->usageScope = AIUsageLog::normalizeScope('  TICKETS.ANSWER  ');
        self::assertSame('TICKETS.ANSWER', $this->recordingAIUsageLogsService->callFindFeature($aIUsageLog, 'App\Domain\AI\Repo\Argus\ArgusTicketAnswer'));

        $aIUsageLog->usageScope = AIUsageLog::normalizeScope('');
        self::assertNull($aIUsageLog->usageScope);
        self::assertSame('ArgusTicketAnswer', $this->recordingAIUsageLogsService->callFindFeature($aIUsageLog, 'App\Domain\AI\Repo\Argus\ArgusTicketAnswer'));
        self::assertSame('OTHER', $this->recordingAIUsageLogsService->callFindFeature($aIUsageLog, null));
        self::assertSame(32, mb_strlen($this->recordingAIUsageLogsService->callFindFeature($aIUsageLog, str_repeat('X', 40))));
    }

    protected function buildModelCall(): AIModelCall
    {
        return new AIModelCall(argusClass: 'App\Domain\AI\Repo\Argus\ArgusTicketAnswer', aiUsageScope: 'TICKETS.ANSWER');
    }

    protected function buildUsage(float $costInUSD): AIModelCallUsage
    {
        return new AIModelCallUsage(inputTokens: 100, outputTokens: 20, costs: new MoneyAmount($costInUSD, 'USD'));
    }

    protected function replaceContainerInstance(string $serviceId, object $instance): void
    {
        $this->replacedInstances[$serviceId] = $this->findContainerInstances()[$serviceId] ?? null;
        $this->setContainerInstance($serviceId, $instance);
    }

    protected function setContainerInstance(string $serviceId, ?object $instance): void
    {
        $container = (new ReflectionProperty(DDDBundle::class, 'defaultContainer'))->getValue();
        $testInstancesProperty = new ReflectionProperty($container, 'testInstances');
        $testInstances = $testInstancesProperty->getValue($container);
        if ($instance === null) {
            unset($testInstances[$serviceId]);
        } else {
            $testInstances[$serviceId] = $instance;
        }
        $testInstancesProperty->setValue($container, $testInstances);
    }

    /** @return array<string, object> */
    protected function findContainerInstances(): array
    {
        $container = (new ReflectionProperty(DDDBundle::class, 'defaultContainer'))->getValue();
        return (new ReflectionProperty($container, 'testInstances'))->getValue($container);
    }
}

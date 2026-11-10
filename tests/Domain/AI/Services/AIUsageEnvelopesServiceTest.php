<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Domain\AI\Services;

use DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant;
use DDD\Domain\AI\Entities\UsageLogs\AIUsageEnvelopeContext;
use DDD\Domain\AI\Entities\UsageLogs\Enums\AIUsageInitiator;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Tests\AI\Support\JournalingAIUsageEnvelopesService;
use DDD\Tests\AI\Support\ProjectAIUsageEnvelopeContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AIUsageEnvelopesServiceTest extends TestCase
{
    public const string SCOPE_OUTER = 'ENVELOPE.TEST.OUTER';
    public const string SCOPE_INNER = 'ENVELOPE.TEST.INNER';

    protected function grant(string $reason): AIBudgetBypassGrant
    {
        return AIBudgetBypassGrant::forReason($reason);
    }

    public function testEnvelopesNestAndTheInnermostIsCurrent(): void
    {
        $aIUsageEnvelopesService = new AIUsageEnvelopesService();
        $this->assertFalse($aIUsageEnvelopesService->hasActiveEnvelope());
        $this->assertNull($aIUsageEnvelopesService->getCurrentEnvelopeContext());

        $outerEnvelope = $aIUsageEnvelopesService->openEnvelope(7, self::SCOPE_OUTER, AIUsageInitiator::MESSENGER);
        $innerEnvelope = $aIUsageEnvelopesService->openEnvelope(7, self::SCOPE_INNER);

        $this->assertSame(1, $outerEnvelope->envelopeId);
        $this->assertSame(2, $innerEnvelope->envelopeId);
        $this->assertSame(AIUsageInitiator::MESSENGER, $outerEnvelope->initiatedBy);
        $this->assertSame(2, $aIUsageEnvelopesService->getNestingDepth());
        $this->assertSame($innerEnvelope, $aIUsageEnvelopesService->getCurrentEnvelopeContext());

        $aIUsageEnvelopesService->closeEnvelope($innerEnvelope);
        $this->assertSame(1, $aIUsageEnvelopesService->getCurrentEnvelopeId());
        $aIUsageEnvelopesService->closeEnvelope($outerEnvelope);
        $this->assertFalse($aIUsageEnvelopesService->hasActiveEnvelope());
    }

    public function testANonEnvelopeScopeIsRefused(): void
    {
        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage("Scope 'SEO.TEXT.ANALYZE' is not an envelope scope");

        (new AIUsageEnvelopesService())->openEnvelope(7, 'SEO.TEXT.ANALYZE');
    }

    public function testAFailedOpeningIsWrappedAndOpensNothing(): void
    {
        $aIUsageEnvelopesService = new JournalingAIUsageEnvelopesService();
        $aIUsageEnvelopesService->failsOnOpening = true;

        try {
            $aIUsageEnvelopesService->openEnvelope(7, self::SCOPE_OUTER);
            $this->fail('the opening must fail');
        } catch (InternalErrorException $internalErrorException) {
            $this->assertStringStartsWith('Failed to create envelope: usage log unavailable', $internalErrorException->getMessage());
        }
        $this->assertFalse($aIUsageEnvelopesService->hasActiveEnvelope());
    }

    public function testAnApplicationContextSubclassReachesThePersistence(): void
    {
        $aIUsageEnvelopesService = new JournalingAIUsageEnvelopesService();
        $projectEnvelopeContext = ProjectAIUsageEnvelopeContext::forScope(7, self::SCOPE_OUTER);
        $projectEnvelopeContext->projectId = 42;

        $openedEnvelope = $aIUsageEnvelopesService->openEnvelopeForContext($projectEnvelopeContext);

        $this->assertSame(['open ' . self::SCOPE_OUTER . ' project 42'], $aIUsageEnvelopesService->journal);
        $this->assertSame(1000, $openedEnvelope->envelopeId);
        $this->assertSame($projectEnvelopeContext, $aIUsageEnvelopesService->getCurrentEnvelopeContext());
    }

    public function testAContextIsOpenedOnlyOnce(): void
    {
        $aIUsageEnvelopesService = new AIUsageEnvelopesService();
        $envelope = $aIUsageEnvelopesService->openEnvelope(7, self::SCOPE_OUTER);

        $this->expectException(InternalErrorException::class);
        $aIUsageEnvelopesService->openEnvelopeForContext($envelope);
    }

    public function testClosingWithoutAnEnvelopeThrowsButClosingAClosedEnvelopeDoesNothing(): void
    {
        $aIUsageEnvelopesService = new AIUsageEnvelopesService();
        $envelope = $aIUsageEnvelopesService->openEnvelope(7, self::SCOPE_OUTER);
        $aIUsageEnvelopesService->closeEnvelope($envelope);

        $aIUsageEnvelopesService->closeEnvelope($envelope);
        $aIUsageEnvelopesService->closeEnvelope(AIUsageEnvelopeContext::forScope(7, self::SCOPE_OUTER));

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('No active envelope to finalize');
        $aIUsageEnvelopesService->closeEnvelope();
    }

    /** A nested close a throw skipped: closing the outer envelope closes the inner one too, innermost first. */
    public function testClosingAnOuterEnvelopeClosesTheEnvelopesOpenedInsideIt(): void
    {
        $aIUsageEnvelopesService = new JournalingAIUsageEnvelopesService();
        $outerEnvelope = $aIUsageEnvelopesService->openEnvelope(7, self::SCOPE_OUTER);
        $aIUsageEnvelopesService->openEnvelope(7, self::SCOPE_INNER);
        $aIUsageEnvelopesService->openEnvelope(7, self::SCOPE_INNER);

        $aIUsageEnvelopesService->closeEnvelope($outerEnvelope);

        $this->assertFalse($aIUsageEnvelopesService->hasActiveEnvelope());
        $this->assertSame(['close 1002', 'close 1001', 'close 1000'], array_slice($aIUsageEnvelopesService->journal, 3));
    }

    public function testAFailedClosingStillClosesTheEnvelopeAndDropsItsGrant(): void
    {
        $aIUsageEnvelopesService = new JournalingAIUsageEnvelopesService();
        $aIUsageEnvelopesService->failsOnClosing = true;
        $aIUsageEnvelopesService->openEnvelope(7, self::SCOPE_OUTER);
        $aIUsageEnvelopesService->grantBudgetBypassForCurrentEnvelope($this->grant(AIBudgetBypassGrant::REASON_CONVERSATION_BYPASS));

        $aIUsageEnvelopesService->closeEnvelope();

        $this->assertFalse($aIUsageEnvelopesService->hasActiveEnvelope());
        $this->assertFalse($aIUsageEnvelopesService->isBudgetBypassGranted());
    }

    public function testCloseAllEnvelopesClosesEveryOpenEnvelope(): void
    {
        $aIUsageEnvelopesService = new JournalingAIUsageEnvelopesService();
        $aIUsageEnvelopesService->openEnvelope(7, self::SCOPE_OUTER);
        $aIUsageEnvelopesService->openEnvelope(7, self::SCOPE_INNER);

        $aIUsageEnvelopesService->closeAllEnvelopes();

        $this->assertFalse($aIUsageEnvelopesService->hasActiveEnvelope());
        $this->assertSame(['close 1001', 'close 1000'], array_slice($aIUsageEnvelopesService->journal, 2));
    }

    public function testANestedEnvelopeInheritsTheGrantAndTheProcessGrantAppliesLast(): void
    {
        $aIUsageEnvelopesService = new AIUsageEnvelopesService();
        $processGrant = $this->grant(AIBudgetBypassGrant::REASON_CONSOLE_OPTION);
        $aIUsageEnvelopesService->grantBudgetBypassForTheProcess($processGrant);
        $this->assertSame($processGrant, $aIUsageEnvelopesService->getBudgetBypassGrant());

        $aIUsageEnvelopesService->openEnvelope(7, self::SCOPE_OUTER);
        $envelopeGrant = $this->grant(AIBudgetBypassGrant::REASON_CONVERSATION_BYPASS);
        $aIUsageEnvelopesService->grantBudgetBypassForCurrentEnvelope($envelopeGrant);
        $innerEnvelope = $aIUsageEnvelopesService->openEnvelope(7, self::SCOPE_INNER);

        $this->assertSame($envelopeGrant, $aIUsageEnvelopesService->getBudgetBypassGrant());
        $this->assertSame($processGrant, $aIUsageEnvelopesService->getBudgetBypassGrantForTheProcess());
        $aIUsageEnvelopesService->closeEnvelope($innerEnvelope);
        $aIUsageEnvelopesService->closeEnvelope();
        $this->assertSame($processGrant, $aIUsageEnvelopesService->getBudgetBypassGrant());
    }

    public function testWithoutAnEnvelopeNothingIsGranted(): void
    {
        $aIUsageEnvelopesService = new AIUsageEnvelopesService();

        $this->assertFalse($aIUsageEnvelopesService->grantBudgetBypassIfAnEnvelopeIsOpen($this->grant(AIBudgetBypassGrant::REASON_SYSTEM_MAINTENANCE)));
        $this->assertNull($aIUsageEnvelopesService->grantBudgetBypassUntilRestored($this->grant(AIBudgetBypassGrant::REASON_SYSTEM_MAINTENANCE)));
        $this->assertFalse($aIUsageEnvelopesService->isBudgetBypassGranted());

        $this->expectException(InternalErrorException::class);
        $aIUsageEnvelopesService->grantBudgetBypassForCurrentEnvelope($this->grant(AIBudgetBypassGrant::REASON_SYSTEM_MAINTENANCE));
    }

    /** No session account in the test container: a request's bypass flag is ignored, as for a customer. */
    public function testARequestWithoutAnAdminSessionLiftsNothing(): void
    {
        $aIUsageEnvelopesService = new AIUsageEnvelopesService();
        $aIUsageEnvelopesService->openEnvelope(null, self::SCOPE_OUTER);

        $this->assertFalse($aIUsageEnvelopesService->grantBudgetBypassIfAnAdminRequestedIt(false));
        $this->assertFalse($aIUsageEnvelopesService->grantBudgetBypassIfAnAdminRequestedIt(true));
        $this->assertFalse($aIUsageEnvelopesService->isBudgetBypassGranted());
    }

    public function testTheRestorePutsBackThePriorGrantOrNoneAndIsRepeatable(): void
    {
        $aIUsageEnvelopesService = new AIUsageEnvelopesService();
        $aIUsageEnvelopesService->openEnvelope(7, self::SCOPE_OUTER);

        $turnRestore = $aIUsageEnvelopesService->grantBudgetBypassUntilRestored($this->grant(AIBudgetBypassGrant::REASON_CONVERSATION_BYPASS));
        $this->assertNull($turnRestore->priorBudgetBypassGrant);
        $driveGrant = $this->grant(AIBudgetBypassGrant::REASON_ADMIN_REQUEST);
        $driveRestore = $aIUsageEnvelopesService->grantBudgetBypassUntilRestored($driveGrant);
        $this->assertSame($driveGrant, $aIUsageEnvelopesService->getBudgetBypassGrant());

        $aIUsageEnvelopesService->restoreBudgetBypassGrant($driveRestore);
        $this->assertSame(AIBudgetBypassGrant::REASON_CONVERSATION_BYPASS, $aIUsageEnvelopesService->getBudgetBypassGrant()->reason);
        $aIUsageEnvelopesService->restoreBudgetBypassGrant($turnRestore);
        $aIUsageEnvelopesService->restoreBudgetBypassGrant($turnRestore);
        $aIUsageEnvelopesService->restoreBudgetBypassGrant(null);
        $this->assertFalse($aIUsageEnvelopesService->isBudgetBypassGranted());
    }

    /** The envelope ended before the restore: its grant went with it, and a later envelope is left alone. */
    public function testTheRestoreOfAClosedEnvelopeDoesNothing(): void
    {
        $aIUsageEnvelopesService = new AIUsageEnvelopesService();
        $aIUsageEnvelopesService->openEnvelope(7, self::SCOPE_OUTER);
        $restore = $aIUsageEnvelopesService->grantBudgetBypassUntilRestored($this->grant(AIBudgetBypassGrant::REASON_CONVERSATION_BYPASS));
        $aIUsageEnvelopesService->closeEnvelope();
        $aIUsageEnvelopesService->openEnvelope(7, self::SCOPE_OUTER);
        $laterGrant = $this->grant(AIBudgetBypassGrant::REASON_SYSTEM_MAINTENANCE);
        $aIUsageEnvelopesService->grantBudgetBypassForCurrentEnvelope($laterGrant);

        $aIUsageEnvelopesService->restoreBudgetBypassGrant($restore);

        $this->assertSame($laterGrant, $aIUsageEnvelopesService->getBudgetBypassGrant());
    }

    public function testABookedCostGoesToTheInnermostRecordingOnly(): void
    {
        $aIUsageEnvelopesService = new AIUsageEnvelopesService();
        $outerRecording = $aIUsageEnvelopesService->startRecordingBookedCosts();
        $aIUsageEnvelopesService->recordBookedCost('TOOL.WEB_SEARCH', 0.5);
        $innerRecording = $aIUsageEnvelopesService->startRecordingBookedCosts();
        $aIUsageEnvelopesService->recordBookedCost('TOOL.PAGE_READ', 0.25);
        $aIUsageEnvelopesService->stopRecordingBookedCosts($innerRecording);
        $aIUsageEnvelopesService->recordBookedCostsOf($innerRecording);
        $aIUsageEnvelopesService->stopRecordingBookedCosts($outerRecording);
        $aIUsageEnvelopesService->recordBookedCost('TOOL.WEB_SEARCH', 9.0);

        $this->assertSame(0.25, $innerRecording->totalCostInUSD);
        $this->assertSame(0.75, $outerRecording->totalCostInUSD);
        $this->assertSame(2, $outerRecording->bookingCount);
    }

    /** A throw skipped the inner stop: stopping the outer recording closes both. */
    public function testStoppingAnOuterRecordingStopsTheInnerOnesToo(): void
    {
        $aIUsageEnvelopesService = new AIUsageEnvelopesService();
        $outerRecording = $aIUsageEnvelopesService->startRecordingBookedCosts();
        $innerRecording = $aIUsageEnvelopesService->startRecordingBookedCosts();

        $aIUsageEnvelopesService->stopRecordingBookedCosts($outerRecording);
        $aIUsageEnvelopesService->recordBookedCost('TOOL.WEB_SEARCH', 1.0);
        $aIUsageEnvelopesService->stopRecordingBookedCosts($innerRecording);

        $this->assertSame(0.0, $outerRecording->totalCostInUSD);
        $this->assertSame(0.0, $innerRecording->totalCostInUSD);
    }

    public function testACostCountedElsewhereStaysOutOfTheRecordingByConfig(): void
    {
        Config::addConfigDirectory(dirname(__DIR__, 3) . '/Fixtures/config/app', isModule: true);
        $aIUsageEnvelopesService = new AIUsageEnvelopesService();
        $recording = $aIUsageEnvelopesService->startRecordingBookedCosts();

        $aIUsageEnvelopesService->recordBookedCost('FIXTURE.COUNTED_ON_ITS_OWN_ROW', 2.0);
        $aIUsageEnvelopesService->recordBookedCost('TOOL.WEB_SEARCH', 0.5);

        $this->assertSame(0.5, $recording->totalCostInUSD);
        $this->assertSame(1, $recording->scopeBookedCosts->count());
        $this->assertSame(0.5, $recording->scopeBookedCosts->getCostInUSDForUsageScope('TOOL.WEB_SEARCH'));
        $this->assertSame(0.0, $recording->scopeBookedCosts->getCostInUSDForUsageScope('FIXTURE.COUNTED_ON_ITS_OWN_ROW'));
    }

    public function testResetForgetsEnvelopesGrantsAndRecordingsButNeverReusesAnId(): void
    {
        $aIUsageEnvelopesService = new AIUsageEnvelopesService();
        $aIUsageEnvelopesService->openEnvelope(7, self::SCOPE_OUTER);
        $aIUsageEnvelopesService->grantBudgetBypassForCurrentEnvelope($this->grant(AIBudgetBypassGrant::REASON_CONVERSATION_BYPASS));
        $aIUsageEnvelopesService->grantBudgetBypassForTheProcess($this->grant(AIBudgetBypassGrant::REASON_CONSOLE_OPTION));
        $recording = $aIUsageEnvelopesService->startRecordingBookedCosts();

        $aIUsageEnvelopesService->reset();
        $aIUsageEnvelopesService->recordBookedCost('TOOL.WEB_SEARCH', 1.0);

        $this->assertFalse($aIUsageEnvelopesService->hasActiveEnvelope());
        $this->assertFalse($aIUsageEnvelopesService->isBudgetBypassGranted());
        $this->assertSame(0.0, $recording->totalCostInUSD);
        $this->assertSame(2, $aIUsageEnvelopesService->openEnvelope(7, self::SCOPE_OUTER)->envelopeId);
    }
}

<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services;

use DDD\Domain\AI\Attributes\WithAIEnvelope;
use DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant;
use DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrantRestore;
use DDD\Domain\AI\Entities\UsageLogs\AIUsageBookedCosts;
use DDD\Domain\AI\Entities\UsageLogs\AIUsageEnvelopeContext;
use DDD\Domain\AI\Entities\UsageLogs\Enums\AIUsageInitiator;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use DDD\Symfony\Messenger\WorkerStateResetterInterface;
use Throwable;

/**
 * The open AI usage envelopes of this process and what rides on them. An envelope groups every AI call made while it
 * is open (one request, one job, one turn); envelopes nest. On the envelopes ride:
 *  - the AI-budget bypass grant ({@see AIBudgetBypassGrant}): set on an envelope by the code that knows the reason,
 *    inherited by every envelope opened inside it; or set for the whole process (a console option);
 *  - the open recordings of booked costs ({@see AIUsageBookedCosts}): the costs booked while one piece of work (a tool
 *    call) runs, innermost recording only.
 *
 * Resolve it from the container only (`DDDService::instance()->getService(AIUsageEnvelopesService::class)`): one shared
 * instance per process. Open and close an envelope in the same method:
 * ```php
 * $envelope = $aIUsageEnvelopesService->openEnvelope($accountId, 'ENVELOPE.MY.WORK', AIUsageInitiator::MESSENGER);
 * try {
 *     // the AI calls
 * } finally {
 *     $aIUsageEnvelopesService->closeEnvelope($envelope);
 * }
 * ```
 *
 * The base keeps everything in memory and persists nothing: envelope ids are counted in the process, closing writes
 * nothing, and no scope is counted elsewhere. An application that logs its usage overrides the protected methods
 * {@see persistEnvelopeOpening()}, {@see persistEnvelopeClosing()}, {@see isEnvelopeScope()} and
 * {@see isBookedCostCountedElsewhere()} in a subclass registered under this class's service id.
 *
 * {@see reset()} empties the state. The service is a worker state resetter: a Messenger worker runs
 * {@see resetWorkerStateForNewJob()} before every consumed job, so a job never inherits an envelope, a grant or a
 * recording of the previous job.
 */
class AIUsageEnvelopesService extends Service implements WorkerStateResetterInterface
{
    /** @var string Config key: the usage scopes whose costs are counted elsewhere, see {@see isBookedCostCountedElsewhere()} */
    public const string CONFIG_KEY_SCOPES_COUNTED_ELSEWHERE = 'AI.usageScopesCountedElsewhere';

    /** @var array<int, AIUsageEnvelopeContext> envelope id => context, in opening order (innermost last) */
    protected array $envelopeContextsById = [];

    /** @var array<int, AIBudgetBypassGrant> envelope id => the budget-bypass grant set on that envelope */
    protected array $budgetBypassGrantsByEnvelopeId = [];

    /**
     * The budget-bypass grant of the whole process ({@see grantBudgetBypassForTheProcess()}): a console command run
     * with its budget option. A console run reads its options before any envelope is open, so the lift cannot sit on an
     * envelope; it applies wherever no open envelope carries a grant of its own. Cleared by {@see reset()}.
     */
    protected ?AIBudgetBypassGrant $budgetBypassGrantForTheProcess = null;

    /**
     * @var AIUsageBookedCosts[] The open recordings of booked costs, innermost last ({@see startRecordingBookedCosts()}).
     *      A booked amount goes to the innermost one only, so nested work is never counted on two recordings.
     */
    protected array $openBookedCostRecordings = [];

    /** @var int The last envelope id the base counted ({@see persistEnvelopeOpening()}); never reset, so an id is never reused in a process */
    protected int $lastInProcessEnvelopeId = 0;

    /**
     * @return bool Whether an envelope is open
     */
    public function hasActiveEnvelope(): bool
    {
        return $this->envelopeContextsById !== [];
    }

    /**
     * @return int How many envelopes are open (nested)
     */
    public function getNestingDepth(): int
    {
        return count($this->envelopeContextsById);
    }

    /**
     * @return int|null The id of the innermost open envelope; null when none is open
     */
    public function getCurrentEnvelopeId(): ?int
    {
        return array_key_last($this->envelopeContextsById);
    }

    /**
     * @return AIUsageEnvelopeContext|null The innermost open envelope; null when none is open
     */
    public function getCurrentEnvelopeContext(): ?AIUsageEnvelopeContext
    {
        $envelopeId = $this->getCurrentEnvelopeId();
        return $envelopeId === null ? null : $this->envelopeContextsById[$envelopeId];
    }

    /**
     * Opens an envelope inside the current one (or the first one). Close it with {@see closeEnvelope()} in a `finally`.
     *
     * @param int|null $accountId The account the envelope's calls are billed to
     * @param string $scope The envelope scope (`ENVELOPE.*`)
     * @param string $initiatedBy Who started the work ({@see AIUsageInitiator})
     * @return AIUsageEnvelopeContext The opened envelope, its id set
     * @throws InternalErrorException when the scope is no envelope scope, or persisting the opening fails
     */
    public function openEnvelope(
        ?int $accountId,
        string $scope,
        string $initiatedBy = AIUsageInitiator::ACCOUNT
    ): AIUsageEnvelopeContext {
        return $this->openEnvelopeForContext(AIUsageEnvelopeContext::forScope($accountId, $scope, $initiatedBy));
    }

    /**
     * Opens the envelope `$envelopeContext` describes — for an application whose context subclass carries more than the
     * account (a project); its {@see persistEnvelopeOpening()} override reads those properties. Sets the context's id.
     *
     * @param AIUsageEnvelopeContext $envelopeContext A context not opened yet (no id)
     * @return AIUsageEnvelopeContext The same context, its id set
     * @throws InternalErrorException when the context was opened before, the scope is no envelope scope, or persisting
     *         the opening fails
     */
    public function openEnvelopeForContext(AIUsageEnvelopeContext $envelopeContext): AIUsageEnvelopeContext
    {
        if ($envelopeContext->envelopeId !== null) {
            throw new InternalErrorException("Envelope {$envelopeContext->envelopeId} was opened before; open a new context instead");
        }
        if (!$this->isEnvelopeScope($envelopeContext->scope)) {
            throw new InternalErrorException(
                "Scope '{$envelopeContext->scope}' is not an envelope scope. Use ENVELOPE.* scopes.",
            );
        }
        try {
            $envelopeId = $this->persistEnvelopeOpening($envelopeContext);
        } catch (Throwable $throwable) {
            throw new InternalErrorException(message: "Failed to create envelope: {$throwable->getMessage()}", previous: $throwable);
        }
        $envelopeContext->envelopeId = $envelopeId;
        $this->envelopeContextsById[$envelopeId] = $envelopeContext;
        return $envelopeContext;
    }

    /**
     * Closes an envelope and every envelope opened inside it that is still open (a nested close a throw skipped; a
     * warning is logged then). An envelope that is no longer open is left alone. A failure of
     * {@see persistEnvelopeClosing()} is swallowed — the envelope is closed in memory regardless, its grant goes with
     * it.
     *
     * @param AIUsageEnvelopeContext|null $envelopeContext The envelope to close; null = the innermost one
     * @return void
     * @throws InternalErrorException when `$envelopeContext` is null and no envelope is open
     */
    public function closeEnvelope(?AIUsageEnvelopeContext $envelopeContext = null): void
    {
        if ($envelopeContext === null) {
            $targetEnvelopeId = $this->getCurrentEnvelopeId();
            if ($targetEnvelopeId === null) {
                throw new InternalErrorException('No active envelope to finalize');
            }
        } else {
            $targetEnvelopeId = $envelopeContext->envelopeId;
            if ($targetEnvelopeId === null || !isset($this->envelopeContextsById[$targetEnvelopeId])) {
                return;
            }
        }
        if ($targetEnvelopeId !== $this->getCurrentEnvelopeId()) {
            $this->logEnvelopesClosedWithAnOuterEnvelope($targetEnvelopeId);
        }
        while ($this->envelopeContextsById !== []) {
            $innermostEnvelopeId = array_key_last($this->envelopeContextsById);
            $this->closeInnermostEnvelope();
            if ($innermostEnvelopeId === $targetEnvelopeId) {
                return;
            }
        }
    }

    /**
     * Closes every open envelope, innermost first; failures are swallowed.
     *
     * @return void
     */
    public function closeAllEnvelopes(): void
    {
        while ($this->envelopeContextsById !== []) {
            try {
                $this->closeEnvelope();
            } catch (Throwable) {
                // closeEnvelope() throws only for an empty stack, which the loop condition excludes
            }
        }
    }

    /**
     * Lift the AI-budget cap for the CURRENT envelope and every envelope opened inside it. Set by the code that knows
     * the reason, after the envelope is open (a Messenger handler sets it once it has loaded the conversation).
     *
     * @param AIBudgetBypassGrant $budgetBypassGrant The grant and its reason
     * @return void
     * @throws InternalErrorException when no envelope is open — a grant without an envelope would lift nothing
     */
    public function grantBudgetBypassForCurrentEnvelope(AIBudgetBypassGrant $budgetBypassGrant): void
    {
        $envelopeId = $this->getCurrentEnvelopeId();
        if ($envelopeId === null) {
            throw new InternalErrorException('No active envelope to grant the budget bypass for');
        }
        $this->budgetBypassGrantsByEnvelopeId[$envelopeId] = $budgetBypassGrant;
    }

    /**
     * Lift the AI-budget cap for the current envelope when one is open; without an envelope nothing is granted. For a
     * Messenger handler, whose envelope the middleware opens and closes around the job: the grant ends with the job.
     *
     * @param AIBudgetBypassGrant $budgetBypassGrant The grant and its reason
     * @return bool Whether the grant was set
     */
    public function grantBudgetBypassIfAnEnvelopeIsOpen(AIBudgetBypassGrant $budgetBypassGrant): bool
    {
        $envelopeId = $this->getCurrentEnvelopeId();
        if ($envelopeId === null) {
            return false;
        }
        $this->budgetBypassGrantsByEnvelopeId[$envelopeId] = $budgetBypassGrant;
        return true;
    }

    /**
     * A request that asked for the bypass lifts the cap for its own envelope only when an admin operates the session
     * ({@see AuthService::isCurrentSessionAdmin()}: an admin, or an admin impersonating the customer). A customer's
     * request is ignored, so a request never lifts its own cap. The grant ends with the request's envelope.
     *
     * @param bool $budgetBypassRequested Whether the request asked for the bypass
     * @return bool Whether the cap is lifted for this request now
     */
    public function grantBudgetBypassIfAnAdminRequestedIt(bool $budgetBypassRequested): bool
    {
        if (!$budgetBypassRequested || !AuthService::instance()->isCurrentSessionAdmin()) {
            return false;
        }
        return $this->grantBudgetBypassIfAnEnvelopeIsOpen(AIBudgetBypassGrant::forReason(AIBudgetBypassGrant::REASON_ADMIN_REQUEST));
    }

    /**
     * Lift the AI-budget cap for a piece of work that runs INSIDE the current envelope but ends before it (a turn, a
     * supervisor round, a drive): sets the grant on the current envelope and returns what to restore — hand it to
     * {@see restoreBudgetBypassGrant()} in a `finally`, and the envelope carries the grant it carried before (or none)
     * again. Null when no envelope is open: nothing is granted, nothing to restore.
     *
     * @param AIBudgetBypassGrant $budgetBypassGrant The grant and its reason
     * @return AIBudgetBypassGrantRestore|null What to restore; null when no envelope is open
     */
    public function grantBudgetBypassUntilRestored(AIBudgetBypassGrant $budgetBypassGrant): ?AIBudgetBypassGrantRestore
    {
        $envelopeId = $this->getCurrentEnvelopeId();
        if ($envelopeId === null) {
            return null;
        }
        $budgetBypassGrantRestore = AIBudgetBypassGrantRestore::forEnvelope(
            $envelopeId,
            $this->budgetBypassGrantsByEnvelopeId[$envelopeId] ?? null
        );
        $this->budgetBypassGrantsByEnvelopeId[$envelopeId] = $budgetBypassGrant;
        return $budgetBypassGrantRestore;
    }

    /**
     * Puts back the grant {@see grantBudgetBypassUntilRestored()} replaced. Null-safe and repeatable; an envelope that
     * was closed meanwhile is left alone (its grant went with it).
     *
     * @param AIBudgetBypassGrantRestore|null $budgetBypassGrantRestore What grantBudgetBypassUntilRestored() returned
     * @return void
     */
    public function restoreBudgetBypassGrant(?AIBudgetBypassGrantRestore $budgetBypassGrantRestore): void
    {
        if ($budgetBypassGrantRestore === null) {
            return;
        }
        $envelopeId = $budgetBypassGrantRestore->envelopeId;
        if (!isset($this->envelopeContextsById[$envelopeId])) {
            return;
        }
        if ($budgetBypassGrantRestore->priorBudgetBypassGrant === null) {
            unset($this->budgetBypassGrantsByEnvelopeId[$envelopeId]);
            return;
        }
        $this->budgetBypassGrantsByEnvelopeId[$envelopeId] = $budgetBypassGrantRestore->priorBudgetBypassGrant;
    }

    /**
     * Lift the AI-budget cap for the rest of this process — a console command's budget option. HTTP requests and
     * Messenger jobs grant on their envelope instead. Never restored by the command (the process ends); {@see reset()}
     * clears it.
     *
     * @param AIBudgetBypassGrant $budgetBypassGrant The grant and its reason
     * @return void
     */
    public function grantBudgetBypassForTheProcess(AIBudgetBypassGrant $budgetBypassGrant): void
    {
        $this->budgetBypassGrantForTheProcess = $budgetBypassGrant;
    }

    /**
     * @return AIBudgetBypassGrant|null The grant of the whole process ({@see grantBudgetBypassForTheProcess()}), regardless of the open envelopes
     */
    public function getBudgetBypassGrantForTheProcess(): ?AIBudgetBypassGrant
    {
        return $this->budgetBypassGrantForTheProcess;
    }

    /**
     * The budget-bypass grant that applies now: the one set on the current envelope or on the nearest enclosing one (a
     * nested envelope inherits its parent's grant); when none of the open envelopes carries a grant, the grant of the
     * process. Null when neither exists.
     *
     * @return AIBudgetBypassGrant|null
     */
    public function getBudgetBypassGrant(): ?AIBudgetBypassGrant
    {
        foreach (array_reverse(array_keys($this->envelopeContextsById)) as $envelopeId) {
            if (isset($this->budgetBypassGrantsByEnvelopeId[$envelopeId])) {
                return $this->budgetBypassGrantsByEnvelopeId[$envelopeId];
            }
        }
        return $this->budgetBypassGrantForTheProcess;
    }

    /**
     * @return bool Whether a budget-bypass grant applies now ({@see getBudgetBypassGrant()})
     */
    public function isBudgetBypassGranted(): bool
    {
        return $this->getBudgetBypassGrant() !== null;
    }

    /**
     * Opens a recording of the AI costs booked from now on ({@see recordBookedCost()}), for one piece of work — a tool
     * call's execution. The caller stops it in a `finally` ({@see stopRecordingBookedCosts()}) and keeps the returned
     * object: it holds what was booked while it was open.
     *
     * @return AIUsageBookedCosts The open recording
     */
    public function startRecordingBookedCosts(): AIUsageBookedCosts
    {
        $aIUsageBookedCosts = new AIUsageBookedCosts();
        $this->openBookedCostRecordings[] = $aIUsageBookedCosts;
        return $aIUsageBookedCosts;
    }

    /**
     * Closes `$aIUsageBookedCosts` and every recording opened after it that is still open (a nested recording whose
     * stop a throw skipped). A recording that is no longer open is left alone.
     *
     * @param AIUsageBookedCosts $aIUsageBookedCosts The recording startRecordingBookedCosts() returned
     * @return void
     */
    public function stopRecordingBookedCosts(AIUsageBookedCosts $aIUsageBookedCosts): void
    {
        $recordingPosition = array_search($aIUsageBookedCosts, $this->openBookedCostRecordings, true);
        if ($recordingPosition === false) {
            return;
        }
        $this->openBookedCostRecordings = array_slice($this->openBookedCostRecordings, 0, (int)$recordingPosition);
    }

    /**
     * One AI cost was just booked under `$usageScope`: added to the innermost open recording, if any. A cost counted
     * elsewhere ({@see isBookedCostCountedElsewhere()}) is left out.
     *
     * @param string $usageScope The usage scope the cost was booked under
     * @param float $costInUSD The booked amount
     * @return void
     */
    public function recordBookedCost(string $usageScope, float $costInUSD): void
    {
        if ($this->openBookedCostRecordings === [] || $this->isBookedCostCountedElsewhere($usageScope)) {
            return;
        }
        $innermostRecording = $this->openBookedCostRecordings[array_key_last($this->openBookedCostRecordings)];
        $innermostRecording->addBookedCost($usageScope, $costInUSD);
    }

    /**
     * Costs booked elsewhere for work that belongs to the innermost open recording, added whole (total, count, scopes):
     * the costs a piece of work booked under its own nested recording, or costs stored earlier and played back. The
     * amounts were filtered when they were first recorded, so no scope is left out here.
     *
     * @param AIUsageBookedCosts $aIUsageBookedCosts The costs to add
     * @return void
     */
    public function recordBookedCostsOf(AIUsageBookedCosts $aIUsageBookedCosts): void
    {
        if ($this->openBookedCostRecordings === []) {
            return;
        }
        $innermostRecording = $this->openBookedCostRecordings[array_key_last($this->openBookedCostRecordings)];
        $innermostRecording->addBookedCostsOf($aIUsageBookedCosts);
    }

    /**
     * A worker's next job starts without the envelopes, grants and recordings of the previous one.
     *
     * @return void
     */
    public function resetWorkerStateForNewJob(): void
    {
        $this->reset();
    }

    /**
     * Empties the state: no envelope, no envelope grant, no process grant, no open cost recording. Nothing is
     * persisted for the envelopes it drops. Run before every consumed worker job; also used by tests.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->envelopeContextsById = [];
        $this->budgetBypassGrantsByEnvelopeId = [];
        $this->budgetBypassGrantForTheProcess = null;
        $this->openBookedCostRecordings = [];
    }

    /**
     * Override point: whether `$scope` may open an envelope. Base: it starts with `ENVELOPE.`.
     *
     * @param string $scope The scope to check
     * @return bool
     */
    protected function isEnvelopeScope(string $scope): bool
    {
        return str_starts_with($scope, WithAIEnvelope::ENVELOPE_SCOPE_PREFIX);
    }

    /**
     * Override point: persists the opening of an envelope (a usage-log row) and returns its id. Base: persists
     * nothing, counts the id in the process. A throw makes {@see openEnvelopeForContext()} fail.
     *
     * @param AIUsageEnvelopeContext $envelopeContext The envelope being opened (no id yet)
     * @return int The envelope's id — unique among the open envelopes of the process
     * @throws Throwable when persisting fails
     */
    protected function persistEnvelopeOpening(AIUsageEnvelopeContext $envelopeContext): int
    {
        return ++$this->lastInProcessEnvelopeId;
    }

    /**
     * Override point: persists the closing of an envelope (e.g. totals of its calls). Base: nothing. A throw is
     * swallowed by {@see closeEnvelope()}.
     *
     * @param AIUsageEnvelopeContext $envelopeContext The envelope being closed
     * @return void
     * @throws Throwable when persisting fails
     */
    protected function persistEnvelopeClosing(AIUsageEnvelopeContext $envelopeContext): void
    {
    }

    /**
     * Override point: whether a cost booked under `$usageScope` is counted elsewhere and must stay out of the cost
     * recordings (e.g. a chat model's call whose cost the conversation stores on its own message row). Base: the scope
     * is listed in the config {@see self::CONFIG_KEY_SCOPES_COUNTED_ELSEWHERE} (a module or the application ships the
     * list).
     *
     * @param string $usageScope The usage scope of the booked cost
     * @return bool
     */
    protected function isBookedCostCountedElsewhere(string $usageScope): bool
    {
        $usageScopesCountedElsewhere = Config::get(static::CONFIG_KEY_SCOPES_COUNTED_ELSEWHERE);
        return is_array($usageScopesCountedElsewhere) && in_array($usageScope, $usageScopesCountedElsewhere, true);
    }

    /**
     * Closes the innermost envelope: persists the closing (failure swallowed), forgets the context and its grant.
     *
     * @return void
     */
    protected function closeInnermostEnvelope(): void
    {
        $envelopeId = array_key_last($this->envelopeContextsById);
        if ($envelopeId === null) {
            return;
        }
        $envelopeContext = $this->envelopeContextsById[$envelopeId];
        try {
            $this->persistEnvelopeClosing($envelopeContext);
        } catch (Throwable) {
            // closing in memory never depends on the usage log
        } finally {
            unset($this->envelopeContextsById[$envelopeId], $this->budgetBypassGrantsByEnvelopeId[$envelopeId]);
        }
    }

    /**
     * Logs that an outer envelope is closed while envelopes opened inside it are still open (their close was skipped);
     * they are closed with it. Never throws.
     *
     * @param int $targetEnvelopeId The envelope being closed
     * @return void
     */
    protected function logEnvelopesClosedWithAnOuterEnvelope(int $targetEnvelopeId): void
    {
        try {
            $innerEnvelopeIds = array_slice(
                array_keys($this->envelopeContextsById),
                (int)array_search($targetEnvelopeId, array_keys($this->envelopeContextsById), true) + 1
            );
            DDDService::instance()->getLogger()->warning(
                "AIUsageEnvelopesService: envelope $targetEnvelopeId closed while the envelopes opened inside it were still open ("
                . implode(', ', $innerEnvelopeIds) . '); closing them with it'
            );
        } catch (Throwable) {
            // logging is best effort
        }
    }
}

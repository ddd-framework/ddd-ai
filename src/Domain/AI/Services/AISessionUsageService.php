<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\AI\Entities\ModelCalls\AIModelCallUsage;
use DDD\Domain\AI\Entities\Models\Settings\AIModelSetting;
use DDD\Domain\Common\Entities\Money\MoneyAmount;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * Books the time of a session that a model bills by the second (a realtime voice session) through the same usage
 * recorder as a model call billed by tokens ({@see AIModelCallUsageRecorderService::recordModelCallUsage()}): the
 * usage carries the seconds in {@see AIModelCallUsage::$sessionSeconds}, the cost is the seconds times the model's
 * catalog price per second (`costsPerSessionSecondInUSD`, {@see AIModelSetting::$costsPerSessionSecond}) or a price
 * the caller passes. Like a token answer, a session is booked only when its cost is known.
 *
 * The caller books under the usage scope and billed account of the session ({@see AIModelCall}) and inside the
 * envelope it opened for the session; a long session may book in slices (every slice is one usage record).
 */
class AISessionUsageService extends Service
{
    /**
     * Books `$sessionSeconds` seconds of a session.
     *
     * @param AIModelCall $aiModelCall The session as the recorder sees it: repo or session class, usage scope, model,
     *                                 billed account
     * @param float $sessionSeconds The seconds to book (a negative value counts as 0)
     * @param MoneyAmount|null $costsPerSessionSecond A price per second that replaces the catalog's
     * @return AIModelCallUsage The usage; its `costs` is null when no price is known (then nothing was booked)
     */
    public function recordSessionSeconds(AIModelCall $aiModelCall, float $sessionSeconds, ?MoneyAmount $costsPerSessionSecond = null): AIModelCallUsage
    {
        $sessionSeconds = max(0.0, $sessionSeconds);
        $aiModelCallUsage = new AIModelCallUsage(
            costs: $this->calculateCostsOfSessionSeconds($aiModelCall, $sessionSeconds, $costsPerSessionSecond),
            sessionSeconds: $sessionSeconds,
        );
        if ($aiModelCallUsage->costs === null) {
            return $aiModelCallUsage;
        }
        $this->getAIModelCallUsageRecorderService()->recordModelCallUsage($aiModelCall, $aiModelCallUsage);
        return $aiModelCallUsage;
    }

    /** The application's usage recorder (a same-path subclass of the host books into its ledger and budget pots) */
    protected function getAIModelCallUsageRecorderService(): AIModelCallUsageRecorderService
    {
        /** @var AIModelCallUsageRecorderService $aIModelCallUsageRecorderService */
        $aIModelCallUsageRecorderService = DDDService::instance()->getService(AIModelCallUsageRecorderService::class);
        return $aIModelCallUsageRecorderService;
    }

    /**
     * The cost of the seconds: the passed price, else the catalog price of the call's model; null without either.
     */
    public function calculateCostsOfSessionSeconds(AIModelCall $aiModelCall, float $sessionSeconds, ?MoneyAmount $costsPerSessionSecond = null): ?MoneyAmount
    {
        if ($costsPerSessionSecond !== null) {
            $aiModelSetting = new AIModelSetting();
            $aiModelSetting->costsPerSessionSecond = $costsPerSessionSecond;
            return $aiModelSetting->getEstimatedCostsForSessionSeconds($sessionSeconds);
        }
        $aiModelSetting = $aiModelCall->aiModel?->settings ?? null;
        return $aiModelSetting instanceof AIModelSetting ? $aiModelSetting->getEstimatedCostsForSessionSeconds($sessionSeconds) : null;
    }
}

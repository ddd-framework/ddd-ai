<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Models\Settings;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The carry-overs of a model's own earlier reasoning per wire API (catalog key
 * `agenticUseCase.previousReasoningByWireApi`), one element per wire API.
 *
 * @method AIModelWireApiPreviousReasoningCarryOver first()
 * @method AIModelWireApiPreviousReasoningCarryOver getByUniqueKey(string $uniqueKey)
 * @method AIModelWireApiPreviousReasoningCarryOver[] getElements()
 * @property AIModelWireApiPreviousReasoningCarryOver[] $elements
 */
class AIModelWireApiPreviousReasoningCarryOvers extends ObjectSet
{
    /**
     * The carry-over this set names for `$agentWireApi` (a known wire API in any case and with surrounding blanks, an
     * unknown one as written); null when the set names none for it.
     */
    public function getPreviousReasoningForAgentWireApi(string $agentWireApi): ?AIModelPreviousReasoningCarryOver
    {
        $normalizedAgentWireApi = static::normalizeAgentWireApi($agentWireApi);
        if ($normalizedAgentWireApi === '') {
            return null;
        }
        return $this->getByUniqueKey(AIModelWireApiPreviousReasoningCarryOver::uniqueKeyForAgentWireApi($normalizedAgentWireApi))?->previousReasoning;
    }

    /** The key of a wire API: the constant of a known one ({@see AIModel::canonicalAgentWireApi()}), else the trimmed value */
    public static function normalizeAgentWireApi(string $agentWireApi): string
    {
        return AIModel::canonicalAgentWireApi($agentWireApi) ?? trim($agentWireApi);
    }
}

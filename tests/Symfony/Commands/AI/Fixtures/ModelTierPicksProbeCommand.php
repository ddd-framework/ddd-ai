<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Symfony\Commands\AI\Fixtures;

use DDD\Domain\AI\Services\AIModelsService;
use DDD\Symfony\Commands\AI\ModelTierPicksCommand;

/** {@see ModelTierPicksCommand} over a catalog service the test supplies */
class ModelTierPicksProbeCommand extends ModelTierPicksCommand
{
    /** @var AIModelsService|null The catalog service the command gets; null = the service lookup */
    public ?AIModelsService $fixedAIModelsService = null;

    public function __construct(?AIModelsService $fixedAIModelsService = null)
    {
        $this->fixedAIModelsService = $fixedAIModelsService;
        parent::__construct();
    }

    protected function getAIModelsService(): AIModelsService
    {
        return $this->fixedAIModelsService ?? parent::getAIModelsService();
    }
}

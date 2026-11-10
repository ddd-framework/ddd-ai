<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Domain\AI\Entities\Models\Fixtures;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Services\AIModelsService;

/** Builds a model from one catalog entry a test supplies */
class CatalogProbeAIModelsService extends AIModelsService
{
    /**
     * @param array<string, mixed> $catalogEntry One entry of the model catalog
     */
    public function createAIModelFromCatalogEntry(string $modelName, array $catalogEntry): AIModel
    {
        return $this->createAIModelFromConfig($modelName, $catalogEntry);
    }
}

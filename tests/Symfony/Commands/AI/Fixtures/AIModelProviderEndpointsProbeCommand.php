<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Symfony\Commands\AI\Fixtures;

use DDD\Domain\AI\Services\AIModelsService;
use DDD\Symfony\Commands\AI\AIModelProviderEndpointsCommand;

/** {@see AIModelProviderEndpointsCommand} over a catalog service and an endpoints answer the test supplies; no network */
class AIModelProviderEndpointsProbeCommand extends AIModelProviderEndpointsCommand
{
    /** @var AIModelsService The catalog service the command gets */
    public AIModelsService $fixedAIModelsService;

    /** @var string|null The body the endpoints request answers with; null = the request fails */
    public ?string $endpointsResponseBody;

    /** @var string[] The URLs the command requested */
    public array $requestedUrls = [];

    public function __construct(AIModelsService $fixedAIModelsService, ?string $endpointsResponseBody)
    {
        $this->fixedAIModelsService = $fixedAIModelsService;
        $this->endpointsResponseBody = $endpointsResponseBody;
        parent::__construct();
    }

    protected function fetchProviderEndpointsJson(string $endpointsUrl): ?string
    {
        $this->requestedUrls[] = $endpointsUrl;
        return $this->endpointsResponseBody;
    }

    protected function getAIModelsService(): AIModelsService
    {
        return $this->fixedAIModelsService;
    }
}

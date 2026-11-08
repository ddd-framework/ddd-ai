<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Traits;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCallUsage;
use DDD\Domain\AI\Entities\Models\Settings\AIImageModelSetting;
use DDD\Domain\AI\Exceptions\AiBudgetExceededException;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Common\Entities\Money\MoneyAmount;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use Doctrine\DBAL\Exception;
use InvalidArgumentException;
use ReflectionException;

/**
 * An Argus repo backed by an image-generation model: sends `{model, prompt}`, maps the returned images, and hands the
 * cost the gateway reports to the application's usage recorder. The gates are the eligibility stage and the image
 * budget stage of {@see \DDD\Domain\AI\Services\AIModelCallGateService}; no usage-scope stage runs for images.
 */
trait ArgusAIImageModelTrait
{
    use ArgusAILanguageModelTrait;

    public const int DEFAULT_WIDTH = 1024;
    public const int DEFAULT_HEIGHT = 768;
    public const int DEFAULT_NUMBER_OF_IMAGES = 1;

    /** @var int Width of the image */
    public int $width = self::DEFAULT_WIDTH;

    /** @var int Height of the image */
    public int $height = self::DEFAULT_HEIGHT;

    /** @var int Number of images to generate */
    public int $numberOfImages = self::DEFAULT_NUMBER_OF_IMAGES;

    /**
     * @throws NotFoundException
     * @throws BadRequestException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws ReflectionException
     * @throws InternalErrorException
     * @throws Exception
     */
    public function handleLoadResponseInternal(
        mixed &$callResponseData = null,
        ?ArgusApiOperation &$apiOperation = null,
    ): void {
        $responseObject = null;
        if (isset($callResponseData->status) && ($callResponseData->status == 200 || $callResponseData->status == 'OK') && ($callResponseData->data ?? null)) {
            $responseObject = $callResponseData->data;
        }
        if (!$responseObject) {
            $this->postProcessLoadResponse($callResponseData, false);
            return;
        }
        $loadResult = $responseObject->images ?? [];
        if (!$loadResult) {
            $this->postProcessLoadResponse($callResponseData, false);
            return;
        }
        $this->applyLoadResult($loadResult);

        if (isset($responseObject->cost)) {
            $this->updateCostsForUsage($responseObject->cost);
        }
        $this->postProcessLoadResponse($callResponseData, true);
    }

    /**
     * @return array|array[]|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws NotFoundException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws \Psr\Cache\InvalidArgumentException|AiBudgetExceededException
     */
    protected function getLoadPayloadInternal(): ?array
    {
        $aiModel = $this->getAIModel();
        $aiModelCallGateService = $this->getAIModelCallGateService();
        $this->prepareBilledEntitiesBeforeGates();
        $aiModelCallGateService->refuseModelCallForIneligibleBilledScope($this->buildAIModelCall());
        if ($aiModelCallGateService->needsCostEstimateForBudgetCheck($this->buildAIModelCall())) {
            $aiModelCallGateService->refuseImageModelCallOverBudget(
                $this->buildAIModelCall(),
                $this->estimateCostsOfImageModelCall(),
            );
        }
        return [
            'body' => [
                'model' => $aiModel->externalId,
                'prompt' => $this->getAIPromptWithParametersApplied()->getPromptTextWithParametersApplied(),
            ]
        ];
    }

    /**
     * The estimated cost of this image batch ({@see AIImageModelSetting::getEstimatedCostsForImages()}); null when no
     * model is resolved or the model carries no image price.
     *
     * @throws ReflectionException
     */
    protected function estimateCostsOfImageModelCall(): ?MoneyAmount
    {
        $aiModelSettings = $this->aiModel?->settings ?? null;
        if (!$aiModelSettings instanceof AIImageModelSetting) {
            return null;
        }
        return $aiModelSettings->getEstimatedCostsForImages($this->numberOfImages, $this->width, $this->height);
    }

    /**
     * Hands the cost the image gateway reported to the application's usage recorder (no tokens for images).
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws Exception
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function updateCostsForUsage(float $costUsd): void
    {
        $this->aiCosts = new MoneyAmount($costUsd, 'USD');
        $this->getAIModelCallUsageRecorderService()->recordModelCallUsage(
            $this->buildAIModelCall(forUsageRecording: true),
            new AIModelCallUsage(costs: $this->aiCosts),
        );
    }
}

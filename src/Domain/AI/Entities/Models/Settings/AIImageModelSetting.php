<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Models\Settings;

use DDD\Domain\Common\Entities\Money\MoneyAmount;

/**
 * Settings for image generation models (e.g. FALAI, DALL-E, FluxAI, Gemini image models).
 *
 * Image models price per generated image rather than per token, so they carry a
 * single MoneyAmount field instead of token-rate triplets. Width/height/numberOfImages
 * are runtime parameters on the call (see ArgusAIImageModelTrait), not settings.
 */
class AIImageModelSetting extends AIModelSetting
{
    /** @var MoneyAmount|null Cost per generated image */
    public ?MoneyAmount $costsPerImageInUSD = null;

    /**
     * The estimated cost of generating `$numberOfImages` images of `$width` × `$height` pixels: the raw pixel volume
     * (3 bytes per pixel) in megabytes times the per-image price. Null when the model carries no per-image price.
     */
    public function getEstimatedCostsForImages(int $numberOfImages, int $width, int $height): ?MoneyAmount
    {
        if ($this->costsPerImageInUSD === null) {
            return null;
        }
        return new MoneyAmount(
            $numberOfImages * ($height * $width * 3) / (1024 * 1024) * $this->costsPerImageInUSD->amount,
            'USD'
        );
    }
}

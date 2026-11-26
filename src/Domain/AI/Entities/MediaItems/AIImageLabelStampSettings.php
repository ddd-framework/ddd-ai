<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MediaItems;

use InvalidArgumentException;
use LengthException;

/**
 * How the EU "AI" icon is stamped on an image: the corner, the disc size as a share of the shorter side with a minimum
 * in pixels, the margin as a share of the shorter side, the colour (automatic or pinned), the icon files per label
 * variant and icon colour, and the design version these numbers make up. Pure: built from the `Common.AIImageLabelStamp` config array and an icon folder, holds no
 * image and touches no service; the geometry and colour arithmetic of a stamp live here (the corner averaging
 * included: the stamper only reads the corner's pixels).
 *
 * Holds file paths only, is never serialized or persisted, and is therefore not a DDD value object.
 */
class AIImageLabelStampSettings
{
    /** @var string Corner: top right (the default) */
    public const string CORNER_TOP_RIGHT = 'TOP_RIGHT';

    /** @var string Corner: top left */
    public const string CORNER_TOP_LEFT = 'TOP_LEFT';

    /** @var string Corner: bottom right */
    public const string CORNER_BOTTOM_RIGHT = 'BOTTOM_RIGHT';

    /** @var string Corner: bottom left */
    public const string CORNER_BOTTOM_LEFT = 'BOTTOM_LEFT';

    /** @var string[] Every corner */
    public const array ALL_CORNERS = [
        self::CORNER_TOP_RIGHT,
        self::CORNER_TOP_LEFT,
        self::CORNER_BOTTOM_RIGHT,
        self::CORNER_BOTTOM_LEFT,
    ];

    /** @var string Colour choice: black on a light corner, white on a dark one */
    public const string COLOUR_AUTOMATIC = 'AUTOMATIC';

    /** @var string Colour choice: always the black icon */
    public const string COLOUR_BLACK = 'BLACK';

    /** @var string Colour choice: always the white icon */
    public const string COLOUR_WHITE = 'WHITE';

    /** @var string[] Every colour choice */
    public const array ALL_COLOUR_CHOICES = [
        self::COLOUR_AUTOMATIC,
        self::COLOUR_BLACK,
        self::COLOUR_WHITE,
    ];

    /** @var string Icon colour: the black disc with white letters */
    public const string ICON_COLOUR_BLACK = 'BLACK';

    /** @var string Icon colour: the white disc with dark letters */
    public const string ICON_COLOUR_WHITE = 'WHITE';

    /** @var string[] Every icon colour a variant needs a file for */
    public const array ALL_ICON_COLOURS = [
        self::ICON_COLOUR_BLACK,
        self::ICON_COLOUR_WHITE,
    ];

    /** @var float A corner whose Rec. 709 luma (0 to 1) is at least this is light and gets the black icon */
    public const float LIGHT_CORNER_LUMA_THRESHOLD = 0.5;

    /** @var float Rec. 709 luma weight of the red value */
    public const float REC_709_LUMA_RED_WEIGHT = 0.2126;

    /** @var float Rec. 709 luma weight of the green value */
    public const float REC_709_LUMA_GREEN_WEIGHT = 0.7152;

    /** @var float Rec. 709 luma weight of the blue value */
    public const float REC_709_LUMA_BLUE_WEIGHT = 0.0722;

    /** @var string Config key: one of the CORNER_* values */
    public const string CONFIG_KEY_CORNER = 'corner';

    /** @var string Config key: the disc diameter as a share of the displayed image's shorter side */
    public const string CONFIG_KEY_ICON_DIAMETER_SHARE = 'iconDiameterShareOfShorterSide';

    /** @var string Config key: the smallest disc diameter in pixels */
    public const string CONFIG_KEY_MINIMUM_ICON_DIAMETER = 'minimumIconDiameterInPixels';

    /** @var string Config key: the margin between the disc and the image edges as a share of the shorter side */
    public const string CONFIG_KEY_MARGIN_SHARE = 'marginShareOfShorterSide';

    /** @var string Config key: one of the COLOUR_* values */
    public const string CONFIG_KEY_COLOUR = 'colour';

    /** @var string Config key: per label variant, the icon file name per icon colour, in the icon folder */
    public const string CONFIG_KEY_ICON_FILE_NAMES_BY_VARIANT = 'iconFileNamesByVariant';

    /**
     * @var string Config key: the version of the stamp's design (an integer of at least 1), raised when the design
     *     changes; it names the stored labelled copies and the token claim that serves them
     */
    public const string CONFIG_KEY_DESIGN_VERSION = 'designVersion';

    /**
     * Only {@see fromConfigArray()} builds settings, so every instance passed its checks.
     *
     * @param array<string, array<string, string>> $iconFilePathsByVariantAndIconColour
     */
    final protected function __construct(
        public readonly string $corner,
        public readonly float $iconDiameterShareOfShorterSide,
        public readonly int $minimumIconDiameterInPixels,
        public readonly float $marginShareOfShorterSide,
        public readonly string $colourChoice,
        protected readonly array $iconFilePathsByVariantAndIconColour,
        public readonly int $designVersion,
    ) {
    }

    /**
     * Builds the settings from a `Common.AIImageLabelStamp` config array (keys: the CONFIG_KEY_* values); icon file
     * names are resolved in $iconDirectoryPath. Settings that could not stamp every image of every variant are
     * rejected here rather than failing a publication later.
     *
     * @param array<string, mixed> $stampConfig
     * @throws InvalidArgumentException When the corner or the colour choice is unknown, the diameter share is not
     *     above 0 and below 1, the minimum is not an integer of at least 1, the margin share is not from 0 to below
     *     0.5, diameter share + margin share is not below 1, the design version is not an integer of at least 1, a
     *     value of {@see AIImageLabelVariant::ALL_VARIANTS} lacks its black or white icon file name, or an icon file
     *     does not exist.
     */
    public static function fromConfigArray(array $stampConfig, string $iconDirectoryPath): static
    {
        $corner = static::readChoice($stampConfig, self::CONFIG_KEY_CORNER, self::ALL_CORNERS);
        $colourChoice = static::readChoice($stampConfig, self::CONFIG_KEY_COLOUR, self::ALL_COLOUR_CHOICES);
        $iconDiameterShare = static::readShare($stampConfig, self::CONFIG_KEY_ICON_DIAMETER_SHARE);
        if (!($iconDiameterShare > 0 && $iconDiameterShare < 1)) {
            throw new InvalidArgumentException(
                sprintf('%s must be above 0 and below 1; it is %s', self::CONFIG_KEY_ICON_DIAMETER_SHARE, var_export($iconDiameterShare, true))
            );
        }
        $minimumIconDiameter = $stampConfig[self::CONFIG_KEY_MINIMUM_ICON_DIAMETER] ?? null;
        if (!is_int($minimumIconDiameter) || $minimumIconDiameter < 1) {
            throw new InvalidArgumentException(
                sprintf('%s must be an integer of at least 1; it is %s', self::CONFIG_KEY_MINIMUM_ICON_DIAMETER, var_export($minimumIconDiameter, true))
            );
        }
        $marginShare = static::readShare($stampConfig, self::CONFIG_KEY_MARGIN_SHARE);
        if (!($marginShare >= 0 && $marginShare < 0.5)) {
            throw new InvalidArgumentException(
                sprintf('%s must be from 0 to below 0.5; it is %s', self::CONFIG_KEY_MARGIN_SHARE, var_export($marginShare, true))
            );
        }
        // a sum of 1 or more would leave no image large enough for the disc and its margin: every stamp would throw
        if ($iconDiameterShare + $marginShare >= 1) {
            throw new InvalidArgumentException(
                sprintf(
                    '%s + %s must be below 1; it is %s',
                    self::CONFIG_KEY_ICON_DIAMETER_SHARE,
                    self::CONFIG_KEY_MARGIN_SHARE,
                    var_export($iconDiameterShare + $marginShare, true),
                )
            );
        }
        $designVersion = $stampConfig[self::CONFIG_KEY_DESIGN_VERSION] ?? null;
        if (!is_int($designVersion) || $designVersion < 1) {
            throw new InvalidArgumentException(
                sprintf('%s must be an integer of at least 1; it is %s', self::CONFIG_KEY_DESIGN_VERSION, var_export($designVersion, true))
            );
        }
        return new static(
            $corner,
            $iconDiameterShare,
            $minimumIconDiameter,
            $marginShare,
            $colourChoice,
            static::buildIconFilePathsByVariantAndIconColour($stampConfig, $iconDirectoryPath),
            $designVersion,
        );
    }

    /**
     * The disc diameter in pixels: the diameter share of the displayed image's shorter side, rounded half away from
     * zero, and never below the minimum.
     */
    public function getIconDiameterForShorterSide(int $shorterSide): int
    {
        return max(
            $this->minimumIconDiameterInPixels,
            (int)round($this->iconDiameterShareOfShorterSide * $shorterSide, 0, PHP_ROUND_HALF_UP),
        );
    }

    /**
     * The margin in pixels between the disc and the two image edges of its corner: the margin share of the displayed
     * image's shorter side, rounded half away from zero.
     */
    public function getMarginForShorterSide(int $shorterSide): int
    {
        return (int)round($this->marginShareOfShorterSide * $shorterSide, 0, PHP_ROUND_HALF_UP);
    }

    /**
     * The square the disc is scaled into, in the configured corner of the image as it displays.
     *
     * @throws LengthException When the disc and its margin are longer than the shorter side.
     */
    public function buildIconSquareForDisplayedImageSize(int $displayedWidth, int $displayedHeight): AIImageLabelIconSquare
    {
        $shorterSide = min($displayedWidth, $displayedHeight);
        $iconDiameter = $this->getIconDiameterForShorterSide($shorterSide);
        $margin = $this->getMarginForShorterSide($shorterSide);
        if ($iconDiameter + $margin > $shorterSide) {
            throw new LengthException(
                sprintf(
                    'An image of %dx%d is too small for the icon: the %d px disc and its %d px margin need %d px on the shorter side',
                    $displayedWidth,
                    $displayedHeight,
                    $iconDiameter,
                    $margin,
                    $iconDiameter + $margin,
                )
            );
        }
        $left = in_array($this->corner, [self::CORNER_TOP_LEFT, self::CORNER_BOTTOM_LEFT], true)
            ? $margin
            : $displayedWidth - $margin - $iconDiameter;
        $top = in_array($this->corner, [self::CORNER_TOP_LEFT, self::CORNER_TOP_RIGHT], true)
            ? $margin
            : $displayedHeight - $margin - $iconDiameter;
        return new AIImageLabelIconSquare($left, $top, $iconDiameter);
    }

    /**
     * The icon colour of the automatic choice for the pixels of the square the disc will cover: each pixel is
     * flattened onto white (a transparent corner counts as white), the pixels are averaged and the mean colour decides
     * ({@see getIconColourForCornerColour()}).
     *
     * @param float[] $rgbaFloatSamples Red, green, blue and alpha of each pixel in turn, in sRGB, each from 0 to 1,
     *     gamma-encoded (alpha 1 for every pixel of an image without an alpha channel)
     * @throws InvalidArgumentException When the samples are empty or not whole RGBA pixels.
     */
    public function getIconColourForCornerSamples(array $rgbaFloatSamples): string
    {
        $sampleCount = count($rgbaFloatSamples);
        if ($sampleCount === 0 || $sampleCount % 4 !== 0) {
            throw new InvalidArgumentException(
                sprintf('The corner samples must be whole RGBA pixels; there are %d samples', $sampleCount)
            );
        }
        $redSum = 0.0;
        $greenSum = 0.0;
        $blueSum = 0.0;
        for ($sampleOffset = 0; $sampleOffset < $sampleCount; $sampleOffset += 4) {
            // over white: value × alpha + 1 × (1 − alpha)
            $transparency = 1.0 - $rgbaFloatSamples[$sampleOffset + 3];
            $redSum += $rgbaFloatSamples[$sampleOffset] * $rgbaFloatSamples[$sampleOffset + 3] + $transparency;
            $greenSum += $rgbaFloatSamples[$sampleOffset + 1] * $rgbaFloatSamples[$sampleOffset + 3] + $transparency;
            $blueSum += $rgbaFloatSamples[$sampleOffset + 2] * $rgbaFloatSamples[$sampleOffset + 3] + $transparency;
        }
        $pixelCount = intdiv($sampleCount, 4);
        return $this->getIconColourForCornerColour($redSum / $pixelCount, $greenSum / $pixelCount, $blueSum / $pixelCount);
    }

    /**
     * The icon colour of the automatic choice for the mean colour of the square the disc will cover: the black icon
     * on a light corner, the white icon on a dark one. Light is a Rec. 709 luma of {@see LIGHT_CORNER_LUMA_THRESHOLD}
     * or above, on the gamma-encoded values.
     *
     * The arguments are the mean sRGB colour of the square, flattened onto white, each from 0 to 1.
     */
    public function getIconColourForCornerColour(float $red, float $green, float $blue): string
    {
        $cornerLuma = self::REC_709_LUMA_RED_WEIGHT * $red
            + self::REC_709_LUMA_GREEN_WEIGHT * $green
            + self::REC_709_LUMA_BLUE_WEIGHT * $blue;
        return $cornerLuma >= self::LIGHT_CORNER_LUMA_THRESHOLD ? self::ICON_COLOUR_BLACK : self::ICON_COLOUR_WHITE;
    }

    /**
     * The icon colour a pinned colour choice stands for, or null when the colour is automatic (the corner decides).
     */
    public function getPinnedIconColour(): ?string
    {
        return match ($this->colourChoice) {
            self::COLOUR_BLACK => self::ICON_COLOUR_BLACK,
            self::COLOUR_WHITE => self::ICON_COLOUR_WHITE,
            default => null,
        };
    }

    /** Whether the settings hold the icon files of a label variant (then they hold one for every icon colour). */
    public function hasIconsForVariant(string $aiImageLabelVariant): bool
    {
        return isset($this->iconFilePathsByVariantAndIconColour[$aiImageLabelVariant]);
    }

    /**
     * The icon file path of a label variant in an icon colour.
     *
     * @param string $iconColour One of {@see ALL_ICON_COLOURS}
     * @throws InvalidArgumentException When the settings hold no icons for the variant, or the icon colour is unknown.
     */
    public function getIconFilePath(string $aiImageLabelVariant, string $iconColour): string
    {
        if (!$this->hasIconsForVariant($aiImageLabelVariant)) {
            throw new InvalidArgumentException(
                sprintf(
                    'The stamp settings hold no icons for the label variant %s; they hold icons for %s',
                    $aiImageLabelVariant,
                    implode(', ', array_keys($this->iconFilePathsByVariantAndIconColour)),
                )
            );
        }
        if (!isset($this->iconFilePathsByVariantAndIconColour[$aiImageLabelVariant][$iconColour])) {
            throw new InvalidArgumentException(
                sprintf('%s is not an icon colour; the icon colours are %s', $iconColour, implode(', ', self::ALL_ICON_COLOURS))
            );
        }
        return $this->iconFilePathsByVariantAndIconColour[$aiImageLabelVariant][$iconColour];
    }

    /**
     * @param array<string, mixed> $stampConfig
     * @param string[] $allowedValues
     * @throws InvalidArgumentException When the value is not one of $allowedValues.
     */
    protected static function readChoice(array $stampConfig, string $configKey, array $allowedValues): string
    {
        $configValue = $stampConfig[$configKey] ?? null;
        if (!in_array($configValue, $allowedValues, true)) {
            throw new InvalidArgumentException(
                sprintf('%s must be one of %s; it is %s', $configKey, implode(', ', $allowedValues), var_export($configValue, true))
            );
        }
        return $configValue;
    }

    /**
     * A share of the shorter side: an int or a float (config files write 0 and 1 as integers).
     *
     * @param array<string, mixed> $stampConfig
     * @throws InvalidArgumentException When the value is not a number.
     */
    protected static function readShare(array $stampConfig, string $configKey): float
    {
        $configValue = $stampConfig[$configKey] ?? null;
        if (!is_int($configValue) && !is_float($configValue)) {
            throw new InvalidArgumentException(
                sprintf('%s must be a number; it is %s', $configKey, var_export($configValue, true))
            );
        }
        return (float)$configValue;
    }

    /**
     * @param array<string, mixed> $stampConfig
     * @return array<string, array<string, string>> The icon file path by label variant and icon colour
     * @throws InvalidArgumentException When a variant lacks an icon file name or an icon file does not exist.
     */
    protected static function buildIconFilePathsByVariantAndIconColour(array $stampConfig, string $iconDirectoryPath): array
    {
        $iconFileNamesByVariant = $stampConfig[self::CONFIG_KEY_ICON_FILE_NAMES_BY_VARIANT] ?? null;
        $iconFilePathsByVariantAndIconColour = [];
        // every variant needs both colours: a missing one would fail every image of that variant at stamp time
        foreach (AIImageLabelVariant::ALL_VARIANTS as $aiImageLabelVariant) {
            foreach (self::ALL_ICON_COLOURS as $iconColour) {
                $iconFileName = is_array($iconFileNamesByVariant) && is_array($iconFileNamesByVariant[$aiImageLabelVariant] ?? null)
                    ? ($iconFileNamesByVariant[$aiImageLabelVariant][$iconColour] ?? null)
                    : null;
                if (!is_string($iconFileName) || $iconFileName === '') {
                    throw new InvalidArgumentException(
                        sprintf(
                            '%s must name the %s icon file of the variant %s',
                            self::CONFIG_KEY_ICON_FILE_NAMES_BY_VARIANT,
                            $iconColour,
                            $aiImageLabelVariant,
                        )
                    );
                }
                $iconFilePath = rtrim($iconDirectoryPath, '/') . '/' . $iconFileName;
                if (!is_file($iconFilePath)) {
                    throw new InvalidArgumentException(
                        sprintf('The %s icon file of the variant %s does not exist: %s', $iconColour, $aiImageLabelVariant, $iconFilePath)
                    );
                }
                $iconFilePathsByVariantAndIconColour[$aiImageLabelVariant][$iconColour] = $iconFilePath;
            }
        }
        return $iconFilePathsByVariantAndIconColour;
    }
}

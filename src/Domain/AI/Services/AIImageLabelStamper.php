<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services;

use DDD\Domain\AI\Entities\MediaItems\AIImageIptcTag;
use DDD\Domain\AI\Entities\MediaItems\AIImageLabelIconSquare;
use DDD\Domain\AI\Entities\MediaItems\AIImageLabelStampSettings;
use DDD\Domain\Common\Entities\MediaItems\ExifOrientation;
use DDD\Domain\Common\Entities\MediaItems\ImageFileStructure;
use DDD\Domain\Common\Entities\MediaItems\ImageMetadata;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Untrusted\RasterImageDecoder;
use Imagick;
use ImagickException;
use ImagickPixel;
use InvalidArgumentException;
use LengthException;
use UnexpectedValueException;

/**
 * Stamps the EU "AI" icon (the Commission's basic icon, committed under config/app/AI/AIImageLabelIcons) on a JPEG
 * or PNG: image bytes and a label variant in, labelled bytes out, in the same format and dimensions.
 *
 * - The disc goes into the configured corner of the image as it displays: a JPEG's EXIF orientation is followed (the
 *   square is mapped to stored pixels and the icon turned or mirrored to display upright); the pixels and the tag stay
 *   as they are. A PNG's `eXIf` orientation is ignored.
 * - Its colour is pinned or follows the corner (see {@see AIImageLabelStampSettings}).
 * - The output is written in the input's format with ImageMagick's own choice of quality and sampling (it keeps the
 *   input's estimate), and the input's EXIF, IPTC and XMP are carried through {@see ImageMetadata}. C2PA is not carried:
 *   a manifest cannot survive the re-encode.
 * - An input that carries no XMP a reader reads ({@see AIImageIptcTag::isCarryingXmp()}: an extended XMP APP1 alone
 *   does not count) gets our AI tag for the label variant instead ({@see AIImageIptcTag::getXmpPacketForLabelVariant()});
 *   an input with XMP keeps only its own.
 * - Every failure throws; the stamper never returns the unlabelled image.
 * - No decoded icon is kept between stamps: each stamp decodes its icon and clears every Imagick object it made; only
 *   the shipped settings are kept per process.
 *
 * The geometry, the orientation mapping and the colour arithmetic are pure and live on {@see AIImageLabelStampSettings},
 * {@see AIImageLabelIconSquare} and {@see ExifOrientation}; this class does the Imagick work. The stamper only stamps:
 * whether an image is labelled is decided by the label policy, outside this class.
 */
class AIImageLabelStamper extends Service
{
    /** @var string The config the shipped settings are read from, whole */
    public const string STAMP_CONFIG_PATH = 'AI.AIImageLabelStamp';

    /** @var string The folder of the committed icon files, relative to the app folder */
    public const string ICON_DIRECTORY_RELATIVE_TO_APP = 'config/app/AI/AIImageLabelIcons';

    /** @var AIImageLabelStampSettings|null The settings of the shipped config, built once per process */
    protected static ?AIImageLabelStampSettings $shippedStampSettings = null;

    /**
     * The image with the icon of the label variant stamped on it, in the same format and dimensions.
     *
     * @param string $aiImageLabelVariant One of {@see \DDD\Domain\AI\Entities\MediaItems\AIImageLabelVariant::ALL_VARIANTS}
     * @param AIImageLabelStampSettings|null $stampSettings Null: the settings of the shipped `Common.AIImageLabelStamp`
     * @throws InvalidArgumentException When the bytes are neither JPEG nor PNG, the settings hold no icons for the
     *     variant, or the shipped config is invalid.
     * @throws UnexpectedValueException When a mark of the image cannot be read (a PNG XMP chunk whose compressed text
     *     does not inflate), or the shipped config is missing.
     * @throws LengthException When the disc and its margin do not fit the image's shorter side.
     * @throws ImagickException When Imagick cannot decode the image or the icon file.
     */
    public function buildLabelledImageBytes(
        string $imageBytes,
        string $aiImageLabelVariant,
        ?AIImageLabelStampSettings $stampSettings = null,
    ): string {
        if (!ImageFileStructure::isJpeg($imageBytes) && !ImageFileStructure::isPng($imageBytes)) {
            throw new InvalidArgumentException('Only a JPEG or a PNG can be labelled');
        }
        $stampSettings ??= static::getShippedStampSettings();
        // an unknown variant fails before anything is decoded
        if (!$stampSettings->hasIconsForVariant($aiImageLabelVariant)) {
            throw new InvalidArgumentException(
                sprintf('The stamp settings hold no icons for the label variant %s', $aiImageLabelVariant)
            );
        }
        $imageMetadata = ImageMetadata::fromImageBytes($imageBytes);
        if (!AIImageIptcTag::isCarryingXmp($imageBytes)) {
            // our AI tag for the variant, also beside an extended XMP APP1 the input carries alone (readers ignore that);
            // an input with a main XMP packet keeps only its own
            $imageMetadata->xmpPacket ??= AIImageIptcTag::getXmpPacketForLabelVariant($aiImageLabelVariant);
        }
        $image = new Imagick();
        $icon = null;
        try {
            RasterImageDecoder::readBlob($imageBytes, $image);
            // the square is placed on the image as it displays, then mapped to the stored pixels the stamp writes
            $orientation = $this->readDisplayOrientation($image, $imageBytes);
            $storedWidth = $image->getImageWidth();
            $storedHeight = $image->getImageHeight();
            $isDisplayedSideways = ExifOrientation::isSideways($orientation);
            $displayedIconSquare = $stampSettings->buildIconSquareForDisplayedImageSize(
                $isDisplayedSideways ? $storedHeight : $storedWidth,
                $isDisplayedSideways ? $storedWidth : $storedHeight,
            );
            $storedIconSquare = $displayedIconSquare->buildStoredSquareForExifOrientation($orientation, $storedWidth, $storedHeight);
            $iconColour = $stampSettings->getPinnedIconColour()
                ?? $stampSettings->getIconColourForCornerSamples($this->readCornerRgbaSamples($image, $storedIconSquare));
            $icon = $this->buildScaledIcon(
                $stampSettings->getIconFilePath($aiImageLabelVariant, $iconColour),
                $displayedIconSquare->sideLength,
            );
            $this->turnDisplayedIconIntoStoredIcon($icon, $orientation);
            // composited unconverted onto CMYK, the icon's channels would be read as ink: its colours come out inverted
            if ($image->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
                $icon->transformImageColorspace(Imagick::COLORSPACE_CMYK);
            }
            $image->compositeImage($icon, Imagick::COMPOSITE_OVER, $storedIconSquare->left, $storedIconSquare->top);
            $labelledImageBytes = $image->getImageBlob();
        } finally {
            $icon?->clear();
            $image->clear();
        }
        // XMP cannot pass through ImageMagick (no XML delegate in production); EXIF and IPTC it keeps are not doubled
        return $imageMetadata->buildImageBytesWithMissingMetadataInserted($labelledImageBytes);
    }

    /**
     * The design version of the shipped config ({@see AIImageLabelStampSettings::$designVersion}): the version a
     * labelled copy built with the shipped settings is stored and linked under.
     *
     * @throws UnexpectedValueException When the config is missing.
     * @throws InvalidArgumentException When the config is invalid (see {@see AIImageLabelStampSettings::fromConfigArray()}).
     */
    public function getShippedDesignVersion(): int
    {
        return static::getShippedStampSettings()->designVersion;
    }

    /**
     * The settings of the shipped config, held for the process once {@see buildShippedStampSettings()} built them.
     *
     * @throws UnexpectedValueException When the config is missing.
     * @throws InvalidArgumentException When the config is invalid (see {@see AIImageLabelStampSettings::fromConfigArray()}).
     */
    protected static function getShippedStampSettings(): AIImageLabelStampSettings
    {
        return static::$shippedStampSettings ??= static::buildShippedStampSettings();
    }

    /**
     * The settings of the shipped config, with the icon files in the committed folder. The config is read whole:
     * Config::get() caches no falsy leaf (a margin share of 0), and its first uncached read of a key rebuilds the
     * cached `Common` subtree.
     *
     * @throws UnexpectedValueException When the config is missing.
     * @throws InvalidArgumentException When the config is invalid (see {@see AIImageLabelStampSettings::fromConfigArray()}).
     */
    protected static function buildShippedStampSettings(): AIImageLabelStampSettings
    {
        $stampConfig = Config::get(self::STAMP_CONFIG_PATH);
        if (!is_array($stampConfig)) {
            throw new UnexpectedValueException('The config ' . self::STAMP_CONFIG_PATH . ' is missing');
        }
        // src/Domain/Common/Services → app/
        return AIImageLabelStampSettings::fromConfigArray(
            $stampConfig,
            dirname(__DIR__, 4) . '/' . self::ICON_DIRECTORY_RELATIVE_TO_APP,
        );
    }

    /**
     * The EXIF orientation a viewer turns the image upright by: read from a JPEG (0, undefined, and any value outside
     * 1 to 8 count as {@see ExifOrientation::UPRIGHT}); always upright for a PNG, whose `eXIf` orientation is ignored
     * on purpose (ImageMagick reads it, but our AI images never carry one).
     */
    protected function readDisplayOrientation(Imagick $image, string $imageBytes): int
    {
        if (!ImageFileStructure::isJpeg($imageBytes)) {
            return ExifOrientation::UPRIGHT;
        }
        return ExifOrientation::getKnownOrientationForReadValue($image->getImageOrientation());
    }

    /**
     * Turns or mirrors the upright icon into the stored form, so it displays upright once a viewer applies the EXIF
     * orientation (the inverse of the viewer's turn).
     *
     * @throws ImagickException When Imagick cannot turn the icon.
     */
    protected function turnDisplayedIconIntoStoredIcon(Imagick $icon, int $orientation): void
    {
        $transparentBackground = new ImagickPixel('none');
        match ($orientation) {
            ExifOrientation::MIRRORED => $icon->flopImage(),
            ExifOrientation::UPSIDE_DOWN => $icon->rotateImage($transparentBackground, 180),
            ExifOrientation::FLIPPED => $icon->flipImage(),
            ExifOrientation::TRANSPOSED => $icon->transposeImage(),
            // the viewer turns 90° clockwise, so the stored icon is turned 90° anticlockwise
            ExifOrientation::TURNED_RIGHT => $icon->rotateImage($transparentBackground, 270),
            ExifOrientation::TRANSVERSED => $icon->transverseImage(),
            ExifOrientation::TURNED_LEFT => $icon->rotateImage($transparentBackground, 90),
            default => true,
        };
        $icon->setImagePage(0, 0, 0, 0);
    }

    /**
     * The pixels of the square the disc will cover, converted to sRGB (a CMYK or grayscale image included): red, green,
     * blue and alpha of each pixel in turn, each from 0 to 1, gamma-encoded. Alpha is 1 for every pixel of an image
     * without an alpha channel.
     *
     * @return float[]
     * @throws ImagickException When Imagick cannot read the region.
     */
    protected function readCornerRgbaSamples(Imagick $image, AIImageLabelIconSquare $iconSquare): array
    {
        $cornerImage = $image->getImageRegion($iconSquare->sideLength, $iconSquare->sideLength, $iconSquare->left, $iconSquare->top);
        try {
            $cornerImage->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            // @phpstan-ignore return.type (the Imagick stub declares int[]; with PIXEL_FLOAT the extension returns floats)
            return $cornerImage->exportImagePixels(0, 0, $iconSquare->sideLength, $iconSquare->sideLength, 'RGBA', Imagick::PIXEL_FLOAT);
        } finally {
            $cornerImage->clear();
        }
    }

    /**
     * The icon file decoded, cropped to the bounding box of its pixels with alpha above 0 (the visible disc: each file
     * is a 2363 px canvas around a 1524 px disc with uneven transparent borders) and scaled to the diameter. When the
     * crop or the scaling fails, the decoded icon is cleared before the exception leaves.
     *
     * @throws ImagickException When Imagick cannot decode, crop or scale the icon file.
     */
    protected function buildScaledIcon(string $iconFilePath, int $iconDiameter): Imagick
    {
        $icon = new Imagick($iconFilePath);
        $isIconReturned = false;
        try {
            $alphaChannel = clone $icon;
            try {
                // the alpha channel as a gray image: trimming its fully transparent (black) border gives the alpha box
                $alphaChannel->separateImageChannel(Imagick::CHANNEL_ALPHA);
                $alphaChannel->trimImage(0);
                $alphaBox = $alphaChannel->getImagePage();
                $icon->cropImage($alphaChannel->getImageWidth(), $alphaChannel->getImageHeight(), $alphaBox['x'], $alphaBox['y']);
            } finally {
                $alphaChannel->clear();
            }
            $icon->setImagePage(0, 0, 0, 0);
            $icon->resizeImage($iconDiameter, $iconDiameter, Imagick::FILTER_LANCZOS, 1);
            $isIconReturned = true;
            return $icon;
        } finally {
            // the caller clears a returned icon; one that is not returned is cleared here
            if (!$isIconReturned) {
                $icon->clear();
            }
        }
    }
}

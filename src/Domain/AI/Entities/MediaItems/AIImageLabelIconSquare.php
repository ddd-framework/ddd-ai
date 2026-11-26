<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MediaItems;

use DDD\Domain\Common\Entities\MediaItems\ExifOrientation;
use DDD\Domain\Common\Entities\MediaItems\ImageMetadata;

/**
 * The square the EU "AI" disc is scaled into, in pixels of an image: its left and top edge and its side length. The
 * square covers the columns left to {@see getRight()} - 1 and the rows top to {@see getBottom()} - 1 (half-open
 * ranges). Pure: no Imagick, no service.
 *
 * Is never serialized or persisted, and is therefore not a DDD value object (the same ruling as {@see ImageMetadata}).
 */
class AIImageLabelIconSquare
{
    final public function __construct(
        public readonly int $left,
        public readonly int $top,
        public readonly int $sideLength,
    ) {
    }

    /** The first column right of the square. */
    public function getRight(): int
    {
        return $this->left + $this->sideLength;
    }

    /** The first row below the square. */
    public function getBottom(): int
    {
        return $this->top + $this->sideLength;
    }

    /**
     * This square, placed on the image as it displays, in the stored pixels a viewer turns or mirrors into that
     * display by the EXIF orientation: the square a stamp writes so the disc shows where this square is.
     *
     * @param int $exifOrientation One of the {@see ExifOrientation} values; any other value counts as upright
     */
    public function buildStoredSquareForExifOrientation(int $exifOrientation, int $storedWidth, int $storedHeight): static
    {
        [$storedLeft, $storedTop] = match ($exifOrientation) {
            ExifOrientation::MIRRORED => [$storedWidth - $this->left - $this->sideLength, $this->top],
            ExifOrientation::UPSIDE_DOWN => [$storedWidth - $this->left - $this->sideLength, $storedHeight - $this->top - $this->sideLength],
            ExifOrientation::FLIPPED => [$this->left, $storedHeight - $this->top - $this->sideLength],
            ExifOrientation::TRANSPOSED => [$this->top, $this->left],
            ExifOrientation::TURNED_RIGHT => [$this->top, $storedHeight - $this->left - $this->sideLength],
            ExifOrientation::TRANSVERSED => [$storedWidth - $this->top - $this->sideLength, $storedHeight - $this->left - $this->sideLength],
            ExifOrientation::TURNED_LEFT => [$storedWidth - $this->top - $this->sideLength, $this->left],
            default => [$this->left, $this->top],
        };
        return new static($storedLeft, $storedTop, $this->sideLength);
    }
}

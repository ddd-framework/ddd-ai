<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Domain\AI\Entities\MediaItems;

use DDD\Testing\MediaItems\ImageMarkFixtureBuilder;
use DDD\Testing\MediaItems\ImageStructureWalker;

/**
 * The assertions behind the words of the criteria, read with the tests' own {@see ImageStructureWalker} and
 * never through the code under test:
 *
 * - "holds exactly one XMP packet P": a JPEG has exactly one main XMP APP1 and no other XMP APP1, whose payload after
 *   the identifier is P; a PNG has exactly one text chunk with keyword `XML:com.adobe.xmp` or `Raw profile type xmp`,
 *   an uncompressed `iTXt` with a valid CRC whose text is P;
 * - "tagged with P": holds exactly one XMP packet P, the JPEG APP1 sits directly after APP0 (directly after the Exif
 *   APP1 when there is one) and the PNG chunk directly after IHDR, and removing that one segment or chunk gives back
 *   the bytes the tag was written into;
 * - "carries no XMP packet": none of those segments or chunks;
 * - "tagged beside an extended XMP APP1" (criteria 31 and 32): one main XMP APP1 placed as the tag is placed, any
 *   extended XMP APP1 left as it was.
 *
 * The expected packets are never defined here: each test file writes its own literals.
 */
trait AIImageIptcTagAssertionsTrait
{
    /** @var string The keyword ImageMagick writes its own XMP text chunk under */
    protected const string RAW_PROFILE_XMP_PNG_KEYWORD = 'Raw profile type xmp';

    /**
     * Holds exactly one XMP packet, `$expectedXmpPacket`.
     */
    protected function assertHoldsExactlyOneXmpPacket(string $imageBytes, string $expectedXmpPacket, string $label): void
    {
        if (str_starts_with($imageBytes, "\xFF\xD8")) {
            self::assertCount(
                0,
                ImageStructureWalker::listJpegSegmentsOfKind($imageBytes, ImageStructureWalker::JPEG_KIND_EXTENDED_XMP),
                "{$label}: no extended XMP APP1",
            );
            $mainXmpSegments = ImageStructureWalker::listJpegSegmentsOfKind($imageBytes, ImageStructureWalker::JPEG_KIND_XMP);
            self::assertCount(1, $mainXmpSegments, "{$label}: exactly one main XMP APP1");
            self::assertSame(
                ImageMarkFixtureBuilder::XMP_IDENTIFIER . $expectedXmpPacket,
                $mainXmpSegments[0]['payload'],
                "{$label}: the main XMP APP1 holds the expected packet, whole",
            );
            return;
        }
        $xmpTextChunks = static::listPngXmpTextChunksOfEitherKeyword($imageBytes);
        self::assertCount(1, $xmpTextChunks, "{$label}: exactly one XMP text chunk (XML:com.adobe.xmp or Raw profile type xmp)");
        self::assertSame('iTXt', $xmpTextChunks[0]['type'], "{$label}: the XMP chunk is an iTXt");
        self::assertTrue($xmpTextChunks[0]['hasValidCrc'], "{$label}: the XMP chunk's CRC is valid");
        self::assertStringStartsWith(
            ImageMarkFixtureBuilder::XMP_PNG_KEYWORD . "\0" . "\x00",
            $xmpTextChunks[0]['data'],
            "{$label}: the XMP iTXt has keyword XML:com.adobe.xmp and is uncompressed",
        );
        self::assertSame(
            $expectedXmpPacket,
            ImageStructureWalker::decodePngXmpPacket($xmpTextChunks[0]),
            "{$label}: the XMP iTXt's text is the expected packet, whole",
        );
    }

    /**
     * Carries no XMP packet in any form: no main or extended XMP APP1 in a JPEG, no XMP text chunk in a PNG.
     */
    protected function assertCarriesNoXmpPacket(string $imageBytes, string $label): void
    {
        if (str_starts_with($imageBytes, "\xFF\xD8")) {
            foreach ([ImageStructureWalker::JPEG_KIND_XMP, ImageStructureWalker::JPEG_KIND_EXTENDED_XMP] as $kind) {
                self::assertCount(0, ImageStructureWalker::listJpegSegmentsOfKind($imageBytes, $kind), "{$label}: no {$kind}");
            }
            return;
        }
        self::assertSame([], static::listPngXmpTextChunksOfEitherKeyword($imageBytes), "{$label}: no XMP text chunk");
    }

    /**
     * Carries `$expectedXmpPacket` (a stamper output, whose pre-insert bytes never leave the stamper): holds exactly one
     * XMP packet, the expected one, placed as the tag is placed ({@see buildImageBytesWithoutThePlacedTag()}).
     */
    protected function assertCarriesThePacket(string $imageBytes, string $expectedXmpPacket, string $label): void
    {
        $this->assertHoldsExactlyOneXmpPacket($imageBytes, $expectedXmpPacket, $label);
        $this->buildImageBytesWithoutThePlacedTag($imageBytes, $label);
    }

    /**
     * Tagged with `$expectedXmpPacket`: one packet, placed as the tag is placed, and removing it gives back
     * `$untaggedJpegBytes` byte for byte.
     */
    protected function assertJpegIsTaggedWith(
        string $taggedJpegBytes,
        string $untaggedJpegBytes,
        string $expectedXmpPacket,
        string $label,
    ): void {
        $this->assertHoldsExactlyOneXmpPacket($taggedJpegBytes, $expectedXmpPacket, $label);
        self::assertSame(
            hash('sha256', $untaggedJpegBytes),
            hash('sha256', $this->buildImageBytesWithoutThePlacedTag($taggedJpegBytes, $label)),
            "{$label}: removing the XMP APP1 gives back the untagged bytes, byte for byte",
        );
    }

    /**
     * Tagged with `$expectedXmpPacket` beside an extended XMP APP1 that no main packet points at: exactly one main XMP
     * APP1, whose payload after the identifier is the packet, placed as the tag is placed, and removing it gives back
     * `$untaggedJpegBytes` byte for byte, so every extended XMP APP1 stays as it was. Read with the walker only: unlike
     * {@see assertJpegIsTaggedWith()} and {@see assertHoldsExactlyOneXmpPacket()}, it allows extended segments.
     */
    protected function assertJpegIsTaggedWithMainXmpPacketBesideItsExtendedXmp(
        string $taggedJpegBytes,
        string $untaggedJpegBytes,
        string $expectedXmpPacket,
        string $label,
    ): void {
        $mainXmpSegments = ImageStructureWalker::listJpegSegmentsOfKind($taggedJpegBytes, ImageStructureWalker::JPEG_KIND_XMP);
        self::assertCount(1, $mainXmpSegments, "{$label}: exactly one main XMP APP1");
        self::assertSame(
            ImageMarkFixtureBuilder::XMP_IDENTIFIER . $expectedXmpPacket,
            $mainXmpSegments[0]['payload'],
            "{$label}: the main XMP APP1 holds the expected packet, whole",
        );
        self::assertSame(
            hash('sha256', $untaggedJpegBytes),
            hash('sha256', $this->buildImageBytesWithoutThePlacedTag($taggedJpegBytes, $label)),
            "{$label}: removing the main XMP APP1 gives back the untagged bytes, byte for byte (the extended XMP APP1 unchanged)",
        );
    }

    /**
     * Tagged with `$expectedXmpPacket`: one packet, its chunk directly after IHDR. Returns the PNG with that chunk
     * removed; when `$untaggedPngBytes` is given, that must be it byte for byte.
     */
    protected function assertPngIsTaggedWith(
        string $taggedPngBytes,
        ?string $untaggedPngBytes,
        string $expectedXmpPacket,
        string $label,
    ): string {
        $this->assertHoldsExactlyOneXmpPacket($taggedPngBytes, $expectedXmpPacket, $label);
        $pngBytesWithoutTheTag = $this->buildImageBytesWithoutThePlacedTag($taggedPngBytes, $label);
        if ($untaggedPngBytes !== null) {
            self::assertSame(
                hash('sha256', $untaggedPngBytes),
                hash('sha256', $pngBytesWithoutTheTag),
                "{$label}: removing the XMP chunk gives back the untagged bytes, byte for byte",
            );
        }
        return $pngBytesWithoutTheTag;
    }

    /**
     * The XMP segment or chunk sits where the tag goes: in a JPEG the main XMP APP1 directly after the Exif APP1 when
     * there is one, else directly after APP0, with no byte between; in a PNG the XMP chunk directly after IHDR, the
     * first chunk. Returns the bytes with that one segment or chunk removed.
     */
    protected function buildImageBytesWithoutThePlacedTag(string $taggedImageBytes, string $label): string
    {
        if (!str_starts_with($taggedImageBytes, "\xFF\xD8")) {
            $chunks = ImageStructureWalker::listPngChunks($taggedImageBytes);
            self::assertSame('IHDR', $chunks[0]['type'], "{$label}: the first chunk is IHDR");
            $xmpChunks = static::listPngXmpTextChunksOfEitherKeyword($taggedImageBytes);
            self::assertNotSame([], $xmpChunks, "{$label}: an XMP chunk is there");
            self::assertSame($chunks[1]['offset'], $xmpChunks[0]['offset'], "{$label}: the XMP chunk sits directly after IHDR");
            return ImageStructureWalker::buildPngWithoutChunks($taggedImageBytes, [$xmpChunks[0]]);
        }
        $segments = ImageStructureWalker::listJpegSegmentsUpToFirstScan($taggedImageBytes);
        $xmpSegmentIndex = array_search(ImageStructureWalker::JPEG_KIND_XMP, array_column($segments, 'kind'), true);
        self::assertIsInt($xmpSegmentIndex, "{$label}: the XMP APP1 is listed");
        self::assertGreaterThan(0, $xmpSegmentIndex, "{$label}: a segment precedes the XMP APP1");
        $expectedPrecedingKind = in_array(ImageStructureWalker::JPEG_KIND_EXIF, array_column($segments, 'kind'), true)
            ? ImageStructureWalker::JPEG_KIND_EXIF
            : ImageStructureWalker::JPEG_KIND_APP0;
        self::assertSame(
            $expectedPrecedingKind,
            $segments[$xmpSegmentIndex - 1]['kind'],
            "{$label}: the XMP APP1 sits directly after the {$expectedPrecedingKind}",
        );
        self::assertSame(
            $segments[$xmpSegmentIndex - 1]['offset'] + $segments[$xmpSegmentIndex - 1]['length'],
            $segments[$xmpSegmentIndex]['offset'],
            "{$label}: no byte between the {$expectedPrecedingKind} and the XMP APP1",
        );
        return ImageStructureWalker::buildJpegWithoutSegments($taggedImageBytes, [$segments[$xmpSegmentIndex]]);
    }

    /**
     * Every `iTXt`, `zTXt` or `tEXt` chunk whose keyword is `XML:com.adobe.xmp` or `Raw profile type xmp`, in file
     * order.
     *
     * @return array<int, array{type: string, offset: int, length: int, data: string, hasValidCrc: bool}>
     */
    protected static function listPngXmpTextChunksOfEitherKeyword(string $pngBytes): array
    {
        return array_values(
            array_filter(
                ImageStructureWalker::listPngChunks($pngBytes),
                static fn(array $chunk): bool => in_array($chunk['type'], ['iTXt', 'zTXt', 'tEXt'], true)
                    && (
                        str_starts_with($chunk['data'], ImageMarkFixtureBuilder::XMP_PNG_KEYWORD . "\0")
                        || str_starts_with($chunk['data'], self::RAW_PROFILE_XMP_PNG_KEYWORD . "\0")
                    ),
            )
        );
    }
}

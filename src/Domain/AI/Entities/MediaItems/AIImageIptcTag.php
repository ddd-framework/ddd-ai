<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MediaItems;

use DDD\Domain\Common\Entities\MediaItems\ImageFileStructure;
use DDD\Domain\Common\Entities\MediaItems\ImageMetadata;
use InvalidArgumentException;

/**
 * Our own machine-readable AI tag of an image: one XMP packet holding only the IPTC digital source type of its AI kind,
 * written on the file bytes, without re-encoding, into a JPEG or PNG that carries neither a C2PA manifest nor XMP.
 * Pure: no repository, no service, no Imagick; static, like {@see ImageFileStructure}.
 *
 * - The packet is fixed per kind: no padding, no toolkit, no date, no id beyond the fixed XMP packet id, so every image
 *   of a kind gets the same bytes and nothing names a tool, a signer or a customer.
 * - A file that carries a C2PA manifest is never touched (added bytes would break the provider's signature), and the
 *   detection fails closed: a header or chunk walk that cannot be completed counts as a manifest.
 * - A file that carries XMP is never touched: its packet is kept as it is and no second packet is added. An extended
 *   XMP APP1 without a main XMP APP1 is no XMP to a reader (it ignores a segment no main packet points at), so such a
 *   JPEG gets the tag as its main packet and keeps the extended segment byte for byte.
 *
 * Holds raw binary, is never serialized or persisted, and is therefore not a DDD value object.
 */
class AIImageIptcTag
{
    /** @var string The IPTC digital source type of an image an AI model generated */
    public const string DIGITAL_SOURCE_TYPE_TRAINED_ALGORITHMIC_MEDIA
        = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';

    /** @var string The IPTC digital source type of an image edited with an AI model */
    public const string DIGITAL_SOURCE_TYPE_COMPOSITE_WITH_TRAINED_ALGORITHMIC_MEDIA
        = 'http://cv.iptc.org/newscodes/digitalsourcetype/compositeWithTrainedAlgorithmicMedia';

    /** @var array<string, string> The digital source type written for each AI image kind that gets a tag */
    public const array DIGITAL_SOURCE_TYPES_BY_AI_IMAGE_KIND = [
        AIImageKind::GENERATED => self::DIGITAL_SOURCE_TYPE_TRAINED_ALGORITHMIC_MEDIA,
        AIImageKind::EDITED => self::DIGITAL_SOURCE_TYPE_COMPOSITE_WITH_TRAINED_ALGORITHMIC_MEDIA,
    ];

    /** @var array<string, string> The digital source type written for each label variant (the stamper's input) */
    public const array DIGITAL_SOURCE_TYPES_BY_LABEL_VARIANT = [
        AIImageLabelVariant::GENERATED => self::DIGITAL_SOURCE_TYPE_TRAINED_ALGORITHMIC_MEDIA,
        AIImageLabelVariant::EDITED => self::DIGITAL_SOURCE_TYPE_COMPOSITE_WITH_TRAINED_ALGORITHMIC_MEDIA,
    ];

    /** @var int JPEG marker APP11, which carries the JUMBF boxes of a C2PA manifest */
    public const int JPEG_MARKER_APP11 = 0xEB;

    /** @var string The common identifier a JUMBF APP11 payload starts with */
    public const string JUMBF_APP11_IDENTIFIER = 'JP';

    /** @var string The PNG chunk type that carries a C2PA manifest */
    public const string C2PA_PNG_CHUNK_TYPE = 'caBX';

    /** @var string The PNG chunk type every PNG starts with */
    public const string PNG_HEADER_CHUNK_TYPE = 'IHDR';

    /** @var string The PNG chunk type every PNG ends with */
    public const string PNG_END_CHUNK_TYPE = 'IEND';

    /** @var string[] The PNG text chunk types an XMP packet can live in */
    public const array PNG_TEXT_CHUNK_TYPES = ['iTXt', 'zTXt', 'tEXt'];

    /**
     * @var string The keyword of ImageMagick's own XMP text chunk, which {@see ImageMetadata} does not read as XMP but
     *     which carries XMP all the same
     */
    public const string RAW_PROFILE_XMP_PNG_KEYWORD = 'Raw profile type xmp';

    /**
     * The fixed packet of an AI image kind: generated and edited have one; not AI, null and any other value have none.
     *
     * @param string|null $aiImageKind One of {@see AIImageKind::ALL_KINDS}, or anything else
     *
     * @return string|null
     */
    public static function getXmpPacketForAIImageKind(?string $aiImageKind): ?string
    {
        $digitalSourceType = self::DIGITAL_SOURCE_TYPES_BY_AI_IMAGE_KIND[$aiImageKind ?? ''] ?? null;
        return $digitalSourceType === null ? null : static::buildXmpPacketForDigitalSourceType($digitalSourceType);
    }

    /**
     * The fixed packet of a label variant: the same packets as the kinds generated and edited.
     *
     * @param string $aiImageLabelVariant One of {@see AIImageLabelVariant::ALL_VARIANTS}
     *
     * @return string
     * @throws InvalidArgumentException When $aiImageLabelVariant is not one of the variants.
     */
    public static function getXmpPacketForLabelVariant(string $aiImageLabelVariant): string
    {
        $digitalSourceType = self::DIGITAL_SOURCE_TYPES_BY_LABEL_VARIANT[$aiImageLabelVariant] ?? null;
        if ($digitalSourceType === null) {
            throw new InvalidArgumentException(sprintf(
                'Unknown AI image label variant "%s"; expected one of: %s.',
                $aiImageLabelVariant,
                implode(', ', AIImageLabelVariant::ALL_VARIANTS),
            ));
        }
        return static::buildXmpPacketForDigitalSourceType($digitalSourceType);
    }

    /**
     * Whether the image may carry a C2PA manifest. Conservative, and it fails closed; the label of a JUMBF box is never
     * parsed:
     *
     * - JPEG: an APP11 segment before the first SOS whose payload starts with the JUMBF identifier `JP`; or a segment
     *   walk that stops before it reaches an SOS (a TEM marker, a broken length, the end of the file), behind which a
     *   manifest could hide;
     * - PNG: a `caBX` chunk anywhere; or a chunk walk that does not start with IHDR or does not end at IEND.
     *
     * False for every other format.
     */
    public static function isCarryingC2paManifest(string $imageBytes): bool
    {
        if (ImageFileStructure::isJpeg($imageBytes)) {
            return static::isJpegCarryingC2paManifest($imageBytes);
        }
        if (ImageFileStructure::isPng($imageBytes)) {
            return static::isPngCarryingC2paManifest($imageBytes);
        }
        return false;
    }

    /**
     * Whether the image carries XMP a reader reads: a JPEG main XMP APP1 before the first SOS (an extended XMP APP1
     * alone does not count: readers ignore an extended segment that no main packet points at); a PNG text chunk
     * (`iTXt`, `zTXt`, `tEXt`) with keyword `XML:com.adobe.xmp` or `Raw profile type xmp`. Reads segment identifiers,
     * chunk types and keywords only, never the packet (a compressed text that does not inflate still counts). False
     * for every other format.
     */
    public static function isCarryingXmp(string $imageBytes): bool
    {
        if (ImageFileStructure::isJpeg($imageBytes)) {
            foreach (ImageFileStructure::listJpegSegmentsBeforeFirstScan($imageBytes) as $segment) {
                if (ImageMetadata::getJpegSegmentKind($segment['marker'], $segment['payload']) === ImageMetadata::JPEG_SEGMENT_KIND_XMP) {
                    return true;
                }
            }
            return false;
        }
        foreach (ImageFileStructure::listPngChunks($imageBytes) as $chunk) {
            if (
                in_array($chunk['type'], self::PNG_TEXT_CHUNK_TYPES, true)
                && (
                    str_starts_with($chunk['payload'], ImageMetadata::XMP_PNG_KEYWORD . "\0")
                    || str_starts_with($chunk['payload'], self::RAW_PROFILE_XMP_PNG_KEYWORD . "\0")
                )
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The image with the packet of `$aiImageKind` inserted (JPEG: a main XMP APP1 directly after APP0, or after the
     * Exif APP1 when there is one, ahead of an extended XMP APP1 the JPEG carries without a main one; PNG: an
     * uncompressed `iTXt` `XML:com.adobe.xmp` chunk directly after IHDR), every other byte as it was. The bytes come
     * back unchanged when the kind has no packet, the image is neither JPEG nor PNG, it may carry a C2PA manifest
     * ({@see isCarryingC2paManifest()}, which also covers a PNG that does not start with IHDR) or it carries XMP
     * ({@see isCarryingXmp()}).
     *
     * @param string $imageBytes The image file bytes
     * @param string|null $aiImageKind One of {@see AIImageKind::ALL_KINDS}, or anything else
     *
     * @return string
     */
    public static function buildImageBytesWithTagIfCarryingNeitherC2paNorXmp(string $imageBytes, ?string $aiImageKind): string
    {
        $xmpPacket = static::getXmpPacketForAIImageKind($aiImageKind);
        if ($xmpPacket === null) {
            return $imageBytes;
        }
        if (!ImageFileStructure::isJpeg($imageBytes) && !ImageFileStructure::isPng($imageBytes)) {
            return $imageBytes;
        }
        if (static::isCarryingC2paManifest($imageBytes) || static::isCarryingXmp($imageBytes)) {
            return $imageBytes;
        }
        $imageMetadata = new ImageMetadata();
        $imageMetadata->xmpPacket = $xmpPacket;
        return $imageMetadata->buildImageBytesWithMissingMetadataInserted($imageBytes);
    }

    /**
     * The packet: the XMP packet header with its byte order mark and the fixed packet id, one `rdf:Description` with
     * the `Iptc4xmpExt:DigitalSourceType` attribute, and the writable trailer; nothing else.
     */
    protected static function buildXmpPacketForDigitalSourceType(string $digitalSourceType): string
    {
        // "\u{FEFF}" is the byte order mark the packet header requires: the three bytes EF BB BF
        return '<?xpacket begin="' . "\u{FEFF}" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>'
            . '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
            . '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description rdf:about="" xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/"'
            . ' Iptc4xmpExt:DigitalSourceType="' . $digitalSourceType . '"/>'
            . '</rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
    }

    /**
     * A JUMBF APP11 segment before the first SOS, or a segment walk that ends anywhere but at an SOS marker (after
     * optional `FF` fill bytes).
     */
    protected static function isJpegCarryingC2paManifest(string $jpegBytes): bool
    {
        $endOfLastSegment = strlen(ImageFileStructure::JPEG_START_OF_IMAGE);
        foreach (ImageFileStructure::listJpegSegmentsBeforeFirstScan($jpegBytes) as $segment) {
            if (
                $segment['marker'] === self::JPEG_MARKER_APP11
                && str_starts_with($segment['payload'], self::JUMBF_APP11_IDENTIFIER)
            ) {
                return true;
            }
            $endOfLastSegment = $segment['offset'] + $segment['length'];
        }
        return !static::isJpegStartOfScanAt($jpegBytes, $endOfLastSegment);
    }

    /**
     * True when the bytes at `$offset` are an SOS marker, `FF DA`, after any number of `FF` fill bytes.
     */
    protected static function isJpegStartOfScanAt(string $jpegBytes, int $offset): bool
    {
        $byteLength = strlen($jpegBytes);
        if ($offset >= $byteLength || $jpegBytes[$offset] !== "\xFF") {
            return false;
        }
        while ($offset + 1 < $byteLength && $jpegBytes[$offset + 1] === "\xFF") {
            $offset++;
        }
        return $offset + 1 < $byteLength && ord($jpegBytes[$offset + 1]) === ImageFileStructure::JPEG_MARKER_START_OF_SCAN;
    }

    /**
     * A `caBX` chunk, or a chunk walk that does not start with IHDR or does not end at IEND (a chunk that runs past the
     * end of the file ends the walk early).
     */
    protected static function isPngCarryingC2paManifest(string $pngBytes): bool
    {
        $chunks = ImageFileStructure::listPngChunks($pngBytes);
        if ($chunks === [] || $chunks[0]['type'] !== self::PNG_HEADER_CHUNK_TYPE) {
            return true;
        }
        if ($chunks[count($chunks) - 1]['type'] !== self::PNG_END_CHUNK_TYPE) {
            return true;
        }
        foreach ($chunks as $chunk) {
            if ($chunk['type'] === self::C2PA_PNG_CHUNK_TYPE) {
                return true;
            }
        }
        return false;
    }
}

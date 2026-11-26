<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Domain\AI\Entities\MediaItems;

use DDD\Domain\AI\Entities\MediaItems\AIImageIptcTag;
use DDD\Domain\AI\Entities\MediaItems\AIImageKind;
use DDD\Testing\MediaItems\ImageMarkFixtureBuilder;
use DDD\Testing\MediaItems\ImageStructureWalker;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use LogicException;

/**
 * AIImageIptcTag writes, on the file bytes, one fixed XMP packet holding only the IPTC digital source type of an AI
 * image (generated or edited), and only into a JPEG or PNG that carries no C2PA manifest and no XMP; C2PA detection
 * fails closed. Pixels come from GD (no Imagick), marks are injected as bytes, and every result is read with the
 * test's own {@see ImageStructureWalker} through {@see AIImageIptcTagAssertionsTrait}.
 *
 * The expected packets are this file's own literals, the byte order mark written as the bytes EF BB BF. The
 * "criterion N" labels number the cases of the specification the class was written against.
 */
class AIImageIptcTagTest extends TestCase
{
    use AIImageIptcTagAssertionsTrait;

    /** @var string The packet of a generated image (393 bytes) */
    protected const string EXPECTED_GENERATED_PACKET = '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>'
        . '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
        . '<rdf:Description rdf:about="" xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/"'
        . ' Iptc4xmpExt:DigitalSourceType="http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia"/>'
        . '</rdf:RDF></x:xmpmeta><?xpacket end="w"?>';

    /** @var string The packet of an edited image (406 bytes) */
    protected const string EXPECTED_EDITED_PACKET = '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>'
        . '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
        . '<rdf:Description rdf:about="" xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/"'
        . ' Iptc4xmpExt:DigitalSourceType="http://cv.iptc.org/newscodes/digitalsourcetype/compositeWithTrainedAlgorithmicMedia"/>'
        . '</rdf:RDF></x:xmpmeta><?xpacket end="w"?>';

    /** @var string A packet differing from both expected ones: a camera photo's digital source type */
    protected const string DISTINCT_PACKET = '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>'
        . '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
        . '<rdf:Description rdf:about="" xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/"'
        . ' Iptc4xmpExt:DigitalSourceType="http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture"/>'
        . '</rdf:RDF></x:xmpmeta><?xpacket end="w"?>';

    /** @var string The kind values as the tag reads them (literals, not the AIImageKind constants) */
    protected const string KIND_GENERATED = 'GENERATED';
    protected const string KIND_EDITED = 'EDITED';
    protected const string KIND_NOT_AI = 'NOT_AI';

    /** @var int Width of the GD fixtures */
    protected const int WIDTH = 64;

    /** @var int Height of the GD fixtures */
    protected const int HEIGHT = 48;

    protected const string FIXTURE_JPEG_WITH_XMP = 'JPEG with a main XMP APP1 of the distinct packet';
    protected const string FIXTURE_JPEG_WITH_ONLY_EXTENDED_XMP = 'JPEG with only an extended XMP APP1 (no main XMP APP1)';
    protected const string FIXTURE_PNG_WITH_XMP_ITXT = 'PNG with an XMP iTXt of the distinct packet';
    protected const string FIXTURE_PNG_WITH_XMP_ZTXT = 'PNG with an XMP zTXt of the distinct packet';
    protected const string FIXTURE_PNG_WITH_XMP_TEXT = 'PNG with an XMP tEXt of the distinct packet';
    protected const string FIXTURE_PNG_WITH_RAW_PROFILE_TEXT = 'PNG with a Raw profile type xmp tEXt';
    protected const string FIXTURE_PNG_WITH_RAW_PROFILE_ZTXT = 'PNG with a Raw profile type xmp zTXt';
    protected const string FIXTURE_PNG_WITH_RAW_PROFILE_ITXT = 'PNG with a Raw profile type xmp iTXt';
    protected const string FIXTURE_JPEG_WITH_C2PA_APP11 = 'JPEG with an APP11 holding the fixture C2PA JUMBF box';
    protected const string FIXTURE_JPEG_WITH_UNLABELLED_JUMBF_APP11 = 'JPEG with an APP11 JP payload without a c2pa label';
    protected const string FIXTURE_PNG_WITH_CABX_AFTER_IHDR = 'PNG with caBX directly after IHDR';
    protected const string FIXTURE_PNG_WITH_CABX_BEFORE_IEND = 'PNG with caBX directly before IEND';
    protected const string FIXTURE_JPEG_WITH_TEM_MARKER = 'JPEG without marks with a TEM marker before the first SOS';
    protected const string FIXTURE_PNG_WITH_LAST_CHUNK_RUNNING_PAST_THE_END = 'PNG without marks whose last chunk runs past the end';
    protected const string FIXTURE_PNG_WITHOUT_IEND = 'PNG without marks and without IEND';
    protected const string FIXTURE_PNG_WHOSE_FIRST_CHUNK_IS_NOT_IHDR = 'PNG whose first chunk is not IHDR';

    // ---------------------------------------------------------------------------------------------------------------
    // Criterion 1: the packets
    // ---------------------------------------------------------------------------------------------------------------

    public function testThePacketOfEachKind(): void
    {
        $this->assertTheExpectedPacketLiterals();

        self::assertSame(self::EXPECTED_GENERATED_PACKET, AIImageIptcTag::getXmpPacketForAIImageKind(self::KIND_GENERATED), 'criterion 1: GENERATED');
        self::assertSame(self::EXPECTED_EDITED_PACKET, AIImageIptcTag::getXmpPacketForAIImageKind(self::KIND_EDITED), 'criterion 1: EDITED');
        self::assertNull(AIImageIptcTag::getXmpPacketForAIImageKind(self::KIND_NOT_AI), 'criterion 1: NOT_AI has no packet');
        self::assertNull(AIImageIptcTag::getXmpPacketForAIImageKind(null), 'criterion 1: no kind has no packet');
        self::assertNull(AIImageIptcTag::getXmpPacketForAIImageKind('UNKNOWN'), 'criterion 1: an unknown string has no packet');
    }

    public function testThePacketOfEachLabelVariant(): void
    {
        self::assertSame(self::EXPECTED_GENERATED_PACKET, AIImageIptcTag::getXmpPacketForLabelVariant('GENERATED'), 'criterion 1: variant GENERATED');
        self::assertSame(self::EXPECTED_EDITED_PACKET, AIImageIptcTag::getXmpPacketForLabelVariant('EDITED'), 'criterion 1: variant EDITED');
    }

    /**
     * @dataProvider provideStringsThatAreNoLabelVariant
     */
    public function testAnyOtherLabelVariantThrows(string $notALabelVariant): void
    {
        $this->expectException(InvalidArgumentException::class);

        AIImageIptcTag::getXmpPacketForLabelVariant($notALabelVariant);
    }

    /** @return iterable<string, array{string}> */
    public static function provideStringsThatAreNoLabelVariant(): iterable
    {
        yield 'NOT_AI' => ['NOT_AI'];
        yield 'empty' => [''];
        yield 'lower case' => ['generated'];
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Criteria 2-3: tagged
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * @dataProvider provideAIKindsWithTheirPackets
     */
    public function testJpegWithoutMarksIsTagged(string $aiImageKind, string $expectedXmpPacket): void
    {
        $untaggedJpeg = self::buildGdJpeg();
        $this->assertCarriesNoJpegMark($untaggedJpeg);

        $taggedJpeg = AIImageIptcTag::buildImageBytesWithTagIfCarryingNeitherC2paNorXmp($untaggedJpeg, $aiImageKind);

        $this->assertJpegIsTaggedWith($taggedJpeg, $untaggedJpeg, $expectedXmpPacket, "criterion 2: a JPEG without marks, {$aiImageKind}");
        self::assertSame([self::WIDTH, self::HEIGHT], array_slice((array)getimagesizefromstring($taggedJpeg), 0, 2), 'criterion 2: it decodes');
    }

    /**
     * @dataProvider provideAIKindsWithTheirPackets
     */
    public function testJpegWithOnlyExifIsTaggedDirectlyAfterTheExifApp1(string $aiImageKind, string $expectedXmpPacket): void
    {
        $untaggedJpeg = ImageMarkFixtureBuilder::insertIntoJpegAfterApp0OrSoi(
            self::buildGdJpeg(),
            ImageMarkFixtureBuilder::buildJpegSegment(ImageMarkFixtureBuilder::MARKER_APP1, ImageMarkFixtureBuilder::buildExifApp1Payload()),
        );
        self::assertSame(
            [ImageStructureWalker::JPEG_KIND_APP0, ImageStructureWalker::JPEG_KIND_EXIF],
            array_slice(ImageStructureWalker::listJpegSegmentKinds($untaggedJpeg), 0, 2),
            'PRECONDITION: APP0, then the Exif APP1',
        );
        $this->assertCarriesNoXmpPacket($untaggedJpeg, 'PRECONDITION: the JPEG');

        $taggedJpeg = AIImageIptcTag::buildImageBytesWithTagIfCarryingNeitherC2paNorXmp($untaggedJpeg, $aiImageKind);

        $this->assertJpegIsTaggedWith($taggedJpeg, $untaggedJpeg, $expectedXmpPacket, "criterion 2: a JPEG with an Exif APP1 only, {$aiImageKind}");
    }

    /**
     * @dataProvider provideAIKindsWithTheirPackets
     */
    public function testPngWithoutMarksIsTagged(string $aiImageKind, string $expectedXmpPacket): void
    {
        $untaggedPng = self::buildGdPng();
        $this->assertCarriesNoPngMark($untaggedPng);

        $taggedPng = AIImageIptcTag::buildImageBytesWithTagIfCarryingNeitherC2paNorXmp($untaggedPng, $aiImageKind);

        $this->assertPngIsTaggedWith($taggedPng, $untaggedPng, $expectedXmpPacket, "criterion 3: a PNG without marks, {$aiImageKind}");
        self::assertTrue(ImageStructureWalker::isWellFormedStillPng($taggedPng), 'criterion 3: the result is a well-formed PNG');
        self::assertSame([self::WIDTH, self::HEIGHT], array_slice((array)getimagesizefromstring($taggedPng), 0, 2), 'criterion 3: it decodes');
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideAIKindsWithTheirPackets(): iterable
    {
        yield 'GENERATED' => [self::KIND_GENERATED, self::EXPECTED_GENERATED_PACKET];
        yield 'EDITED' => [self::KIND_EDITED, self::EXPECTED_EDITED_PACKET];
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Criterion 4: no packet for the kind
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * @dataProvider provideKindsWithoutPacket
     */
    public function testNoTagWithoutAnAIKind(?string $aiImageKind): void
    {
        $jpegBytes = self::buildGdJpeg();
        $pngBytes = self::buildGdPng();
        $this->assertCarriesNoJpegMark($jpegBytes);
        $this->assertCarriesNoPngMark($pngBytes);

        self::assertSame($jpegBytes, AIImageIptcTag::buildImageBytesWithTagIfCarryingNeitherC2paNorXmp($jpegBytes, $aiImageKind), 'criterion 4: the JPEG is unchanged');
        self::assertSame($pngBytes, AIImageIptcTag::buildImageBytesWithTagIfCarryingNeitherC2paNorXmp($pngBytes, $aiImageKind), 'criterion 4: the PNG is unchanged');
    }

    /** @return iterable<string, array{string|null}> */
    public static function provideKindsWithoutPacket(): iterable
    {
        yield 'NOT_AI' => [self::KIND_NOT_AI];
        yield 'null' => [null];
        yield 'unknown' => ['UNKNOWN'];
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Criterion 5: existing XMP
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * @dataProvider provideFixturesCarryingOnlyXmp
     */
    public function testImageCarryingOnlyXmpIsUnchanged(string $fixtureName): void
    {
        $imageBytes = $this->buildCheckedFixture($fixtureName);

        foreach ([self::KIND_GENERATED, self::KIND_EDITED] as $aiImageKind) {
            self::assertSame(
                hash('sha256', $imageBytes),
                hash('sha256', AIImageIptcTag::buildImageBytesWithTagIfCarryingNeitherC2paNorXmp($imageBytes, $aiImageKind)),
                "criterion 5: {$fixtureName}, {$aiImageKind}: unchanged byte for byte",
            );
        }
        self::assertTrue(AIImageIptcTag::isCarryingXmp($imageBytes), "{$fixtureName}: carries XMP");
        self::assertFalse(AIImageIptcTag::isCarryingC2paManifest($imageBytes), "{$fixtureName}: carries no C2PA manifest");
    }

    /** @return iterable<string, array{string}> */
    public static function provideFixturesCarryingOnlyXmp(): iterable
    {
        foreach ([
            self::FIXTURE_JPEG_WITH_XMP,
            self::FIXTURE_PNG_WITH_XMP_ITXT,
            self::FIXTURE_PNG_WITH_XMP_ZTXT,
            self::FIXTURE_PNG_WITH_XMP_TEXT,
            self::FIXTURE_PNG_WITH_RAW_PROFILE_TEXT,
            self::FIXTURE_PNG_WITH_RAW_PROFILE_ZTXT,
            self::FIXTURE_PNG_WITH_RAW_PROFILE_ITXT,
        ] as $fixtureName) {
            yield $fixtureName => [$fixtureName];
        }
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Criterion 31: an extended XMP APP1 alone is no XMP (readers ignore it), so the image gets the tag beside it
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * @dataProvider provideAIKindsWithTheirPackets
     */
    public function testJpegWithOnlyExtendedXmpIsTaggedBesideIt(string $aiImageKind, string $expectedXmpPacket): void
    {
        $jpegWithOnlyExtendedXmp = $this->buildCheckedFixture(self::FIXTURE_JPEG_WITH_ONLY_EXTENDED_XMP);

        $taggedJpeg = AIImageIptcTag::buildImageBytesWithTagIfCarryingNeitherC2paNorXmp($jpegWithOnlyExtendedXmp, $aiImageKind);

        $this->assertJpegIsTaggedWithMainXmpPacketBesideItsExtendedXmp(
            $taggedJpeg,
            $jpegWithOnlyExtendedXmp,
            $expectedXmpPacket,
            "criterion 31: a JPEG whose only XMP is an extended XMP APP1, {$aiImageKind}",
        );
        self::assertFalse(
            AIImageIptcTag::isCarryingXmp($jpegWithOnlyExtendedXmp),
            'criterion 31: a JPEG whose only XMP is an extended XMP APP1 does not count as carrying XMP',
        );
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Criteria 6-7: C2PA, and C2PA detection failing closed
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * @dataProvider provideFixturesCarryingOnlyC2pa
     */
    public function testImageCarryingOnlyC2paIsUnchanged(string $fixtureName): void
    {
        $imageBytes = $this->buildCheckedFixture($fixtureName);

        foreach ([self::KIND_GENERATED, self::KIND_EDITED] as $aiImageKind) {
            self::assertSame(
                hash('sha256', $imageBytes),
                hash('sha256', AIImageIptcTag::buildImageBytesWithTagIfCarryingNeitherC2paNorXmp($imageBytes, $aiImageKind)),
                "criterion 6: {$fixtureName}, {$aiImageKind}: unchanged byte for byte",
            );
        }
        self::assertTrue(AIImageIptcTag::isCarryingC2paManifest($imageBytes), "criterion 6: {$fixtureName} carries a C2PA manifest");
    }

    /** @return iterable<string, array{string}> */
    public static function provideFixturesCarryingOnlyC2pa(): iterable
    {
        foreach ([
            self::FIXTURE_JPEG_WITH_C2PA_APP11,
            self::FIXTURE_JPEG_WITH_UNLABELLED_JUMBF_APP11,
            self::FIXTURE_PNG_WITH_CABX_AFTER_IHDR,
            self::FIXTURE_PNG_WITH_CABX_BEFORE_IEND,
        ] as $fixtureName) {
            yield $fixtureName => [$fixtureName];
        }
    }

    /**
     * @dataProvider provideFixturesWhoseWalkCannotBeCompleted
     */
    public function testImageWhoseWalkCannotBeCompletedIsUnchanged(string $fixtureName): void
    {
        $imageBytes = $this->buildCheckedFixture($fixtureName);

        self::assertSame(
            hash('sha256', $imageBytes),
            hash('sha256', AIImageIptcTag::buildImageBytesWithTagIfCarryingNeitherC2paNorXmp($imageBytes, self::KIND_GENERATED)),
            "criterion 7: {$fixtureName}, GENERATED: unchanged byte for byte",
        );
        self::assertTrue(AIImageIptcTag::isCarryingC2paManifest($imageBytes), "criterion 6: {$fixtureName} counts as carrying a C2PA manifest");
    }

    /** @return iterable<string, array{string}> */
    public static function provideFixturesWhoseWalkCannotBeCompleted(): iterable
    {
        foreach ([
            self::FIXTURE_JPEG_WITH_TEM_MARKER,
            self::FIXTURE_PNG_WITH_LAST_CHUNK_RUNNING_PAST_THE_END,
            self::FIXTURE_PNG_WITHOUT_IEND,
            self::FIXTURE_PNG_WHOSE_FIRST_CHUNK_IS_NOT_IHDR,
        ] as $fixtureName) {
            yield $fixtureName => [$fixtureName];
        }
    }

    public function testUnmarkedImagesCarryNoC2paManifest(): void
    {
        $jpegBytes = self::buildGdJpeg();
        $pngBytes = self::buildGdPng();
        $this->assertCarriesNoJpegMark($jpegBytes);
        $this->assertCarriesNoPngMark($pngBytes);

        self::assertFalse(AIImageIptcTag::isCarryingC2paManifest($jpegBytes), 'criterion 6: the unmarked JPEG of criterion 2');
        self::assertFalse(AIImageIptcTag::isCarryingC2paManifest($pngBytes), 'criterion 6: the unmarked PNG of criterion 3');
        self::assertFalse(AIImageIptcTag::isCarryingXmp($jpegBytes), 'the unmarked JPEG carries no XMP');
        self::assertFalse(AIImageIptcTag::isCarryingXmp($pngBytes), 'the unmarked PNG carries no XMP');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Criterion 8: other formats
    // ---------------------------------------------------------------------------------------------------------------

    public function testGifIsUnchanged(): void
    {
        $gifBytes = self::buildGdGif();
        self::assertSame('GIF', substr($gifBytes, 0, 3), 'PRECONDITION: GD wrote a GIF');

        self::assertSame($gifBytes, AIImageIptcTag::buildImageBytesWithTagIfCarryingNeitherC2paNorXmp($gifBytes, self::KIND_GENERATED), 'criterion 8: a GIF is unchanged');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Criterion 9: the shared fixture packet tells the input's XMP from the tag
    // ---------------------------------------------------------------------------------------------------------------

    public function testTheFixtureBuildersPacketDiffersFromBothTags(): void
    {
        $this->assertTheExpectedPacketLiterals();
        $fixturePacket = ImageMarkFixtureBuilder::buildXmpPacket();

        self::assertNotSame(self::EXPECTED_GENERATED_PACKET, $fixturePacket, 'criterion 9: the fixture packet is not the generated packet');
        self::assertNotSame(self::EXPECTED_EDITED_PACKET, $fixturePacket, 'criterion 9: the fixture packet is not the edited packet');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------------------------------------------

    /** The expected packet literals have the lengths the spec states, and the distinct packet differs from both. */
    protected function assertTheExpectedPacketLiterals(): void
    {
        self::assertSame(393, strlen(self::EXPECTED_GENERATED_PACKET), 'PRECONDITION: the generated packet literal is 393 bytes');
        self::assertSame(406, strlen(self::EXPECTED_EDITED_PACKET), 'PRECONDITION: the edited packet literal is 406 bytes');
        self::assertNotSame(self::EXPECTED_GENERATED_PACKET, self::DISTINCT_PACKET, 'PRECONDITION: the distinct packet is not the generated one');
        self::assertNotSame(self::EXPECTED_EDITED_PACKET, self::DISTINCT_PACKET, 'PRECONDITION: the distinct packet is not the edited one');
    }

    /** Builds the named fixture and asserts that it carries only the mark under test. */
    protected function buildCheckedFixture(string $fixtureName): string
    {
        $this->assertTheExpectedPacketLiterals();
        $gdJpeg = self::buildGdJpeg();
        $gdPng = self::buildGdPng();
        $this->assertCarriesNoJpegMark($gdJpeg);
        $this->assertCarriesNoPngMark($gdPng);
        switch ($fixtureName) {
            case self::FIXTURE_JPEG_WITH_XMP:
                $jpegBytes = ImageMarkFixtureBuilder::insertIntoJpegAfterApp0OrSoi(
                    $gdJpeg,
                    ImageMarkFixtureBuilder::buildJpegSegment(ImageMarkFixtureBuilder::MARKER_APP1, ImageMarkFixtureBuilder::buildXmpApp1Payload(self::DISTINCT_PACKET)),
                );
                $this->assertHoldsExactlyOneXmpPacket($jpegBytes, self::DISTINCT_PACKET, 'PRECONDITION: the JPEG');
                self::assertSame([], ImageStructureWalker::listJpegSegmentsOfKind($jpegBytes, ImageStructureWalker::JPEG_KIND_APP11), 'PRECONDITION: no APP11');
                return $jpegBytes;
            case self::FIXTURE_JPEG_WITH_ONLY_EXTENDED_XMP:
                $extendedXmpPayload = ImageMarkFixtureBuilder::buildExtendedXmpApp1Payload(self::DISTINCT_PACKET);
                $jpegBytes = ImageMarkFixtureBuilder::insertIntoJpegAfterApp0OrSoi(
                    $gdJpeg,
                    ImageMarkFixtureBuilder::buildJpegSegment(ImageMarkFixtureBuilder::MARKER_APP1, $extendedXmpPayload),
                );
                // carrying only that mark: one extended XMP APP1, and no main XMP, EXIF, IPTC or APP11
                self::assertSame(
                    [$extendedXmpPayload],
                    array_column(ImageStructureWalker::listJpegSegmentsOfKind($jpegBytes, ImageStructureWalker::JPEG_KIND_EXTENDED_XMP), 'payload'),
                    'PRECONDITION: one extended XMP APP1',
                );
                foreach ([
                    ImageStructureWalker::JPEG_KIND_XMP,
                    ImageStructureWalker::JPEG_KIND_EXIF,
                    ImageStructureWalker::JPEG_KIND_IPTC,
                    ImageStructureWalker::JPEG_KIND_APP11,
                ] as $kind) {
                    self::assertSame([], ImageStructureWalker::listJpegSegmentsOfKind($jpegBytes, $kind), "PRECONDITION: the JPEG carries no {$kind}");
                }
                return $jpegBytes;
            case self::FIXTURE_PNG_WITH_XMP_ITXT:
            case self::FIXTURE_PNG_WITH_XMP_ZTXT:
            case self::FIXTURE_PNG_WITH_XMP_TEXT:
                $chunkType = [
                    self::FIXTURE_PNG_WITH_XMP_ITXT => 'iTXt',
                    self::FIXTURE_PNG_WITH_XMP_ZTXT => 'zTXt',
                    self::FIXTURE_PNG_WITH_XMP_TEXT => 'tEXt',
                ][$fixtureName];
                $pngBytes = ImageMarkFixtureBuilder::insertIntoPngAfterIhdr(
                    $gdPng,
                    ImageMarkFixtureBuilder::buildPngXmpChunk(self::DISTINCT_PACKET, $chunkType, $chunkType === 'zTXt'),
                );
                self::assertSame([self::DISTINCT_PACKET], ImageStructureWalker::listPngXmpPackets($pngBytes), 'PRECONDITION: the PNG carries the distinct packet');
                self::assertSame([$chunkType], array_column(ImageStructureWalker::listPngXmpChunks($pngBytes), 'type'), "PRECONDITION: in one {$chunkType}");
                self::assertNotContains('caBX', ImageStructureWalker::listPngChunkTypes($pngBytes), 'PRECONDITION: no caBX');
                return $pngBytes;
            case self::FIXTURE_PNG_WITH_RAW_PROFILE_TEXT:
            case self::FIXTURE_PNG_WITH_RAW_PROFILE_ZTXT:
            case self::FIXTURE_PNG_WITH_RAW_PROFILE_ITXT:
                $chunkType = [
                    self::FIXTURE_PNG_WITH_RAW_PROFILE_TEXT => 'tEXt',
                    self::FIXTURE_PNG_WITH_RAW_PROFILE_ZTXT => 'zTXt',
                    self::FIXTURE_PNG_WITH_RAW_PROFILE_ITXT => 'iTXt',
                ][$fixtureName];
                $pngBytes = ImageMarkFixtureBuilder::insertIntoPngAfterIhdr($gdPng, self::buildRawProfileXmpChunk($chunkType, self::DISTINCT_PACKET));
                self::assertSame([], ImageStructureWalker::listPngXmpChunks($pngBytes), 'PRECONDITION: no XML:com.adobe.xmp chunk');
                $xmpTextChunks = self::listPngXmpTextChunksOfEitherKeyword($pngBytes);
                self::assertCount(1, $xmpTextChunks, 'PRECONDITION: one Raw profile type xmp chunk');
                self::assertSame($chunkType, $xmpTextChunks[0]['type'], "PRECONDITION: a {$chunkType}");
                self::assertStringStartsWith(self::RAW_PROFILE_XMP_PNG_KEYWORD . "\0", $xmpTextChunks[0]['data'], 'PRECONDITION: its keyword');
                self::assertTrue($xmpTextChunks[0]['hasValidCrc'], 'PRECONDITION: its CRC is valid');
                self::assertNotContains('caBX', ImageStructureWalker::listPngChunkTypes($pngBytes), 'PRECONDITION: no caBX');
                return $pngBytes;
            case self::FIXTURE_JPEG_WITH_C2PA_APP11:
            case self::FIXTURE_JPEG_WITH_UNLABELLED_JUMBF_APP11:
                $app11Payload = $fixtureName === self::FIXTURE_JPEG_WITH_C2PA_APP11
                    ? ImageMarkFixtureBuilder::buildJumbfApp11Payload()
                    : self::buildJumbfApp11PayloadWithoutC2paLabel();
                $jpegBytes = ImageMarkFixtureBuilder::insertIntoJpegAfterApp0OrSoi(
                    $gdJpeg,
                    ImageMarkFixtureBuilder::buildJpegSegment(ImageMarkFixtureBuilder::MARKER_APP11, $app11Payload),
                );
                self::assertSame(
                    [$app11Payload],
                    array_column(ImageStructureWalker::listJpegSegmentsOfKind($jpegBytes, ImageStructureWalker::JPEG_KIND_APP11), 'payload'),
                    'PRECONDITION: one APP11',
                );
                self::assertStringStartsWith('JP', $app11Payload, 'PRECONDITION: the APP11 payload starts with JP');
                if ($fixtureName === self::FIXTURE_JPEG_WITH_UNLABELLED_JUMBF_APP11) {
                    self::assertStringNotContainsString('c2pa', $app11Payload, 'PRECONDITION: no c2pa label');
                }
                $this->assertCarriesNoXmpPacket($jpegBytes, 'PRECONDITION: the JPEG');
                return $jpegBytes;
            case self::FIXTURE_PNG_WITH_CABX_AFTER_IHDR:
            case self::FIXTURE_PNG_WITH_CABX_BEFORE_IEND:
                $caBxChunk = ImageMarkFixtureBuilder::buildPngChunk('caBX', ImageMarkFixtureBuilder::buildJumbfBox());
                $pngBytes = $fixtureName === self::FIXTURE_PNG_WITH_CABX_AFTER_IHDR
                    ? ImageMarkFixtureBuilder::insertIntoPngAfterIhdr($gdPng, $caBxChunk)
                    : ImageMarkFixtureBuilder::insertIntoPngBeforeIend($gdPng, $caBxChunk);
                $chunkTypes = ImageStructureWalker::listPngChunkTypes($pngBytes);
                self::assertSame(1, count(array_keys($chunkTypes, 'caBX', true)), 'PRECONDITION: one caBX');
                self::assertSame(
                    $fixtureName === self::FIXTURE_PNG_WITH_CABX_AFTER_IHDR ? 1 : count($chunkTypes) - 2,
                    array_search('caBX', $chunkTypes, true),
                    'PRECONDITION: caBX ' . ($fixtureName === self::FIXTURE_PNG_WITH_CABX_AFTER_IHDR ? 'directly after IHDR' : 'directly before IEND'),
                );
                $this->assertCarriesNoXmpPacket($pngBytes, 'PRECONDITION: the PNG');
                return $pngBytes;
            case self::FIXTURE_JPEG_WITH_TEM_MARKER:
                // the walker cannot step over a TEM marker, so the test checks its own bytes: the GD JPEG, checked
                // above, with FF 01 inserted directly after its APP0
                $app0Segment = ImageStructureWalker::listJpegSegmentsOfKind($gdJpeg, ImageStructureWalker::JPEG_KIND_APP0)[0];
                $temOffset = $app0Segment['offset'] + $app0Segment['length'];
                $jpegBytes = substr($gdJpeg, 0, $temOffset) . "\xFF\x01" . substr($gdJpeg, $temOffset);
                self::assertSame("\xFF\x01", substr($jpegBytes, $temOffset, 2), 'PRECONDITION: FF 01 between the segments');
                self::assertSame($gdJpeg, substr($jpegBytes, 0, $temOffset) . substr($jpegBytes, $temOffset + 2), 'PRECONDITION: removing it gives back the unmarked JPEG');
                self::assertLessThan(
                    ImageStructureWalker::listJpegSegmentsOfKind($gdJpeg, ImageStructureWalker::JPEG_KIND_START_OF_SCAN)[0]['offset'],
                    $temOffset,
                    'PRECONDITION: the TEM marker comes before the first SOS',
                );
                return $jpegBytes;
            case self::FIXTURE_PNG_WITH_LAST_CHUNK_RUNNING_PAST_THE_END:
                $iendChunk = ImageStructureWalker::listPngChunksOfType($gdPng, 'IEND')[0];
                $pngBytes = substr($gdPng, 0, $iendChunk['offset']) . pack('N', 1000) . substr($gdPng, $iendChunk['offset'] + 4);
                self::assertSame(strlen($gdPng), strlen($pngBytes), 'PRECONDITION: only the IEND length field changed');
                self::assertGreaterThan(strlen($pngBytes), $iendChunk['offset'] + 12 + 1000, 'PRECONDITION: the last chunk runs past the end');
                return $pngBytes;
            case self::FIXTURE_PNG_WITHOUT_IEND:
                $iendChunk = ImageStructureWalker::listPngChunksOfType($gdPng, 'IEND')[0];
                self::assertSame(strlen($gdPng), $iendChunk['offset'] + $iendChunk['length'], 'PRECONDITION: IEND is the last chunk');
                return substr($gdPng, 0, $iendChunk['offset']);
            case self::FIXTURE_PNG_WHOSE_FIRST_CHUNK_IS_NOT_IHDR:
                $pngBytes = substr($gdPng, 0, 8)
                    . ImageMarkFixtureBuilder::buildPngChunk('tEXt', "Comment\0RC fixture")
                    . substr($gdPng, 8);
                $chunkTypes = ImageStructureWalker::listPngChunkTypes($pngBytes);
                self::assertSame(['tEXt', 'IHDR'], array_slice($chunkTypes, 0, 2), 'PRECONDITION: the first chunk is a tEXt, IHDR second');
                $this->assertCarriesNoXmpPacket($pngBytes, 'PRECONDITION: the PNG');
                self::assertNotContains('caBX', $chunkTypes, 'PRECONDITION: no caBX');
                return $pngBytes;
        }
        self::fail("No builder for the fixture {$fixtureName}");
    }

    protected function assertCarriesNoJpegMark(string $jpegBytes): void
    {
        foreach ([
            ImageStructureWalker::JPEG_KIND_EXIF,
            ImageStructureWalker::JPEG_KIND_XMP,
            ImageStructureWalker::JPEG_KIND_EXTENDED_XMP,
            ImageStructureWalker::JPEG_KIND_IPTC,
            ImageStructureWalker::JPEG_KIND_APP11,
        ] as $kind) {
            self::assertCount(0, ImageStructureWalker::listJpegSegmentsOfKind($jpegBytes, $kind), "PRECONDITION: the JPEG carries no {$kind}");
        }
        self::assertSame(ImageStructureWalker::JPEG_KIND_APP0, ImageStructureWalker::listJpegSegmentKinds($jpegBytes)[0], 'PRECONDITION: the JPEG starts with APP0');
    }

    protected function assertCarriesNoPngMark(string $pngBytes): void
    {
        self::assertTrue(ImageStructureWalker::isWellFormedStillPng($pngBytes), 'PRECONDITION: a well-formed PNG');
        $chunkTypes = ImageStructureWalker::listPngChunkTypes($pngBytes);
        self::assertNotContains('caBX', $chunkTypes, 'PRECONDITION: the PNG carries no caBX');
        self::assertNotContains('eXIf', $chunkTypes, 'PRECONDITION: the PNG carries no eXIf');
        $this->assertCarriesNoXmpPacket($pngBytes, 'PRECONDITION: the PNG');
    }

    /**
     * ImageMagick's own XMP text chunk: keyword `Raw profile type xmp`, then the profile as hexadecimal text, as a
     * `tEXt`, a `zTXt` (zlib-compressed) or an uncompressed `iTXt`.
     */
    protected static function buildRawProfileXmpChunk(string $chunkType, string $xmpPacket): string
    {
        $rawProfileText = "\nxmp\n" . sprintf('%8d', strlen($xmpPacket)) . "\n" . chunk_split(bin2hex($xmpPacket), 72, "\n");
        $keywordField = self::RAW_PROFILE_XMP_PNG_KEYWORD . "\0";
        return match ($chunkType) {
            'tEXt' => ImageMarkFixtureBuilder::buildPngChunk('tEXt', $keywordField . $rawProfileText),
            'zTXt' => ImageMarkFixtureBuilder::buildPngChunk('zTXt', $keywordField . "\x00" . gzcompress($rawProfileText)),
            'iTXt' => ImageMarkFixtureBuilder::buildPngChunk('iTXt', $keywordField . "\x00\x00" . "\0" . "\0" . $rawProfileText),
            default => throw new LogicException("{$chunkType} is not a PNG text chunk type"),
        };
    }

    /** An APP11 payload starting with the JUMBF identifier `JP` whose box carries no `c2pa` label. */
    protected static function buildJumbfApp11PayloadWithoutC2paLabel(): string
    {
        $descriptionBoxContent = str_repeat("\x00", 16) . "\x03" . "rc.fixture\0";
        $descriptionBox = pack('N', 8 + strlen($descriptionBoxContent)) . 'jumd' . $descriptionBoxContent;
        return 'JP' . pack('n', 1) . pack('N', 1) . pack('N', 8 + strlen($descriptionBox)) . 'jumb' . $descriptionBox;
    }

    protected static function buildGdJpeg(): string
    {
        ob_start();
        imagejpeg(self::buildGdImage(), null, 90);
        return (string)ob_get_clean();
    }

    protected static function buildGdPng(): string
    {
        ob_start();
        imagepng(self::buildGdImage());
        return (string)ob_get_clean();
    }

    protected static function buildGdGif(): string
    {
        ob_start();
        imagegif(self::buildGdImage());
        return (string)ob_get_clean();
    }

    protected static function buildGdImage(): \GdImage
    {
        $gdImage = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        for ($x = 0; $x < self::WIDTH; $x++) {
            imageline($gdImage, $x, 0, $x, self::HEIGHT - 1, imagecolorallocate($gdImage, $x * 4, 255 - $x * 4, 128));
        }
        return $gdImage;
    }
}

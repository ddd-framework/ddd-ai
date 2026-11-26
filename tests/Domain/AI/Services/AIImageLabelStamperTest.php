<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Domain\AI\Services;

use DDD\Domain\AI\Entities\MediaItems\AIImageLabelStampSettings;
use DDD\Domain\AI\Entities\MediaItems\AIImageLabelVariant;
use DDD\Domain\AI\Services\AIImageLabelStamper;
use DDD\Infrastructure\Services\DDDService;
use DDD\Testing\MediaItems\ImageMarkFixtureBuilder;
use DDD\Testing\MediaItems\ImageStructureWalker;
use DDD\Tests\AI\Domain\AI\Entities\MediaItems\AIImageIptcTagAssertionsTrait;
use PHPUnit\Framework\TestCase;
use Imagick;
use ImagickDraw;
use ImagickException;
use ImagickPixel;
use InvalidArgumentException;
use LengthException;
use LogicException;
use UnexpectedValueException;

/**
 * The stamper with real Imagick and the committed icon files: format and dimensions, corner and size, colour, pixels
 * outside the icon, metadata, rotated and CMYK JPEGs, errors.
 *
 * Fixtures are built in the test (Imagick pixels, marks injected as bytes with ImageMarkFixtureBuilder) and checked
 * before the call; a failed check is labelled PRECONDITION. Every expected position, size and threshold is a literal
 * from the accepted criteria (criterion 21's threshold: from a measured run, as criterion 21 asks), never recomputed with
 * the stamper's or the settings' formula. Imagick runs only in the
 * production-matching image, so the test skips where the extension is missing. Built fixtures and stamped outputs are
 * kept for the whole process: one Imagick call takes seconds under emulation.
 *
 * The "criterion N" labels number the cases of the specifications of the stamper and of the AI tag; the tag's expected
 * packets are this file's own literals.
 */
class AIImageLabelStamperTest extends TestCase
{
    use AIImageIptcTagAssertionsTrait;

    /** @var string The AI tag of a generated image, the byte order mark written as the bytes EF BB BF */
    protected const string EXPECTED_GENERATED_PACKET = '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>'
        . '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
        . '<rdf:Description rdf:about="" xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/"'
        . ' Iptc4xmpExt:DigitalSourceType="http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia"/>'
        . '</rdf:RDF></x:xmpmeta><?xpacket end="w"?>';

    /** @var string The AI tag of an edited image */
    protected const string EXPECTED_EDITED_PACKET = '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>'
        . '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
        . '<rdf:Description rdf:about="" xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/"'
        . ' Iptc4xmpExt:DigitalSourceType="http://cv.iptc.org/newscodes/digitalsourcetype/compositeWithTrainedAlgorithmicMedia"/>'
        . '</rdf:RDF></x:xmpmeta><?xpacket end="w"?>';

    /** @var string An XMP packet that is neither tag: a camera photo's digital source type */
    protected const string DISTINCT_PACKET = '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>'
        . '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
        . '<rdf:Description rdf:about="" xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/"'
        . ' Iptc4xmpExt:DigitalSourceType="http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture"/>'
        . '</rdf:RDF></x:xmpmeta><?xpacket end="w"?>';

    /** @var string Prefix of every message of an assertion on a fixture rather than on the behaviour under test */
    protected const string PRECONDITION = 'PRECONDITION: ';

    /** @var int Seed of ImageMagick's noise, so every run builds the same pixels */
    protected const int NOISE_SEED = 4711;

    /** @var string The committed icon folder, relative to the app folder */
    protected const string ICON_DIRECTORY_RELATIVE_TO_APP = 'config/app/AI/AIImageLabelIcons';

    /** @var array<string, string> The solid pair of icon files, by icon colour */
    protected const array SOLID_ICON_FILE_NAMES = [
        AIImageLabelStampSettings::ICON_COLOUR_BLACK => 'LABEL_AI_black.png',
        AIImageLabelStampSettings::ICON_COLOUR_WHITE => 'LABEL_AI_white.png',
    ];

    /** @var int A PNG pixel counts as changed on any difference in a decoded channel */
    protected const int CHANGED_PIXEL_THRESHOLD_PNG = 0;

    /** @var int A JPEG pixel counts as changed on a difference above 24 in a decoded channel */
    protected const int CHANGED_PIXEL_THRESHOLD_JPEG = 24;

    protected const string FIXTURE_NOISY_JPEG_640X480 = 'noisy JPEG 640x480, quality 85, no Exif';
    protected const string FIXTURE_NOISY_PNG_600X400 = 'noisy 8-bit RGB PNG 600x400';
    protected const string FIXTURE_NOISY_PNG_400X600 = 'noisy 8-bit RGB PNG 400x600';
    protected const string FIXTURE_NOISY_PNG_200X150 = 'noisy 8-bit RGB PNG 200x150';
    protected const string FIXTURE_NOISY_PNG_1000X750 = 'noisy 8-bit RGB PNG 1000x750';
    protected const string FIXTURE_NOISY_PNG_400X500 = 'noisy 8-bit RGB PNG 400x500';
    protected const string FIXTURE_DARK_PNG_WITH_LIGHT_CORNER = 'PNG 300x300 #202020 with a #E0E0E0 top-right 100x100 square';
    protected const string FIXTURE_LIGHT_PNG_WITH_DARK_CORNER = 'PNG 300x300 #E0E0E0 with a #202020 top-right 100x100 square';
    protected const string FIXTURE_FLAT_PNG_8C8C8C = 'flat PNG 300x300 #8C8C8C';
    protected const string FIXTURE_FLAT_PNG_737373 = 'flat PNG 300x300 #737373';
    protected const string FIXTURE_FLAT_PNG_00C800 = 'flat PNG 300x300 #00C800';
    protected const string FIXTURE_FLAT_PNG_E0E0E0 = 'flat PNG 300x300 #E0E0E0';
    protected const string FIXTURE_TRANSPARENT_PNG = 'RGBA PNG 300x300, every pixel (0,0,0,0)';
    protected const string FIXTURE_NOISY_RGBA_PNG_600X400 = 'noisy RGBA PNG 600x400 with varying alpha';
    protected const string FIXTURE_NOISY_SIXTEEN_BIT_PNG_600X400 = 'noisy 16-bit RGB PNG 600x400';
    protected const string FIXTURE_NOISY_PALETTE_PNG_600X400 = 'noisy palette PNG 600x400 (IHDR colour type 3)';
    protected const string FIXTURE_LIGHT_GRAYSCALE_JPEG_640X480 = 'light #E0E0E0 grayscale JPEG 640x480';
    protected const string FIXTURE_DARK_GRAYSCALE_JPEG_640X480 = 'dark #202020 grayscale JPEG 640x480';
    protected const string FIXTURE_JPEG_WITH_ALL_FOUR_MARKS = 'noisy JPEG 640x480 with Exif, XMP, APP13 and APP11';
    protected const string FIXTURE_PNG_WITH_EXIF_XMP_AND_CABX = 'noisy 8-bit RGB PNG 600x400 with eXIf, XMP and caBX';
    protected const string FIXTURE_JPEG_WITH_ICC_PROFILE = 'noisy JPEG 640x480 with the vendor sRGB ICC profile in one APP2';
    protected const string FIXTURE_GIF = 'GIF 300x300';
    protected const string FIXTURE_WEBP = 'WebP 300x300';
    protected const string FIXTURE_JPEG_WITH_SOS_BUT_NO_SOF = 'JPEG of SOI, APP0, an SOS with no SOF, DQT or DHT before it, EOI';
    protected const string FIXTURE_PNG_WITH_CORRUPT_XMP = 'noisy PNG 600x400 whose XMP iTXt holds a corrupt zlib stream';
    protected const string FIXTURE_NOISY_PNG_25X25 = 'noisy 8-bit RGB PNG 25x25';
    protected const string FIXTURE_NOISY_PNG_24X24 = 'noisy 8-bit RGB PNG 24x24';
    protected const string FIXTURE_WHITE_CMYK_JPEG_640X480 = 'white CMYK JPEG 640x480 without a profile';
    protected const string FIXTURE_WHITE_SRGB_JPEG_640X480 = 'white sRGB JPEG 640x480';
    protected const string FIXTURE_NOISY_JPEG_Q95_1X1 = 'noisy JPEG 640x480, quality 95, sampling 1x1';
    protected const string FIXTURE_NOISY_JPEG_Q75_2X2 = 'noisy JPEG 640x480, quality 75, sampling 2x2';
    protected const string FIXTURE_NOISY_PNG_600X400_WITH_EXIF_ORIENTATION_6 = 'noisy 8-bit RGB PNG 600x400 whose eXIf says orientation 6';
    protected const string FIXTURE_JPEG_WITH_ONLY_C2PA = 'noisy JPEG 640x480 with only the fixture APP11 C2PA segment';
    protected const string FIXTURE_JPEG_WITH_DISTINCT_XMP = 'noisy JPEG 640x480 with only an XMP APP1 of a distinct packet';
    protected const string FIXTURE_PNG_WITH_DISTINCT_XMP = 'noisy 8-bit RGB PNG 600x400 with only an XMP iTXt of a distinct packet';
    protected const string FIXTURE_PNG_WITH_ONLY_RAW_PROFILE_XMP = 'noisy 8-bit RGB PNG 600x400 with only a Raw profile type xmp tEXt chunk';
    protected const string FIXTURE_JPEG_WITH_ONLY_EXTENDED_XMP = 'noisy JPEG 640x480 with only an extended XMP APP1 (no main XMP APP1)';

    /** @var array<int, string> The noisy JPEG displayed as 480x320, stored for each EXIF orientation */
    protected const array ROTATED_JPEG_FIXTURE_NAMES_BY_ORIENTATION = [
        1 => 'noisy JPEG displayed 480x320, stored for EXIF orientation 1',
        2 => 'noisy JPEG displayed 480x320, stored for EXIF orientation 2',
        3 => 'noisy JPEG displayed 480x320, stored for EXIF orientation 3',
        4 => 'noisy JPEG displayed 480x320, stored for EXIF orientation 4',
        5 => 'noisy JPEG displayed 480x320, stored for EXIF orientation 5',
        6 => 'noisy JPEG displayed 480x320, stored for EXIF orientation 6',
        7 => 'noisy JPEG displayed 480x320, stored for EXIF orientation 7',
        8 => 'noisy JPEG displayed 480x320, stored for EXIF orientation 8',
    ];

    /**
     * @var float The mean absolute channel difference over the TOP_RIGHT square a rotated stamp may show against the
     *     orientation-1 stamp (criterion 21): the midpoint of the measured run of 2026-11-13 between the largest row
     *     value, 3.746 (orientation 8; rows 1-8: 0.000, 1.651, 1.731, 1.300, 3.102, 3.067, 3.444, 3.746), and the
     *     smallest negative-control value, 25.121 (mirrored; another corner 167.957, turned by 90° 39.139, turned by
     *     180° 26.168)
     */
    protected const float ROTATED_SQUARE_DIFFERENCE_THRESHOLD = 14.43;

    /** @var string The sRGB profile the ICC fixture carries (60,960 bytes), relative to the app folder */
    protected const string VENDOR_SRGB_ICC_PROFILE_RELATIVE_TO_APP = 'vendor/mgamadeus/ddd/src/Infrastructure/Libs/PhotoUtils/Resources/sRGB_v4_ICC_preference.icc';

    /** @var string The identifier an APP2 ICC profile payload starts with, before its sequence number and count */
    protected const string ICC_PROFILE_APP2_IDENTIFIER = "ICC_PROFILE\0";

    /** @var array<string, string> Built images by cache key, kept for the whole process */
    protected static array $imageBytesByCacheKey = [];

    /** @var array<string, string> Stamped outputs by fixture name, variant and settings, kept for the whole process */
    protected static array $stampedImageBytesByCacheKey = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('imagick')) {
            self::markTestSkipped('imagick extension is not available');
        }
    }

    /**
     * criterion 2: the stamper exposes the shipped config's design version, 1.
     */
    public function testShippedDesignVersionIsOne(): void
    {
        self::assertSame(1, $this->getStamper()->getShippedDesignVersion(), 'criterion 2: the shipped design version');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Format and dimensions (criteria 1-2)
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * @dataProvider provideFormatAndDimensionsRows
     */
    public function testFormatAndDimensionsAreKept(string $fixtureName, string $format, int $width, int $height, string $label): void
    {
        $stampedImageBytes = $this->buildStampedImageBytes($fixtureName);

        $this->assertDecodesAs($stampedImageBytes, $format, $width, $height, "{$label}: the output");
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Corner, size, minimum (criteria 3-5)
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * @param array{left: int, top: int, right: int, bottom: int} $expectedIconSquare
     * @dataProvider provideCornerRows
     */
    public function testIconSitsInTheConfiguredCornerAtTheConfiguredSize(string $fixtureName, string $corner, array $expectedIconSquare): void
    {
        $imageBytes = $this->buildCheckedFixture($fixtureName);

        $stampedImageBytes = $this->buildStampedImageBytes($fixtureName, stampSettings: $this->buildTestStampSettings($corner));

        $this->assertChangedPixelsFillTheSquare(
            $imageBytes,
            $stampedImageBytes,
            $expectedIconSquare,
            self::CHANGED_PIXEL_THRESHOLD_PNG,
            1,
            "criterion 3: {$fixtureName}, {$corner}",
        );
    }

    public function testMinimumSizeHoldsOnASmallImage(): void
    {
        $imageBytes = $this->buildCheckedFixture(self::FIXTURE_NOISY_PNG_200X150);

        $stampedImageBytes = $this->buildStampedImageBytes(self::FIXTURE_NOISY_PNG_200X150);

        $this->assertChangedPixelsFillTheSquare(
            $imageBytes,
            $stampedImageBytes,
            ['left' => 168, 'top' => 8, 'right' => 192, 'bottom' => 32],
            self::CHANGED_PIXEL_THRESHOLD_PNG,
            1,
            'criterion 4: 200x150 gets the 24 px minimum disc with margin 8',
        );
    }

    /**
     * @param array{left: int, top: int, right: int, bottom: int} $expectedIconSquare
     * @dataProvider provideShippedDefaultsRows
     */
    public function testShippedDefaultsPlaceTheIconTopRight(string $fixtureName, array $expectedIconSquare, string $label): void
    {
        $imageBytes = $this->buildCheckedFixture($fixtureName);

        $stampedImageBytes = $this->buildStampedImageBytes($fixtureName, useShippedConfig: true);

        $this->assertChangedPixelsFillTheSquare(
            $imageBytes,
            $stampedImageBytes,
            $expectedIconSquare,
            self::CHANGED_PIXEL_THRESHOLD_PNG,
            1,
            "criterion 5: {$label}",
        );
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Colour (criteria 6-11): 300x300 PNGs with test settings T, disc pixel (258, 30) in the square [255,285)x[15,45)
    // ---------------------------------------------------------------------------------------------------------------

    public function testLightCornerGetsTheBlackIcon(): void
    {
        $stampedImageBytes = $this->buildStampedImageBytes(self::FIXTURE_DARK_PNG_WITH_LIGHT_CORNER);

        $this->assertDiscPixelIsBlack($stampedImageBytes, 258, 30, 'criterion 6: a light corner on a dark image gets the black icon');
    }

    public function testDarkCornerGetsTheWhiteIcon(): void
    {
        $stampedImageBytes = $this->buildStampedImageBytes(self::FIXTURE_LIGHT_PNG_WITH_DARK_CORNER);

        $this->assertDiscPixelIsWhite($stampedImageBytes, 258, 30, 'criterion 7: a dark corner on a light image gets the white icon');
    }

    /**
     * @dataProvider provideCornerBrightnessRows
     */
    public function testCornerBrightnessThreshold(string $fixtureName, bool $expectsTheBlackIcon, string $label): void
    {
        $stampedImageBytes = $this->buildStampedImageBytes($fixtureName);

        if ($expectsTheBlackIcon) {
            $this->assertDiscPixelIsBlack($stampedImageBytes, 258, 30, "criterion 8: {$label}");
        } else {
            $this->assertDiscPixelIsWhite($stampedImageBytes, 258, 30, "criterion 8: {$label}");
        }
    }

    public function testTransparentCornerCountsAsLight(): void
    {
        $imageBytes = $this->buildCheckedFixture(self::FIXTURE_TRANSPARENT_PNG);

        $stampedImageBytes = $this->buildStampedImageBytes(self::FIXTURE_TRANSPARENT_PNG);

        $this->assertDiscPixelIsBlack($stampedImageBytes, 258, 30, 'criterion 9: a transparent corner counts as light');
        self::assertSame(255, $this->readPixel($stampedImageBytes, 258, 30)['alpha'], 'criterion 9: the disc pixel is opaque');
        $inputPixelBytes = $this->readRgbaPixelBytes($imageBytes);
        $outputPixelBytes = $this->readRgbaPixelBytes($stampedImageBytes);
        $transparentPixelsOutsideTheSquare = 0;
        for ($y = 0; $y < 300; $y++) {
            for ($x = 0; $x < 300; $x++) {
                if ($x >= 255 && $x < 285 && $y >= 15 && $y < 45) {
                    continue;
                }
                $transparentPixelsOutsideTheSquare += ord($outputPixelBytes[4 * (300 * $y + $x) + 3]) === 0 ? 1 : 0;
            }
        }
        self::assertSame(300 * 300 - 30 * 30, $transparentPixelsOutsideTheSquare, 'criterion 9: every pixel outside the icon square still has alpha 0');
        self::assertSame(strlen($inputPixelBytes), strlen($outputPixelBytes), 'criterion 9: the output has the input\'s dimensions');
    }

    /**
     * @dataProvider providePinnedColourRows
     */
    public function testPinnedColourIsHonoured(string $fixtureName, string $colourChoice, string $label): void
    {
        $stampedImageBytes = $this->buildStampedImageBytes(
            $fixtureName,
            stampSettings: $this->buildTestStampSettings(colourChoice: $colourChoice),
        );

        if ($colourChoice === AIImageLabelStampSettings::COLOUR_BLACK) {
            $this->assertDiscPixelIsBlack($stampedImageBytes, 258, 30, "criterion 10: {$label}");
        } else {
            $this->assertDiscPixelIsWhite($stampedImageBytes, 258, 30, "criterion 10: {$label}");
        }
    }

    public function testIconFilePerVariantIsHonoured(): void
    {
        $stampSettings = $this->buildTestStampSettings(editedIconFileNames: [
            AIImageLabelStampSettings::ICON_COLOUR_BLACK => 'LABEL_AI_black_transparent.png',
            AIImageLabelStampSettings::ICON_COLOUR_WHITE => 'LABEL_AI_white_transparent.png',
        ]);

        $editedImageBytes = $this->buildStampedImageBytes(self::FIXTURE_FLAT_PNG_E0E0E0, AIImageLabelVariant::EDITED, $stampSettings);
        $generatedImageBytes = $this->buildStampedImageBytes(self::FIXTURE_FLAT_PNG_E0E0E0, AIImageLabelVariant::GENERATED, $stampSettings);

        $editedDiscPixel = $this->readPixel($editedImageBytes, 258, 30);
        foreach (['red', 'green', 'blue'] as $channelName) {
            self::assertGreaterThanOrEqual(60, $editedDiscPixel[$channelName], 'criterion 11: EDITED gets the 50 % disc ' . json_encode($editedDiscPixel));
            self::assertLessThanOrEqual(170, $editedDiscPixel[$channelName], 'criterion 11: EDITED gets the 50 % disc ' . json_encode($editedDiscPixel));
        }
        $this->assertDiscPixelIsBlack($generatedImageBytes, 258, 30, 'criterion 11: GENERATED gets the solid disc');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Pixels outside the icon (criteria 12-15)
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * @dataProvider providePngPixelsOutsideTheIconRows
     */
    public function testPngPixelsOutsideTheIconAreUnchanged(string $fixtureName, int $bitDepth, bool $comparesRgbOnlyWhereTheInputIsVisible): void
    {
        $imageBytes = $this->buildCheckedFixture($fixtureName);

        $stampedImageBytes = $this->buildStampedImageBytes($fixtureName);

        $this->assertDecodesAs($stampedImageBytes, 'PNG', 600, 400, "criterion 12: {$fixtureName}");
        // the icon square [540,580)x[20,60); inside it, beyond 24 px from the disc centre (560, 40) (radius 20 + 4)
        $this->assertPixelsAwayFromTheDiscAreUnchanged(
            $imageBytes,
            $stampedImageBytes,
            ['left' => 540, 'top' => 20, 'right' => 580, 'bottom' => 60],
            24.0,
            $bitDepth,
            $comparesRgbOnlyWhereTheInputIsVisible,
            "criterion 12: {$fixtureName}",
        );
        if ($fixtureName === self::FIXTURE_NOISY_RGBA_PNG_600X400) {
            self::assertSame(
                6,
                ImageStructureWalker::getPngBitDepthAndColourType($stampedImageBytes)['colourType'],
                'criterion 12: the RGBA output has IHDR colour type 6',
            );
        }
    }

    public function testJpegPixelsOutsideTheIconStayWithinTolerance(): void
    {
        $imageBytes = $this->buildCheckedFixture(self::FIXTURE_NOISY_JPEG_640X480);

        $stampedImageBytes = $this->buildStampedImageBytes(self::FIXTURE_NOISY_JPEG_640X480);

        $this->assertDecodesAs($stampedImageBytes, 'JPEG', 640, 480, 'criterion 13: the output');
        // the icon square [568,616)x[24,72) expanded outward to the 16 px grid
        $largestDifference = $this->computeLargestChannelDifferenceOutside(
            $imageBytes,
            $stampedImageBytes,
            ['left' => 560, 'top' => 16, 'right' => 624, 'bottom' => 80],
        );
        self::assertLessThanOrEqual(
            16,
            $largestDifference,
            'criterion 13: outside [560,624)x[16,80) no channel differs by more than 16',
        );
    }

    public function testPalettePngComesOutAsTruecolourPng(): void
    {
        $stampedImageBytes = $this->buildStampedImageBytes(self::FIXTURE_NOISY_PALETTE_PNG_600X400);

        $this->assertDecodesAs($stampedImageBytes, 'PNG', 600, 400, 'criterion 14: the output');
        self::assertContains(
            ImageStructureWalker::getPngBitDepthAndColourType($stampedImageBytes)['colourType'],
            [2, 6],
            'criterion 14: the palette PNG comes out as a truecolour PNG (IHDR colour type 2 or 6)',
        );
    }

    /**
     * @dataProvider provideGrayscaleJpegRows
     */
    public function testGrayscaleJpegStaysGrayscale(string $fixtureName, bool $expectsTheBlackIcon, string $label): void
    {
        $stampedImageBytes = $this->buildStampedImageBytes($fixtureName);

        if ($expectsTheBlackIcon) {
            $this->assertDiscPixelIsBlack($stampedImageBytes, 573, 48, "criterion 15: {$label}");
        } else {
            $this->assertDiscPixelIsWhite($stampedImageBytes, 573, 48, "criterion 15: {$label}");
        }
        $this->assertIsOneComponentGrayscaleJpeg($stampedImageBytes, "criterion 15: {$label}, the output");
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Metadata (criteria 16-20)
    // ---------------------------------------------------------------------------------------------------------------

    public function testJpegKeepsExifIptcAndXmp(): void
    {
        $imageBytes = $this->buildCheckedFixture(self::FIXTURE_JPEG_WITH_ALL_FOUR_MARKS);

        $stampedImageBytes = $this->buildStampedImageBytes(self::FIXTURE_JPEG_WITH_ALL_FOUR_MARKS);

        $this->assertDecodesAs($stampedImageBytes, 'JPEG', 640, 480, 'criterion 16: the output');
        foreach ([ImageStructureWalker::JPEG_KIND_EXIF, ImageStructureWalker::JPEG_KIND_XMP] as $kind) {
            self::assertSame(
                array_column(ImageStructureWalker::listJpegSegmentsOfKind($imageBytes, $kind), 'payload'),
                array_column(ImageStructureWalker::listJpegSegmentsOfKind($stampedImageBytes, $kind), 'payload'),
                "criterion 16: the output carries exactly one {$kind} with the input's payload",
            );
        }
        self::assertSame(
            array_column(ImageStructureWalker::listJpegSegmentsOfKind($imageBytes, ImageStructureWalker::JPEG_KIND_IPTC), 'payload'),
            array_column(ImageStructureWalker::listJpegSegmentsOfKind($stampedImageBytes, ImageStructureWalker::JPEG_KIND_IPTC), 'payload'),
            'criterion 16: the output carries the input\'s Photoshop APP13 payloads (count, order, bytes)',
        );
    }

    /**
     * @dataProvider provideC2paRows
     */
    public function testC2paIsNotCarried(string $fixtureName): void
    {
        $stampedImageBytes = $this->buildStampedImageBytes($fixtureName);

        if ($fixtureName === self::FIXTURE_JPEG_WITH_ALL_FOUR_MARKS) {
            $this->assertDecodesAs($stampedImageBytes, 'JPEG', 640, 480, 'criterion 17: the output');
            self::assertSame(
                [],
                ImageStructureWalker::listJpegSegmentsOfKind($stampedImageBytes, ImageStructureWalker::JPEG_KIND_APP11),
                'criterion 17: the JPEG output carries no APP11 segment',
            );
        } else {
            $this->assertDecodesAs($stampedImageBytes, 'PNG', 600, 400, 'criterion 19: the output');
            self::assertNotContains('caBX', ImageStructureWalker::listPngChunkTypes($stampedImageBytes), 'criterion 19: the PNG output carries no caBX chunk');
        }
    }

    public function testPngKeepsExifAndXmp(): void
    {
        $imageBytes = $this->buildCheckedFixture(self::FIXTURE_PNG_WITH_EXIF_XMP_AND_CABX);

        $stampedImageBytes = $this->buildStampedImageBytes(self::FIXTURE_PNG_WITH_EXIF_XMP_AND_CABX);

        $this->assertDecodesAs($stampedImageBytes, 'PNG', 600, 400, 'criterion 18: the output');
        self::assertTrue(ImageStructureWalker::isWellFormedStillPng($stampedImageBytes), 'criterion 18: every chunk CRC of the output is valid');
        $exifChunks = ImageStructureWalker::listPngChunksOfType($stampedImageBytes, 'eXIf');
        self::assertSame(
            [ImageMarkFixtureBuilder::buildExifTiffBlock()],
            array_column($exifChunks, 'data'),
            'criterion 18: the output carries one eXIf with the input\'s data',
        );
        self::assertSame(
            [ImageMarkFixtureBuilder::buildXmpPacket()],
            ImageStructureWalker::listPngXmpPackets($stampedImageBytes),
            'criterion 18: the output carries one XMP chunk with the input\'s packet',
        );
        $chunkOffsets = array_column(ImageStructureWalker::listPngChunks($stampedImageBytes), 'offset');
        $firstIdatIndex = array_search('IDAT', ImageStructureWalker::listPngChunkTypes($stampedImageBytes), true);
        self::assertIsInt($firstIdatIndex, 'criterion 18: the output has an IDAT chunk');
        self::assertLessThan($firstIdatIndex, array_search($exifChunks[0]['offset'], $chunkOffsets, true), 'criterion 18: eXIf before the first IDAT');
        self::assertLessThan(
            $firstIdatIndex,
            array_search(ImageStructureWalker::listPngXmpChunks($stampedImageBytes)[0]['offset'], $chunkOffsets, true),
            'criterion 18: XMP before the first IDAT',
        );
    }

    public function testJpegIccProfileIsKept(): void
    {
        $this->buildCheckedFixture(self::FIXTURE_JPEG_WITH_ICC_PROFILE);

        $stampedImageBytes = $this->buildStampedImageBytes(self::FIXTURE_JPEG_WITH_ICC_PROFILE);

        $this->assertDecodesAs($stampedImageBytes, 'JPEG', 640, 480, 'criterion 20: the output');
        self::assertSame(
            hash('sha256', $this->readVendorSrgbIccProfile()),
            hash('sha256', $this->readJpegIccProfile($stampedImageBytes)),
            'criterion 20: the output carries the input\'s ICC profile bytes',
        );
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Rotated JPEGs (criteria 21-22): displayed 480x320, T gives d = 32, m = 16, TOP_RIGHT square [432,464)x[16,48)
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * @dataProvider provideOrientationRows
     */
    public function testRotatedJpegGetsTheIconWhereItDisplays(int $orientation): void
    {
        $uprightOutput = $this->readUprightImagick($this->buildStampedImageBytes(self::ROTATED_JPEG_FIXTURE_NAMES_BY_ORIENTATION[$orientation]));
        $uprightReference = $this->readUprightImagick($this->buildStampedImageBytes(self::ROTATED_JPEG_FIXTURE_NAMES_BY_ORIENTATION[1]));
        self::assertSame([480, 320], [$uprightOutput->getImageWidth(), $uprightOutput->getImageHeight()], 'criterion 21: the output displays as 480x320');

        $referenceSquareSamples = $this->exportRgbSamples($uprightReference, 432, 16, 32);
        $squareDifferenceFromReference = $this->computeMeanAbsoluteChannelDifference($referenceSquareSamples, $this->exportRgbSamples($uprightOutput, 432, 16, 32));
        $negativeControlValues = $this->computeOrientationNegativeControlValues($uprightReference, $referenceSquareSamples);
        $uprightOutput->clear();
        $uprightReference->clear();

        $message = sprintf(
            'criterion 21: orientation %d: the upright TOP_RIGHT square differs from the orientation-1 stamp by %.3f (threshold %.3f); negative controls %s',
            $orientation,
            $squareDifferenceFromReference,
            self::ROTATED_SQUARE_DIFFERENCE_THRESHOLD,
            json_encode(array_map(static fn(float $controlValue): string => sprintf('%.3f', $controlValue), $negativeControlValues)),
        );
        foreach ($negativeControlValues as $negativeControlValue) {
            self::assertGreaterThan(self::ROTATED_SQUARE_DIFFERENCE_THRESHOLD, $negativeControlValue, "{$message}: every negative control fails the threshold");
        }
        self::assertLessThanOrEqual(self::ROTATED_SQUARE_DIFFERENCE_THRESHOLD, $squareDifferenceFromReference, $message);
    }

    /**
     * @dataProvider provideOrientationRows
     */
    public function testRotatedJpegKeepsItsOrientationTagAndDimensions(int $orientation): void
    {
        $stampedImageBytes = $this->buildStampedImageBytes(self::ROTATED_JPEG_FIXTURE_NAMES_BY_ORIENTATION[$orientation]);

        [$storedWidth, $storedHeight] = $orientation >= 5 ? [320, 480] : [480, 320];
        $this->assertDecodesAs($stampedImageBytes, 'JPEG', $storedWidth, $storedHeight, "criterion 22: orientation {$orientation}: the output keeps its stored dimensions");
        $exifSegments = ImageStructureWalker::listJpegSegmentsOfKind($stampedImageBytes, ImageStructureWalker::JPEG_KIND_EXIF);
        self::assertCount(1, $exifSegments, "criterion 22: orientation {$orientation}: the output carries one Exif APP1");
        self::assertSame(
            $orientation,
            $this->readExifOrientationFromTiffBlock(substr($exifSegments[0]['payload'], strlen(ImageMarkFixtureBuilder::EXIF_IDENTIFIER))),
            "criterion 22: orientation {$orientation}: the output's EXIF keeps the orientation tag",
        );
        self::assertSame($orientation, $this->readImageOrientation($stampedImageBytes), "criterion 22: orientation {$orientation}: Imagick reads the output's orientation");
    }

    // ---------------------------------------------------------------------------------------------------------------
    // CMYK, quality and sampling, orientation of PNG and of a JPEG without EXIF (criteria 23, 36-38)
    // ---------------------------------------------------------------------------------------------------------------

    public function testCmykJpegGetsAVisibleIcon(): void
    {
        $this->buildCheckedFixture(self::FIXTURE_WHITE_CMYK_JPEG_640X480);

        $stampedCmykImageBytes = $this->buildStampedImageBytes(self::FIXTURE_WHITE_CMYK_JPEG_640X480);
        $stampedSrgbImageBytes = $this->buildStampedImageBytes(self::FIXTURE_WHITE_SRGB_JPEG_640X480);

        $this->assertDecodesAs($stampedCmykImageBytes, 'JPEG', 640, 480, 'criterion 23: the output');
        self::assertSame(Imagick::COLORSPACE_CMYK, $this->readImageColourspace($stampedCmykImageBytes), 'criterion 23: the output is a CMYK JPEG');
        $iconSquare = ['left' => 568, 'top' => 24, 'right' => 616, 'bottom' => 72];
        $largestDifference = $this->computeLargestChannelDifferenceInside($stampedSrgbImageBytes, $stampedCmykImageBytes, $iconSquare);
        self::assertLessThanOrEqual(
            24,
            $largestDifference,
            'criterion 23: the icon square of the CMYK output, in sRGB, differs from the same stamp on an sRGB JPEG by at most 24',
        );
        $this->assertDiscPixelIsBlack($stampedCmykImageBytes, 573, 48, 'criterion 23: the CMYK output shows the black disc');
    }

    /**
     * @dataProvider provideQualityAndSamplingRows
     */
    public function testJpegQualityAndSamplingAreKept(string $fixtureName, int $quality, int $horizontalSamplingFactor, int $verticalSamplingFactor): void
    {
        $this->buildCheckedFixture($fixtureName);

        $stampedImageBytes = $this->buildStampedImageBytes($fixtureName);

        $this->assertDecodesAs($stampedImageBytes, 'JPEG', 640, 480, 'criterion 36: the output');
        self::assertSame($quality, $this->readJpegQuality($stampedImageBytes), "criterion 36: the output keeps the quality {$quality}");
        self::assertSame(
            [
                ['horizontal' => $horizontalSamplingFactor, 'vertical' => $verticalSamplingFactor],
                ['horizontal' => 1, 'vertical' => 1],
                ['horizontal' => 1, 'vertical' => 1],
            ],
            $this->readJpegSofComponentSamplingFactors($stampedImageBytes),
            "criterion 36: the output's SOF keeps the sampling {$horizontalSamplingFactor}x{$verticalSamplingFactor},1x1,1x1",
        );
    }

    public function testPngExifOrientationIsIgnored(): void
    {
        $imageBytes = $this->buildCheckedFixture(self::FIXTURE_NOISY_PNG_600X400_WITH_EXIF_ORIENTATION_6);

        $stampedImageBytes = $this->buildStampedImageBytes(self::FIXTURE_NOISY_PNG_600X400_WITH_EXIF_ORIENTATION_6);

        $this->assertChangedPixelsFillTheSquare(
            $imageBytes,
            $stampedImageBytes,
            ['left' => 540, 'top' => 20, 'right' => 580, 'bottom' => 60],
            self::CHANGED_PIXEL_THRESHOLD_PNG,
            1,
            'criterion 37: a PNG whose eXIf says 6 gets the icon in its stored top-right corner',
        );
    }

    public function testJpegWithoutExifGetsTheIconTopRight(): void
    {
        $imageBytes = $this->buildCheckedFixture(self::FIXTURE_NOISY_JPEG_640X480);
        self::assertSame([], ImageStructureWalker::listJpegSegmentsOfKind($imageBytes, ImageStructureWalker::JPEG_KIND_EXIF), self::PRECONDITION . 'the fixture carries no Exif APP1');
        self::assertSame(0, $this->readImageOrientation($imageBytes), self::PRECONDITION . 'Imagick reads the fixture\'s orientation as 0 (undefined)');

        $stampedImageBytes = $this->buildStampedImageBytes(self::FIXTURE_NOISY_JPEG_640X480);

        $changedPixelBoundingBox = $this->buildChangedPixelBoundingBox($imageBytes, $stampedImageBytes, self::CHANGED_PIXEL_THRESHOLD_JPEG);
        self::assertNotNull($changedPixelBoundingBox, 'criterion 38: some pixels changed by more than 24');
        $gridRectangle = ['left' => 560, 'top' => 16, 'right' => 624, 'bottom' => 80];
        self::assertGreaterThanOrEqual($gridRectangle['left'], $changedPixelBoundingBox['left'], 'criterion 38: the changed pixels lie inside [560,624)x[16,80)');
        self::assertGreaterThanOrEqual($gridRectangle['top'], $changedPixelBoundingBox['top'], 'criterion 38: the changed pixels lie inside [560,624)x[16,80)');
        self::assertLessThanOrEqual($gridRectangle['right'], $changedPixelBoundingBox['right'], 'criterion 38: the changed pixels lie inside [560,624)x[16,80)');
        self::assertLessThanOrEqual($gridRectangle['bottom'], $changedPixelBoundingBox['bottom'], 'criterion 38: the changed pixels lie inside [560,624)x[16,80)');
        // within 2 px of each edge of the stored top-right square, on either side (JPEG ringing may cross an edge)
        foreach (['left' => 568, 'top' => 24, 'right' => 616, 'bottom' => 72] as $edgeName => $expectedEdge) {
            self::assertLessThanOrEqual(
                2,
                abs($changedPixelBoundingBox[$edgeName] - $expectedEdge),
                "criterion 38: the {$edgeName} edge of the changed pixels " . json_encode($changedPixelBoundingBox)
                . ' comes within 2 px of [568,616)x[24,72), the stored top-right square',
            );
        }
    }

    // ---------------------------------------------------------------------------------------------------------------
    // The AI tag (criteria 22-23)
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * @dataProvider provideInputsWithoutXmpRows
     */
    public function testOutputOfAnInputWithoutXmpCarriesThePacketOfItsVariant(
        string $fixtureName,
        string $aiImageLabelVariant,
        string $format,
        int $width,
        int $height,
    ): void {
        $this->assertTheExpectedPacketLiterals();
        $imageBytes = $this->buildCheckedFixture($fixtureName);
        $this->assertCarriesNoXmpPacket($imageBytes, self::PRECONDITION . $fixtureName);

        $stampedImageBytes = $this->buildStampedImageBytes($fixtureName, $aiImageLabelVariant);

        $this->assertDecodesAs($stampedImageBytes, $format, $width, $height, "criterion 22: {$fixtureName}, {$aiImageLabelVariant}: the output");
        $this->assertCarriesThePacket(
            $stampedImageBytes,
            $aiImageLabelVariant === AIImageLabelVariant::GENERATED ? self::EXPECTED_GENERATED_PACKET : self::EXPECTED_EDITED_PACKET,
            "criterion 22: {$fixtureName}, {$aiImageLabelVariant}: the output",
        );
    }

    /**
     * @dataProvider provideInputsWithDistinctXmpRows
     */
    public function testOutputOfAnInputWithXmpHoldsOnlyTheInputsPacket(string $fixtureName, string $format, int $width, int $height): void
    {
        $this->assertTheExpectedPacketLiterals();
        $imageBytes = $this->buildCheckedFixture($fixtureName);
        $this->assertHoldsExactlyOneXmpPacket($imageBytes, self::DISTINCT_PACKET, self::PRECONDITION . $fixtureName);

        $stampedImageBytes = $this->buildStampedImageBytes($fixtureName);

        $this->assertDecodesAs($stampedImageBytes, $format, $width, $height, "criterion 23: {$fixtureName}: the output");
        $this->assertHoldsExactlyOneXmpPacket(
            $stampedImageBytes,
            self::DISTINCT_PACKET,
            "criterion 23: {$fixtureName}, GENERATED: the output holds exactly one XMP packet, the input's",
        );
    }

    public function testOutputOfAPngWithOnlyRawProfileXmpGetsNoXmpChunk(): void
    {
        $this->buildCheckedFixture(self::FIXTURE_PNG_WITH_ONLY_RAW_PROFILE_XMP);

        $stampedImageBytes = $this->buildStampedImageBytes(self::FIXTURE_PNG_WITH_ONLY_RAW_PROFILE_XMP);

        $this->assertDecodesAs($stampedImageBytes, 'PNG', 600, 400, 'criterion 23: the output');
        self::assertSame(
            [],
            ImageStructureWalker::listPngXmpChunks($stampedImageBytes),
            'criterion 23: the output of a PNG carrying only a Raw profile type xmp chunk holds no XML:com.adobe.xmp chunk',
        );
    }

    public function testOutputOfAJpegWithOnlyExtendedXmpCarriesTheGeneratedPacketBesideTheExtendedSegment(): void
    {
        $this->assertTheExpectedPacketLiterals();
        $this->buildCheckedFixture(self::FIXTURE_JPEG_WITH_ONLY_EXTENDED_XMP);
        $extendedXmpPayloadOfTheInput = ImageMarkFixtureBuilder::buildExtendedXmpApp1Payload(self::DISTINCT_PACKET);

        $stampedImageBytes = $this->buildStampedImageBytes(self::FIXTURE_JPEG_WITH_ONLY_EXTENDED_XMP);

        // read with the walker, not assertCarriesThePacket(), which rejects an extended segment; order not asserted
        $this->assertDecodesAs($stampedImageBytes, 'JPEG', 640, 480, 'criterion 33: the output');
        $mainXmpSegments = ImageStructureWalker::listJpegSegmentsOfKind($stampedImageBytes, ImageStructureWalker::JPEG_KIND_XMP);
        self::assertCount(1, $mainXmpSegments, 'criterion 33: the output holds exactly one main XMP APP1');
        self::assertSame(
            ImageMarkFixtureBuilder::XMP_IDENTIFIER . self::EXPECTED_GENERATED_PACKET,
            $mainXmpSegments[0]['payload'],
            'criterion 33: the main XMP APP1 holds the generated packet, whole',
        );
        self::assertSame(
            [$extendedXmpPayloadOfTheInput],
            array_column(ImageStructureWalker::listJpegSegmentsOfKind($stampedImageBytes, ImageStructureWalker::JPEG_KIND_EXTENDED_XMP), 'payload'),
            'criterion 33: the output holds exactly one extended XMP APP1, the input\'s payload byte for byte',
        );
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Errors (criteria 24-28): the stamper throws and never returns the input
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * @dataProvider provideOtherFormatRows
     */
    public function testOtherFormatsThrow(string $fixtureName): void
    {
        $imageBytes = $this->buildCheckedFixture($fixtureName);

        $this->expectException(InvalidArgumentException::class);

        $this->getStamper()->buildLabelledImageBytes($imageBytes, AIImageLabelVariant::GENERATED, $this->buildTestStampSettings());
    }

    public function testUndecodableImageThrows(): void
    {
        $imageBytes = $this->buildCheckedFixture(self::FIXTURE_JPEG_WITH_SOS_BUT_NO_SOF);

        $this->expectException(ImagickException::class);

        $this->getStamper()->buildLabelledImageBytes($imageBytes, AIImageLabelVariant::GENERATED, $this->buildTestStampSettings());
    }

    public function testUnknownVariantThrows(): void
    {
        $imageBytes = $this->buildCheckedFixture(self::FIXTURE_NOISY_PNG_600X400);

        $this->expectException(InvalidArgumentException::class);

        $this->getStamper()->buildLabelledImageBytes($imageBytes, 'NOT_AI', $this->buildTestStampSettings());
    }

    public function testUnreadableMarkThrows(): void
    {
        $imageBytes = $this->buildCheckedFixture(self::FIXTURE_PNG_WITH_CORRUPT_XMP);

        $this->expectException(UnexpectedValueException::class);

        $this->getStamper()->buildLabelledImageBytes($imageBytes, AIImageLabelVariant::GENERATED, $this->buildTestStampSettings());
    }

    /**
     * @dataProvider provideImageTooSmallRows
     */
    public function testImageTooSmallForTheIconThrows(string $fixtureName, bool $fitsTheIcon): void
    {
        $imageBytes = $this->buildCheckedFixture($fixtureName);

        if (!$fitsTheIcon) {
            $this->expectException(LengthException::class);
        }
        $stampedImageBytes = $this->getStamper()->buildLabelledImageBytes($imageBytes, AIImageLabelVariant::GENERATED, $this->buildTestStampSettings());

        $this->assertDecodesAs($stampedImageBytes, 'PNG', 25, 25, 'criterion 28: the 25x25 output');
        $this->assertChangedPixelsFillTheSquare(
            $imageBytes,
            $stampedImageBytes,
            ['left' => 0, 'top' => 1, 'right' => 24, 'bottom' => 25],
            self::CHANGED_PIXEL_THRESHOLD_PNG,
            1,
            'criterion 28: 25x25 gets the 24 px disc with margin 1 (it fits exactly)',
        );
    }

    // ---------------------------------------------------------------------------------------------------------------
    // The container (criterion 33)
    // ---------------------------------------------------------------------------------------------------------------

    public function testStamperResolvesFromTheContainer(): void
    {
        self::assertInstanceOf(
            AIImageLabelStamper::class,
            DDDService::instance()->getService(AIImageLabelStamper::class),
            'criterion 33: the container resolves the stamper',
        );
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Data providers (fixture names only: providers run before setUp() can skip)
    // ---------------------------------------------------------------------------------------------------------------

    /** @return iterable<string, array{string, bool, string}> */
    public static function provideGrayscaleJpegRows(): iterable
    {
        yield 'light' => [self::FIXTURE_LIGHT_GRAYSCALE_JPEG_640X480, true, 'a light grayscale JPEG gets the black icon'];
        yield 'dark' => [self::FIXTURE_DARK_GRAYSCALE_JPEG_640X480, false, 'a dark grayscale JPEG gets the white icon'];
    }

    /** @return iterable<string, array{int}> */
    public static function provideOrientationRows(): iterable
    {
        foreach (array_keys(self::ROTATED_JPEG_FIXTURE_NAMES_BY_ORIENTATION) as $orientation) {
            yield "orientation {$orientation}" => [$orientation];
        }
    }

    /** @return iterable<string, array{string, int, int, int}> */
    public static function provideQualityAndSamplingRows(): iterable
    {
        yield 'quality 95, sampling 1x1' => [self::FIXTURE_NOISY_JPEG_Q95_1X1, 95, 1, 1];
        yield 'quality 75, sampling 2x2' => [self::FIXTURE_NOISY_JPEG_Q75_2X2, 75, 2, 2];
    }

    /** @return iterable<string, array{string}> */
    public static function provideOtherFormatRows(): iterable
    {
        yield 'GIF' => [self::FIXTURE_GIF];
        yield 'WebP' => [self::FIXTURE_WEBP];
    }

    /** @return iterable<string, array{string, bool}> */
    public static function provideImageTooSmallRows(): iterable
    {
        yield '25x25 fits' => [self::FIXTURE_NOISY_PNG_25X25, true];
        yield '24x24 throws' => [self::FIXTURE_NOISY_PNG_24X24, false];
    }

    /** @return iterable<string, array{string}> */
    public static function provideC2paRows(): iterable
    {
        yield 'JPEG' => [self::FIXTURE_JPEG_WITH_ALL_FOUR_MARKS];
        yield 'PNG' => [self::FIXTURE_PNG_WITH_EXIF_XMP_AND_CABX];
    }

    /** @return iterable<string, array{string, string, string, int, int}> */
    public static function provideInputsWithoutXmpRows(): iterable
    {
        foreach (AIImageLabelVariant::ALL_VARIANTS as $aiImageLabelVariant) {
            yield "JPEG without marks, {$aiImageLabelVariant}" => [self::FIXTURE_NOISY_JPEG_640X480, $aiImageLabelVariant, 'JPEG', 640, 480];
            yield "PNG without marks, {$aiImageLabelVariant}" => [self::FIXTURE_NOISY_PNG_600X400, $aiImageLabelVariant, 'PNG', 600, 400];
            yield "JPEG with only C2PA, {$aiImageLabelVariant}" => [self::FIXTURE_JPEG_WITH_ONLY_C2PA, $aiImageLabelVariant, 'JPEG', 640, 480];
        }
    }

    /** @return iterable<string, array{string, string, int, int}> */
    public static function provideInputsWithDistinctXmpRows(): iterable
    {
        yield 'JPEG' => [self::FIXTURE_JPEG_WITH_DISTINCT_XMP, 'JPEG', 640, 480];
        yield 'PNG' => [self::FIXTURE_PNG_WITH_DISTINCT_XMP, 'PNG', 600, 400];
    }

    /** @return iterable<string, array{string, int, bool}> */
    public static function providePngPixelsOutsideTheIconRows(): iterable
    {
        yield 'RGB' => [self::FIXTURE_NOISY_PNG_600X400, 8, false];
        yield 'RGBA with varying alpha' => [self::FIXTURE_NOISY_RGBA_PNG_600X400, 8, true];
        yield '16-bit' => [self::FIXTURE_NOISY_SIXTEEN_BIT_PNG_600X400, 16, false];
        yield 'palette' => [self::FIXTURE_NOISY_PALETTE_PNG_600X400, 8, false];
    }

    /** @return iterable<string, array{string, bool, string}> */
    public static function provideCornerBrightnessRows(): iterable
    {
        yield '#8C8C8C' => [self::FIXTURE_FLAT_PNG_8C8C8C, true, 'flat #8C8C8C (luma 0.549) gets the black icon'];
        yield '#737373' => [self::FIXTURE_FLAT_PNG_737373, false, 'flat #737373 (luma 0.451) gets the white icon'];
        yield '#00C800' => [self::FIXTURE_FLAT_PNG_00C800, true, 'flat #00C800 (Rec. 709 luma 0.561; Rec. 601 0.460, mean 0.261) gets the black icon'];
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function providePinnedColourRows(): iterable
    {
        yield 'pinned black on a dark corner' => [
            self::FIXTURE_LIGHT_PNG_WITH_DARK_CORNER,
            AIImageLabelStampSettings::COLOUR_BLACK,
            'pinned black on the dark corner of criterion 7',
        ];
        yield 'pinned white on a light corner' => [
            self::FIXTURE_DARK_PNG_WITH_LIGHT_CORNER,
            AIImageLabelStampSettings::COLOUR_WHITE,
            'pinned white on the light corner of criterion 6',
        ];
    }

    /** @return iterable<string, array{string, array{left: int, top: int, right: int, bottom: int}, string}> */
    public static function provideShippedDefaultsRows(): iterable
    {
        yield '1000x750' => [
            self::FIXTURE_NOISY_PNG_1000X750,
            ['left' => 944, 'top' => 11, 'right' => 989, 'bottom' => 56],
            '1000x750 gets a 45 px disc with margin 11 (11.25)',
        ];
        yield '400x500' => [
            self::FIXTURE_NOISY_PNG_400X500,
            ['left' => 354, 'top' => 6, 'right' => 394, 'bottom' => 46],
            '400x500 gets the 40 px minimum (the share gives 24) with margin 6',
        ];
    }

    /** @return iterable<string, array{string, string, int, int, string}> */
    public static function provideFormatAndDimensionsRows(): iterable
    {
        yield 'JPEG' => [self::FIXTURE_NOISY_JPEG_640X480, 'JPEG', 640, 480, 'criterion 1'];
        yield 'PNG' => [self::FIXTURE_NOISY_PNG_600X400, 'PNG', 600, 400, 'criterion 2'];
    }

    /** @return iterable<string, array{string, string, array{left: int, top: int, right: int, bottom: int}}> */
    public static function provideCornerRows(): iterable
    {
        yield '600x400 TOP_RIGHT' => [
            self::FIXTURE_NOISY_PNG_600X400,
            AIImageLabelStampSettings::CORNER_TOP_RIGHT,
            ['left' => 540, 'top' => 20, 'right' => 580, 'bottom' => 60],
        ];
        yield '600x400 TOP_LEFT' => [
            self::FIXTURE_NOISY_PNG_600X400,
            AIImageLabelStampSettings::CORNER_TOP_LEFT,
            ['left' => 20, 'top' => 20, 'right' => 60, 'bottom' => 60],
        ];
        yield '600x400 BOTTOM_RIGHT' => [
            self::FIXTURE_NOISY_PNG_600X400,
            AIImageLabelStampSettings::CORNER_BOTTOM_RIGHT,
            ['left' => 540, 'top' => 340, 'right' => 580, 'bottom' => 380],
        ];
        yield '600x400 BOTTOM_LEFT' => [
            self::FIXTURE_NOISY_PNG_600X400,
            AIImageLabelStampSettings::CORNER_BOTTOM_LEFT,
            ['left' => 20, 'top' => 340, 'right' => 60, 'bottom' => 380],
        ];
        yield '400x600 portrait TOP_RIGHT (sized on the shorter side)' => [
            self::FIXTURE_NOISY_PNG_400X600,
            AIImageLabelStampSettings::CORNER_TOP_RIGHT,
            ['left' => 340, 'top' => 20, 'right' => 380, 'bottom' => 60],
        ];
    }

    // ---------------------------------------------------------------------------------------------------------------
    // The stamp
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Test settings T of the accepted criteria: size share 0.10, minimum 24 px, margin share 0.05, both variants on the solid pair
     * unless stated, icons from the committed folder; asserts that every icon path lies in that folder (criterion 34).
     *
     * @param array<string, string> $editedIconFileNames
     */
    protected function buildTestStampSettings(
        string $corner = AIImageLabelStampSettings::CORNER_TOP_RIGHT,
        string $colourChoice = AIImageLabelStampSettings::COLOUR_AUTOMATIC,
        array $editedIconFileNames = self::SOLID_ICON_FILE_NAMES,
    ): AIImageLabelStampSettings {
        $committedIconDirectoryPath = $this->getCommittedIconDirectoryPath();
        $stampSettings = AIImageLabelStampSettings::fromConfigArray(
            [
                AIImageLabelStampSettings::CONFIG_KEY_CORNER => $corner,
                AIImageLabelStampSettings::CONFIG_KEY_ICON_DIAMETER_SHARE => 0.10,
                AIImageLabelStampSettings::CONFIG_KEY_MINIMUM_ICON_DIAMETER => 24,
                AIImageLabelStampSettings::CONFIG_KEY_MARGIN_SHARE => 0.05,
                AIImageLabelStampSettings::CONFIG_KEY_COLOUR => $colourChoice,
                AIImageLabelStampSettings::CONFIG_KEY_ICON_FILE_NAMES_BY_VARIANT => [
                    AIImageLabelVariant::GENERATED => self::SOLID_ICON_FILE_NAMES,
                    AIImageLabelVariant::EDITED => $editedIconFileNames,
                ],
                'designVersion' => 1,
            ],
            $committedIconDirectoryPath,
        );
        foreach (AIImageLabelVariant::ALL_VARIANTS as $aiImageLabelVariant) {
            foreach (AIImageLabelStampSettings::ALL_ICON_COLOURS as $iconColour) {
                self::assertStringStartsWith(
                    $committedIconDirectoryPath . '/',
                    $stampSettings->getIconFilePath($aiImageLabelVariant, $iconColour),
                    'criterion 34: every icon path of the test settings lies in the committed folder',
                );
            }
        }
        return $stampSettings;
    }

    /**
     * Stamps a fixture (once per process for the same fixture, variant and settings) with the stamper from the
     * container: with $stampSettings, else with test settings T, or with no settings at all (the shipped config) when
     * $useShippedConfig is true.
     */
    protected function buildStampedImageBytes(
        string $fixtureName,
        string $aiImageLabelVariant = AIImageLabelVariant::GENERATED,
        ?AIImageLabelStampSettings $stampSettings = null,
        bool $useShippedConfig = false,
    ): string {
        $stampSettings = $useShippedConfig ? null : ($stampSettings ?? $this->buildTestStampSettings());
        $cacheKey = $fixtureName . ':' . $aiImageLabelVariant . ':' . ($stampSettings === null ? 'shipped config' : serialize($stampSettings));
        if (!isset(self::$stampedImageBytesByCacheKey[$cacheKey])) {
            self::$stampedImageBytesByCacheKey[$cacheKey] = $this->getStamper()->buildLabelledImageBytes(
                $this->buildCheckedFixture($fixtureName),
                $aiImageLabelVariant,
                $stampSettings,
            );
        }
        return self::$stampedImageBytesByCacheKey[$cacheKey];
    }

    protected function getStamper(): AIImageLabelStamper
    {
        /** @var AIImageLabelStamper $aiImageLabelStamper */
        $aiImageLabelStamper = DDDService::instance()->getService(AIImageLabelStamper::class);
        return $aiImageLabelStamper;
    }

    /** The committed icon folder's real path. */
    protected function getCommittedIconDirectoryPath(): string
    {
        $iconDirectoryPath = realpath(dirname(__DIR__, 4) . '/' . self::ICON_DIRECTORY_RELATIVE_TO_APP);
        self::assertIsString($iconDirectoryPath, self::PRECONDITION . 'the committed icon folder exists');
        return $iconDirectoryPath;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Assertions
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * The changed pixels lie inside the expected square, and their bounding box comes within $edgeTolerance pixels of
     * every edge of it.
     *
     * @param array{left: int, top: int, right: int, bottom: int} $expectedSquare Half-open pixel ranges
     */
    protected function assertChangedPixelsFillTheSquare(
        string $inputImageBytes,
        string $outputImageBytes,
        array $expectedSquare,
        int $changedPixelThreshold,
        int $edgeTolerance,
        string $label,
    ): void {
        $changedPixelBoundingBox = $this->buildChangedPixelBoundingBox($inputImageBytes, $outputImageBytes, $changedPixelThreshold);
        self::assertNotNull($changedPixelBoundingBox, "{$label}: some pixels changed");
        $message = sprintf(
            '%s: the changed pixels [%d,%d)x[%d,%d) lie inside [%d,%d)x[%d,%d) and come within %d px of each edge',
            $label,
            $changedPixelBoundingBox['left'],
            $changedPixelBoundingBox['right'],
            $changedPixelBoundingBox['top'],
            $changedPixelBoundingBox['bottom'],
            $expectedSquare['left'],
            $expectedSquare['right'],
            $expectedSquare['top'],
            $expectedSquare['bottom'],
            $edgeTolerance,
        );
        self::assertGreaterThanOrEqual($expectedSquare['left'], $changedPixelBoundingBox['left'], $message);
        self::assertLessThanOrEqual($expectedSquare['left'] + $edgeTolerance, $changedPixelBoundingBox['left'], $message);
        self::assertGreaterThanOrEqual($expectedSquare['top'], $changedPixelBoundingBox['top'], $message);
        self::assertLessThanOrEqual($expectedSquare['top'] + $edgeTolerance, $changedPixelBoundingBox['top'], $message);
        self::assertLessThanOrEqual($expectedSquare['right'], $changedPixelBoundingBox['right'], $message);
        self::assertGreaterThanOrEqual($expectedSquare['right'] - $edgeTolerance, $changedPixelBoundingBox['right'], $message);
        self::assertLessThanOrEqual($expectedSquare['bottom'], $changedPixelBoundingBox['bottom'], $message);
        self::assertGreaterThanOrEqual($expectedSquare['bottom'] - $edgeTolerance, $changedPixelBoundingBox['bottom'], $message);
    }

    /**
     * Every decoded pixel outside the icon square equals the input's, and so does every pixel inside it whose centre is
     * farther than $unchangedBeyondDistance from the square's centre. With $comparesRgbOnlyWhereTheInputIsVisible,
     * alpha is compared at every pixel and RGB only where the input's alpha is above 0 (an encoder may rewrite the RGB
     * of an invisible pixel).
     *
     * @param array{left: int, top: int, right: int, bottom: int} $iconSquare
     */
    protected function assertPixelsAwayFromTheDiscAreUnchanged(
        string $inputImageBytes,
        string $outputImageBytes,
        array $iconSquare,
        float $unchangedBeyondDistance,
        int $bitDepth,
        bool $comparesRgbOnlyWhereTheInputIsVisible,
        string $label,
    ): void {
        $width = $this->readImageFormatAndDimensions($inputImageBytes)['width'];
        $inputPixelBytes = $this->readRgbaPixelBytes($inputImageBytes, $bitDepth);
        $outputPixelBytes = $this->readRgbaPixelBytes($outputImageBytes, $bitDepth);
        self::assertSame(strlen($inputPixelBytes), strlen($outputPixelBytes), "{$label}: the output has the input's dimensions");
        $sampleByteLength = intdiv($bitDepth, 8);
        $pixelByteLength = 4 * $sampleByteLength;
        $rowByteLength = $width * $pixelByteLength;
        $centreX = ($iconSquare['left'] + $iconSquare['right']) / 2;
        $centreY = ($iconSquare['top'] + $iconSquare['bottom']) / 2;
        $changedPixels = [];
        $comparedPixelCount = 0;
        $rowCount = intdiv(strlen($inputPixelBytes), $rowByteLength);
        for ($y = 0; $y < $rowCount; $y++) {
            $inputRowBytes = substr($inputPixelBytes, $y * $rowByteLength, $rowByteLength);
            $outputRowBytes = substr($outputPixelBytes, $y * $rowByteLength, $rowByteLength);
            $rowCrossesTheSquare = $y >= $iconSquare['top'] && $y < $iconSquare['bottom'];
            if (!$rowCrossesTheSquare && !$comparesRgbOnlyWhereTheInputIsVisible) {
                $comparedPixelCount += $width;
                if ($inputRowBytes !== $outputRowBytes) {
                    $changedPixels[] = "row {$y}";
                }
                continue;
            }
            for ($x = 0; $x < $width; $x++) {
                $isInTheSquare = $rowCrossesTheSquare && $x >= $iconSquare['left'] && $x < $iconSquare['right'];
                if ($isInTheSquare && hypot($x + 0.5 - $centreX, $y + 0.5 - $centreY) <= $unchangedBeyondDistance) {
                    continue;
                }
                $comparedPixelCount++;
                $inputPixel = substr($inputRowBytes, $x * $pixelByteLength, $pixelByteLength);
                $outputPixel = substr($outputRowBytes, $x * $pixelByteLength, $pixelByteLength);
                $alphaOffset = 3 * $sampleByteLength;
                $inputIsVisible = trim(substr($inputPixel, $alphaOffset), "\0") !== '';
                $isUnchanged = $comparesRgbOnlyWhereTheInputIsVisible && !$inputIsVisible
                    ? substr($inputPixel, $alphaOffset) === substr($outputPixel, $alphaOffset)
                    : $inputPixel === $outputPixel;
                if (!$isUnchanged) {
                    $changedPixels[] = "({$x}, {$y})";
                }
            }
        }
        self::assertGreaterThan(0, $comparedPixelCount, "{$label}: pixels were compared");
        self::assertSame(
            [],
            array_slice($changedPixels, 0, 20),
            sprintf('%s: %d of %d compared pixels changed (first 20 listed)', $label, count($changedPixels), $comparedPixelCount),
        );
    }

    /**
     * The largest difference of a decoded 8-bit channel between input and output, over the pixels outside a
     * rectangle.
     *
     * @param array{left: int, top: int, right: int, bottom: int} $excludedRectangle
     */
    protected function computeLargestChannelDifferenceOutside(string $inputImageBytes, string $outputImageBytes, array $excludedRectangle): int
    {
        $width = $this->readImageFormatAndDimensions($inputImageBytes)['width'];
        $inputPixelBytes = $this->readRgbaPixelBytes($inputImageBytes);
        $outputPixelBytes = $this->readRgbaPixelBytes($outputImageBytes);
        self::assertSame(strlen($inputPixelBytes), strlen($outputPixelBytes), 'the output has the input\'s dimensions');
        $rowByteLength = 4 * $width;
        $largestDifference = 0;
        $rowCount = intdiv(strlen($inputPixelBytes), $rowByteLength);
        for ($y = 0; $y < $rowCount; $y++) {
            $inputRowSamples = array_values(unpack('C*', substr($inputPixelBytes, $y * $rowByteLength, $rowByteLength)));
            $outputRowSamples = array_values(unpack('C*', substr($outputPixelBytes, $y * $rowByteLength, $rowByteLength)));
            $rowCrossesTheRectangle = $y >= $excludedRectangle['top'] && $y < $excludedRectangle['bottom'];
            for ($sampleOffset = 0; $sampleOffset < $rowByteLength; $sampleOffset++) {
                $x = intdiv($sampleOffset, 4);
                if ($rowCrossesTheRectangle && $x >= $excludedRectangle['left'] && $x < $excludedRectangle['right']) {
                    continue;
                }
                $largestDifference = max($largestDifference, abs($inputRowSamples[$sampleOffset] - $outputRowSamples[$sampleOffset]));
            }
        }
        return $largestDifference;
    }

    /**
     * The largest difference of a decoded 8-bit sRGB channel between two images of the same size, over the pixels
     * inside a rectangle.
     *
     * @param array{left: int, top: int, right: int, bottom: int} $rectangle
     */
    protected function computeLargestChannelDifferenceInside(string $firstImageBytes, string $secondImageBytes, array $rectangle): int
    {
        $width = $this->readImageFormatAndDimensions($firstImageBytes)['width'];
        $firstPixelBytes = $this->readRgbaPixelBytes($firstImageBytes);
        $secondPixelBytes = $this->readRgbaPixelBytes($secondImageBytes);
        self::assertSame(strlen($firstPixelBytes), strlen($secondPixelBytes), 'both images have the same dimensions');
        $rectangleRowByteLength = 4 * ($rectangle['right'] - $rectangle['left']);
        $largestDifference = 0;
        for ($y = $rectangle['top']; $y < $rectangle['bottom']; $y++) {
            $rowOffset = 4 * ($y * $width + $rectangle['left']);
            $firstSamples = array_values(unpack('C*', substr($firstPixelBytes, $rowOffset, $rectangleRowByteLength)));
            $secondSamples = array_values(unpack('C*', substr($secondPixelBytes, $rowOffset, $rectangleRowByteLength)));
            foreach ($firstSamples as $sampleIndex => $firstSample) {
                $largestDifference = max($largestDifference, abs($firstSample - $secondSamples[$sampleIndex]));
            }
        }
        return $largestDifference;
    }

    /** The black icon: the disc pixel reads at most 40 in every channel. */
    protected function assertDiscPixelIsBlack(string $imageBytes, int $x, int $y, string $label): void
    {
        $discPixel = $this->readPixel($imageBytes, $x, $y);
        foreach (['red', 'green', 'blue'] as $channelName) {
            self::assertLessThanOrEqual(40, $discPixel[$channelName], "{$label}: the disc pixel ({$x}, {$y}) " . json_encode($discPixel));
        }
    }

    /** The white icon: the disc pixel reads at least 215 in every channel. */
    protected function assertDiscPixelIsWhite(string $imageBytes, int $x, int $y, string $label): void
    {
        $discPixel = $this->readPixel($imageBytes, $x, $y);
        foreach (['red', 'green', 'blue'] as $channelName) {
            self::assertGreaterThanOrEqual(215, $discPixel[$channelName], "{$label}: the disc pixel ({$x}, {$y}) " . json_encode($discPixel));
        }
    }

    protected function assertDecodesAs(string $imageBytes, string $format, int $width, int $height, string $label): void
    {
        self::assertSame(
            ['format' => $format, 'width' => $width, 'height' => $height],
            $this->readImageFormatAndDimensions($imageBytes),
            "{$label} decodes as {$format} {$width}x{$height}",
        );
    }

    protected function readImageColourspace(string $imageBytes): int
    {
        $imagick = new Imagick();
        $imagick->pingImageBlob($imageBytes);
        $colourspace = $imagick->getImageColorspace();
        $imagick->clear();
        return $colourspace;
    }

    /** ImageMagick's estimate of a JPEG's quality, read from its quantization tables. */
    protected function readJpegQuality(string $jpegBytes): int
    {
        $imagick = new Imagick();
        $imagick->readImageBlob($jpegBytes);
        $quality = $imagick->getImageCompressionQuality();
        $imagick->clear();
        return $quality;
    }

    /** The EXIF orientation ImageMagick reads from the bytes (0 when undefined). */
    protected function readImageOrientation(string $imageBytes): int
    {
        $imagick = new Imagick();
        $imagick->readImageBlob($imageBytes);
        $orientation = $imagick->getImageOrientation();
        $imagick->clear();
        return $orientation;
    }

    /**
     * @return array{format: string, width: int, height: int} Decoded with Imagick::readImageBlob().
     */
    protected function readImageFormatAndDimensions(string $imageBytes): array
    {
        $imagick = new Imagick();
        $imagick->readImageBlob($imageBytes);
        $formatAndDimensions = [
            'format' => strtoupper($imagick->getImageFormat()),
            'width' => $imagick->getImageWidth(),
            'height' => $imagick->getImageHeight(),
        ];
        $imagick->clear();
        return $formatAndDimensions;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Pixels
    // ---------------------------------------------------------------------------------------------------------------

    /** The decoded image turned upright by ImageMagick from its EXIF orientation. */
    protected function readUprightImagick(string $imageBytes): Imagick
    {
        $imagick = new Imagick();
        $imagick->readImageBlob($imageBytes);
        $imagick->autoOrient();
        return $imagick;
    }

    /** The pixels of an in-memory image as raw 8-bit RGBA bytes, row by row. */
    protected function exportRgbaPixelBytes(Imagick $imagick): string
    {
        $rawImagick = clone $imagick;
        $rawImagick->setImageFormat('RGBA');
        $rawImagick->setImageDepth(8);
        $rgbaPixelBytes = $rawImagick->getImageBlob();
        $rawImagick->clear();
        return $rgbaPixelBytes;
    }

    /**
     * The 8-bit R, G, B samples of a square of an image, row by row.
     *
     * @return int[]
     */
    protected function exportRgbSamples(Imagick $imagick, int $left, int $top, int $sideLength): array
    {
        return $imagick->exportImagePixels($left, $top, $sideLength, $sideLength, 'RGB', Imagick::PIXEL_CHAR);
    }

    /**
     * The mean absolute difference of each channel between two equally sized sample lists, the largest of the three.
     *
     * @param int[] $firstRgbSamples
     * @param int[] $secondRgbSamples
     */
    protected function computeMeanAbsoluteChannelDifference(array $firstRgbSamples, array $secondRgbSamples): float
    {
        self::assertSame(count($firstRgbSamples), count($secondRgbSamples), 'both squares have the same size');
        $absoluteDifferenceSumsByChannel = [0, 0, 0];
        foreach ($firstRgbSamples as $sampleIndex => $firstSample) {
            $absoluteDifferenceSumsByChannel[$sampleIndex % 3] += abs($firstSample - $secondRgbSamples[$sampleIndex]);
        }
        $pixelCount = intdiv(count($firstRgbSamples), 3);
        return max($absoluteDifferenceSumsByChannel) / $pixelCount;
    }

    /**
     * The metric of criterion 21 between the orientation-1 stamp's TOP_RIGHT square and squares that must fail it:
     * its TOP_LEFT square [16,48)x[16,48) (another corner), and its TOP_RIGHT square turned by 90°, turned by 180°
     * and mirrored.
     *
     * @param int[] $referenceSquareSamples
     * @return array<string, float> By control name
     */
    protected function computeOrientationNegativeControlValues(Imagick $uprightReference, array $referenceSquareSamples): array
    {
        $negativeControlValues = [
            'another corner' => $this->computeMeanAbsoluteChannelDifference(
                $referenceSquareSamples,
                $this->exportRgbSamples($uprightReference, 16, 16, 32),
            ),
        ];
        $controlTransformations = ['turned by 90°' => 90, 'turned by 180°' => 180, 'mirrored' => 0];
        foreach ($controlTransformations as $controlName => $rotationDegrees) {
            $controlSquare = $uprightReference->getImageRegion(32, 32, 432, 16);
            if ($rotationDegrees === 0) {
                $controlSquare->flopImage();
            } else {
                $controlSquare->rotateImage(new ImagickPixel('none'), $rotationDegrees);
            }
            $controlSquare->setImagePage(0, 0, 0, 0);
            $negativeControlValues[$controlName] = $this->computeMeanAbsoluteChannelDifference(
                $referenceSquareSamples,
                $this->exportRgbSamples($controlSquare, 0, 0, 32),
            );
            $controlSquare->clear();
        }
        return $negativeControlValues;
    }

    /**
     * One decoded pixel, converted to sRGB, as 8-bit values.
     *
     * @return array{red: int, green: int, blue: int, alpha: int}
     */
    protected function readPixel(string $imageBytes, int $x, int $y): array
    {
        $imagick = new Imagick();
        $imagick->readImageBlob($imageBytes);
        $imagick->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        $imagickPixel = $imagick->getImagePixelColor($x, $y);
        $pixel = [
            'red' => (int)round($imagickPixel->getColorValue(Imagick::COLOR_RED) * 255),
            'green' => (int)round($imagickPixel->getColorValue(Imagick::COLOR_GREEN) * 255),
            'blue' => (int)round($imagickPixel->getColorValue(Imagick::COLOR_BLUE) * 255),
            'alpha' => (int)round($imagickPixel->getColorValue(Imagick::COLOR_ALPHA) * 255),
        ];
        $imagick->clear();
        return $pixel;
    }

    /**
     * The decoded pixels of an image, converted to sRGB, as raw bytes: R, G, B, A per pixel, row by row, $bitDepth bits
     * per sample (alpha fully opaque where the image has no alpha channel). A byte string, not an array: a PHP array of
     * every sample of a 1000x750 image would not fit the test process's memory limit.
     */
    protected function readRgbaPixelBytes(string $imageBytes, int $bitDepth = 8): string
    {
        $imagick = new Imagick();
        $imagick->readImageBlob($imageBytes);
        $imagick->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        $imagick->setImageFormat('RGBA');
        $imagick->setImageDepth($bitDepth);
        $rgbaPixelBytes = $imagick->getImageBlob();
        $imagick->clear();
        return $rgbaPixelBytes;
    }

    /**
     * The bounding box of the pixels whose decoded RGBA differs from the input's by more than $changedPixelThreshold in
     * a channel, or null when none does; both images must have the same dimensions.
     *
     * @return array{left: int, top: int, right: int, bottom: int}|null Half-open pixel ranges
     */
    protected function buildChangedPixelBoundingBox(string $inputImageBytes, string $outputImageBytes, int $changedPixelThreshold): ?array
    {
        $inputDimensions = $this->readImageFormatAndDimensions($inputImageBytes);
        $outputDimensions = $this->readImageFormatAndDimensions($outputImageBytes);
        self::assertSame(
            [$inputDimensions['width'], $inputDimensions['height']],
            [$outputDimensions['width'], $outputDimensions['height']],
            'the output has the input\'s dimensions',
        );
        $width = $inputDimensions['width'];
        $inputPixelBytes = $this->readRgbaPixelBytes($inputImageBytes);
        $outputPixelBytes = $this->readRgbaPixelBytes($outputImageBytes);
        $rowByteLength = 4 * $width;
        $boundingBox = null;
        for ($y = 0; $y < $inputDimensions['height']; $y++) {
            $inputRowBytes = substr($inputPixelBytes, $y * $rowByteLength, $rowByteLength);
            $outputRowBytes = substr($outputPixelBytes, $y * $rowByteLength, $rowByteLength);
            if ($inputRowBytes === $outputRowBytes) {
                continue;
            }
            $inputRowSamples = array_values(unpack('C*', $inputRowBytes));
            $outputRowSamples = array_values(unpack('C*', $outputRowBytes));
            for ($x = 0; $x < $width; $x++) {
                if (!$this->isPixelChanged($inputRowSamples, $outputRowSamples, 4 * $x, $changedPixelThreshold)) {
                    continue;
                }
                $boundingBox = $boundingBox === null
                    ? ['left' => $x, 'top' => $y, 'right' => $x + 1, 'bottom' => $y + 1]
                    : [
                        'left' => min($boundingBox['left'], $x),
                        'top' => min($boundingBox['top'], $y),
                        'right' => max($boundingBox['right'], $x + 1),
                        'bottom' => max($boundingBox['bottom'], $y + 1),
                    ];
            }
        }
        return $boundingBox;
    }

    /**
     * @param int[] $inputSamples
     * @param int[] $outputSamples
     */
    protected function isPixelChanged(array $inputSamples, array $outputSamples, int $firstSampleOffset, int $changedPixelThreshold): bool
    {
        for ($sampleOffset = $firstSampleOffset; $sampleOffset < $firstSampleOffset + 4; $sampleOffset++) {
            if (abs($inputSamples[$sampleOffset] - $outputSamples[$sampleOffset]) > $changedPixelThreshold) {
                return true;
            }
        }
        return false;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------------------------------------------

    /** Builds (once per process) the named fixture and asserts its preconditions. */
    protected function buildCheckedFixture(string $fixtureName): string
    {
        $cacheKey = 'fixture:' . $fixtureName;
        if (!isset(self::$imageBytesByCacheKey[$cacheKey])) {
            self::$imageBytesByCacheKey[$cacheKey] = $this->buildFixtureImage($fixtureName);
        }
        $this->assertFixturePreconditions($fixtureName, self::$imageBytesByCacheKey[$cacheKey]);
        return self::$imageBytesByCacheKey[$cacheKey];
    }

    protected function buildFixtureImage(string $fixtureName): string
    {
        $orientation = array_search($fixtureName, self::ROTATED_JPEG_FIXTURE_NAMES_BY_ORIENTATION, true);
        if (is_int($orientation)) {
            return $this->buildRotatedJpeg($orientation);
        }
        return match ($fixtureName) {
            self::FIXTURE_NOISY_JPEG_640X480 => $this->buildNoisyJpeg(640, 480, 85),
            self::FIXTURE_NOISY_PNG_600X400 => $this->buildNoisyPng(600, 400),
            self::FIXTURE_NOISY_PNG_400X600 => $this->buildNoisyPng(400, 600),
            self::FIXTURE_NOISY_PNG_200X150 => $this->buildNoisyPng(200, 150),
            self::FIXTURE_NOISY_PNG_1000X750 => $this->buildNoisyPng(1000, 750),
            self::FIXTURE_NOISY_PNG_400X500 => $this->buildNoisyPng(400, 500),
            self::FIXTURE_DARK_PNG_WITH_LIGHT_CORNER => $this->buildPngWithTopRightSquare('#202020', '#E0E0E0'),
            self::FIXTURE_LIGHT_PNG_WITH_DARK_CORNER => $this->buildPngWithTopRightSquare('#E0E0E0', '#202020'),
            self::FIXTURE_FLAT_PNG_8C8C8C => $this->buildFlatPng('#8C8C8C'),
            self::FIXTURE_FLAT_PNG_737373 => $this->buildFlatPng('#737373'),
            self::FIXTURE_FLAT_PNG_00C800 => $this->buildFlatPng('#00C800'),
            self::FIXTURE_FLAT_PNG_E0E0E0 => $this->buildFlatPng('#E0E0E0'),
            self::FIXTURE_TRANSPARENT_PNG => $this->buildTransparentPng(),
            self::FIXTURE_NOISY_RGBA_PNG_600X400 => $this->buildNoisyRgbaPng(600, 400),
            self::FIXTURE_NOISY_SIXTEEN_BIT_PNG_600X400 => $this->buildNoisySixteenBitPng(600, 400),
            self::FIXTURE_NOISY_PALETTE_PNG_600X400 => $this->buildNoisyPalettePng(600, 400),
            self::FIXTURE_LIGHT_GRAYSCALE_JPEG_640X480 => $this->buildFlatGrayscaleJpeg('#E0E0E0'),
            self::FIXTURE_DARK_GRAYSCALE_JPEG_640X480 => $this->buildFlatGrayscaleJpeg('#202020'),
            self::FIXTURE_JPEG_WITH_ALL_FOUR_MARKS => ImageMarkFixtureBuilder::insertIntoJpegAfterApp0OrSoi(
                $this->buildNoisyJpeg(640, 480, 85),
                ImageMarkFixtureBuilder::buildAllFourJpegMarkSegments(),
            ),
            self::FIXTURE_PNG_WITH_EXIF_XMP_AND_CABX => ImageMarkFixtureBuilder::insertIntoPngAfterIhdr(
                $this->buildNoisyPng(600, 400),
                ImageMarkFixtureBuilder::buildExifXmpAndCaBxPngChunks(),
            ),
            self::FIXTURE_JPEG_WITH_ICC_PROFILE => ImageMarkFixtureBuilder::insertIntoJpegAfterApp0OrSoi(
                $this->buildNoisyJpeg(640, 480, 85),
                ImageMarkFixtureBuilder::buildJpegSegment(0xE2, self::ICC_PROFILE_APP2_IDENTIFIER . "\x01\x01" . $this->readVendorSrgbIccProfile()),
            ),
            self::FIXTURE_GIF => $this->buildFlatImageInFormat('gif'),
            self::FIXTURE_WEBP => $this->buildFlatImageInFormat('webp'),
            self::FIXTURE_JPEG_WITH_SOS_BUT_NO_SOF => $this->buildJpegWithSosButNoSof(),
            self::FIXTURE_PNG_WITH_CORRUPT_XMP => ImageMarkFixtureBuilder::insertIntoPngAfterIhdr(
                $this->buildNoisyPng(600, 400),
                ImageMarkFixtureBuilder::buildPngXmpITxtChunkWithCorruptZlibStream(),
            ),
            self::FIXTURE_NOISY_PNG_25X25 => $this->buildNoisyPng(25, 25),
            self::FIXTURE_NOISY_PNG_24X24 => $this->buildNoisyPng(24, 24),
            self::FIXTURE_WHITE_CMYK_JPEG_640X480 => $this->buildWhiteJpeg(Imagick::COLORSPACE_CMYK),
            self::FIXTURE_WHITE_SRGB_JPEG_640X480 => $this->buildWhiteJpeg(Imagick::COLORSPACE_SRGB),
            self::FIXTURE_NOISY_JPEG_Q95_1X1 => $this->buildNoisyJpeg(640, 480, 95, '1x1'),
            self::FIXTURE_NOISY_JPEG_Q75_2X2 => $this->buildNoisyJpeg(640, 480, 75, '2x2'),
            self::FIXTURE_NOISY_PNG_600X400_WITH_EXIF_ORIENTATION_6 => ImageMarkFixtureBuilder::insertIntoPngAfterIhdr(
                $this->buildNoisyPng(600, 400),
                ImageMarkFixtureBuilder::buildPngChunk('eXIf', ImageMarkFixtureBuilder::buildExifTiffBlock(null, 6)),
            ),
            self::FIXTURE_JPEG_WITH_ONLY_C2PA => ImageMarkFixtureBuilder::insertIntoJpegAfterApp0OrSoi(
                $this->buildNoisyJpeg(640, 480, 85),
                ImageMarkFixtureBuilder::buildJpegSegment(ImageMarkFixtureBuilder::MARKER_APP11, ImageMarkFixtureBuilder::buildJumbfApp11Payload()),
            ),
            self::FIXTURE_JPEG_WITH_DISTINCT_XMP => ImageMarkFixtureBuilder::insertIntoJpegAfterApp0OrSoi(
                $this->buildNoisyJpeg(640, 480, 85),
                ImageMarkFixtureBuilder::buildJpegSegment(ImageMarkFixtureBuilder::MARKER_APP1, ImageMarkFixtureBuilder::buildXmpApp1Payload(self::DISTINCT_PACKET)),
            ),
            self::FIXTURE_PNG_WITH_DISTINCT_XMP => ImageMarkFixtureBuilder::insertIntoPngAfterIhdr(
                $this->buildNoisyPng(600, 400),
                ImageMarkFixtureBuilder::buildPngXmpChunk(self::DISTINCT_PACKET),
            ),
            self::FIXTURE_PNG_WITH_ONLY_RAW_PROFILE_XMP => ImageMarkFixtureBuilder::insertIntoPngAfterIhdr(
                $this->buildNoisyPng(600, 400),
                $this->buildRawProfileXmpTextChunk(self::DISTINCT_PACKET),
            ),
            self::FIXTURE_JPEG_WITH_ONLY_EXTENDED_XMP => ImageMarkFixtureBuilder::insertIntoJpegAfterApp0OrSoi(
                $this->buildNoisyJpeg(640, 480, 85),
                ImageMarkFixtureBuilder::buildJpegSegment(
                    ImageMarkFixtureBuilder::MARKER_APP1,
                    ImageMarkFixtureBuilder::buildExtendedXmpApp1Payload(self::DISTINCT_PACKET),
                ),
            ),
            default => throw new LogicException("No builder for the fixture {$fixtureName}"),
        };
    }

    protected function assertFixturePreconditions(string $fixtureName, string $imageBytes): void
    {
        $orientation = array_search($fixtureName, self::ROTATED_JPEG_FIXTURE_NAMES_BY_ORIENTATION, true);
        if (is_int($orientation)) {
            $this->assertRotatedJpegFixture($imageBytes, $orientation);
            return;
        }
        match ($fixtureName) {
            self::FIXTURE_NOISY_JPEG_640X480 => $this->assertDecodesAs($imageBytes, 'JPEG', 640, 480, self::PRECONDITION . 'the fixture'),
            self::FIXTURE_NOISY_PNG_600X400 => $this->assertDecodesAs($imageBytes, 'PNG', 600, 400, self::PRECONDITION . 'the fixture'),
            self::FIXTURE_NOISY_PNG_400X600 => $this->assertDecodesAs($imageBytes, 'PNG', 400, 600, self::PRECONDITION . 'the fixture'),
            self::FIXTURE_NOISY_PNG_200X150 => $this->assertDecodesAs($imageBytes, 'PNG', 200, 150, self::PRECONDITION . 'the fixture'),
            self::FIXTURE_NOISY_PNG_1000X750 => $this->assertDecodesAs($imageBytes, 'PNG', 1000, 750, self::PRECONDITION . 'the fixture'),
            self::FIXTURE_NOISY_PNG_400X500 => $this->assertDecodesAs($imageBytes, 'PNG', 400, 500, self::PRECONDITION . 'the fixture'),
            self::FIXTURE_DARK_PNG_WITH_LIGHT_CORNER => $this->assertPngWithTopRightSquare($imageBytes, 0x20, 0xE0),
            self::FIXTURE_LIGHT_PNG_WITH_DARK_CORNER => $this->assertPngWithTopRightSquare($imageBytes, 0xE0, 0x20),
            self::FIXTURE_FLAT_PNG_8C8C8C => $this->assertFlatPng($imageBytes, [0x8C, 0x8C, 0x8C]),
            self::FIXTURE_FLAT_PNG_737373 => $this->assertFlatPng($imageBytes, [0x73, 0x73, 0x73]),
            self::FIXTURE_FLAT_PNG_00C800 => $this->assertFlatPng($imageBytes, [0x00, 0xC8, 0x00]),
            self::FIXTURE_FLAT_PNG_E0E0E0 => $this->assertFlatPng($imageBytes, [0xE0, 0xE0, 0xE0]),
            self::FIXTURE_TRANSPARENT_PNG => $this->assertTransparentPng($imageBytes),
            self::FIXTURE_NOISY_RGBA_PNG_600X400 => $this->assertNoisyRgbaPng($imageBytes),
            self::FIXTURE_NOISY_SIXTEEN_BIT_PNG_600X400 => $this->assertPngFixture($imageBytes, 600, 400, 16, 2),
            self::FIXTURE_NOISY_PALETTE_PNG_600X400 => $this->assertPngFixture($imageBytes, 600, 400, 8, 3),
            self::FIXTURE_LIGHT_GRAYSCALE_JPEG_640X480,
            self::FIXTURE_DARK_GRAYSCALE_JPEG_640X480 => $this->assertGrayscaleJpegFixture($imageBytes),
            self::FIXTURE_JPEG_WITH_ALL_FOUR_MARKS => $this->assertJpegWithAllFourMarksFixture($imageBytes),
            self::FIXTURE_PNG_WITH_EXIF_XMP_AND_CABX => $this->assertPngWithExifXmpAndCaBxFixture($imageBytes),
            self::FIXTURE_JPEG_WITH_ICC_PROFILE => $this->assertJpegWithIccProfileFixture($imageBytes),
            self::FIXTURE_GIF => $this->assertGifFixture($imageBytes),
            self::FIXTURE_WEBP => $this->assertWebpFixture($imageBytes),
            self::FIXTURE_JPEG_WITH_SOS_BUT_NO_SOF => $this->assertJpegWithSosButNoSofFixture($imageBytes),
            self::FIXTURE_PNG_WITH_CORRUPT_XMP => $this->assertPngWithCorruptXmpFixture($imageBytes),
            self::FIXTURE_NOISY_PNG_25X25 => $this->assertDecodesAs($imageBytes, 'PNG', 25, 25, self::PRECONDITION . 'the fixture'),
            self::FIXTURE_NOISY_PNG_24X24 => $this->assertDecodesAs($imageBytes, 'PNG', 24, 24, self::PRECONDITION . 'the fixture'),
            self::FIXTURE_WHITE_CMYK_JPEG_640X480 => $this->assertWhiteJpegFixture($imageBytes, Imagick::COLORSPACE_CMYK, 4),
            self::FIXTURE_WHITE_SRGB_JPEG_640X480 => $this->assertWhiteJpegFixture($imageBytes, Imagick::COLORSPACE_SRGB, 3),
            self::FIXTURE_NOISY_JPEG_Q95_1X1 => $this->assertQualityAndSamplingFixture($imageBytes, 95, 1),
            self::FIXTURE_NOISY_JPEG_Q75_2X2 => $this->assertQualityAndSamplingFixture($imageBytes, 75, 2),
            self::FIXTURE_NOISY_PNG_600X400_WITH_EXIF_ORIENTATION_6 => $this->assertPngWithExifOrientation6Fixture($imageBytes),
            self::FIXTURE_JPEG_WITH_ONLY_C2PA => $this->assertJpegWithOnlyC2paFixture($imageBytes),
            self::FIXTURE_JPEG_WITH_DISTINCT_XMP => $this->assertJpegWithOnlyDistinctXmpFixture($imageBytes),
            self::FIXTURE_PNG_WITH_DISTINCT_XMP => $this->assertPngWithOnlyDistinctXmpFixture($imageBytes),
            self::FIXTURE_PNG_WITH_ONLY_RAW_PROFILE_XMP => $this->assertPngWithOnlyRawProfileXmpFixture($imageBytes),
            self::FIXTURE_JPEG_WITH_ONLY_EXTENDED_XMP => $this->assertJpegWithOnlyExtendedXmpFixture($imageBytes),
            default => self::fail("No preconditions for the fixture {$fixtureName}"),
        };
    }

    /** The expected packet literals have the lengths states, and the distinct packet differs from both. */
    protected function assertTheExpectedPacketLiterals(): void
    {
        self::assertSame(393, strlen(self::EXPECTED_GENERATED_PACKET), self::PRECONDITION . 'the generated packet literal is 393 bytes');
        self::assertSame(406, strlen(self::EXPECTED_EDITED_PACKET), self::PRECONDITION . 'the edited packet literal is 406 bytes');
        self::assertNotSame(self::EXPECTED_GENERATED_PACKET, self::DISTINCT_PACKET, self::PRECONDITION . 'the distinct packet is not the generated one');
        self::assertNotSame(self::EXPECTED_EDITED_PACKET, self::DISTINCT_PACKET, self::PRECONDITION . 'the distinct packet is not the edited one');
    }

    /** The noisy JPEG with one APP11 (the fixture JUMBF payload) and no other mark. */
    protected function assertJpegWithOnlyC2paFixture(string $imageBytes): void
    {
        $this->assertDecodesAs($imageBytes, 'JPEG', 640, 480, self::PRECONDITION . 'the fixture');
        self::assertSame(
            [ImageMarkFixtureBuilder::buildJumbfApp11Payload()],
            array_column(ImageStructureWalker::listJpegSegmentsOfKind($imageBytes, ImageStructureWalker::JPEG_KIND_APP11), 'payload'),
            self::PRECONDITION . 'the fixture carries one APP11 with the fixture JUMBF payload',
        );
        foreach ([ImageStructureWalker::JPEG_KIND_EXIF, ImageStructureWalker::JPEG_KIND_IPTC] as $kind) {
            self::assertSame([], ImageStructureWalker::listJpegSegmentsOfKind($imageBytes, $kind), self::PRECONDITION . "the fixture carries no {$kind}");
        }
        $this->assertCarriesNoXmpPacket($imageBytes, self::PRECONDITION . 'the fixture');
    }

    /** The noisy JPEG with one XMP APP1 of the distinct packet and no other mark. */
    protected function assertJpegWithOnlyDistinctXmpFixture(string $imageBytes): void
    {
        $this->assertDecodesAs($imageBytes, 'JPEG', 640, 480, self::PRECONDITION . 'the fixture');
        $this->assertHoldsExactlyOneXmpPacket($imageBytes, self::DISTINCT_PACKET, self::PRECONDITION . 'the fixture');
        foreach ([ImageStructureWalker::JPEG_KIND_EXIF, ImageStructureWalker::JPEG_KIND_IPTC, ImageStructureWalker::JPEG_KIND_APP11] as $kind) {
            self::assertSame([], ImageStructureWalker::listJpegSegmentsOfKind($imageBytes, $kind), self::PRECONDITION . "the fixture carries no {$kind}");
        }
    }

    /**
     * The noisy JPEG with one extended XMP APP1 (identifier `http://ns.adobe.com/xmp/extension/` NUL) and no other
     * mark: no main XMP APP1, Exif, IPTC or APP11.
     */
    protected function assertJpegWithOnlyExtendedXmpFixture(string $imageBytes): void
    {
        $extendedXmpPayload = ImageMarkFixtureBuilder::buildExtendedXmpApp1Payload(self::DISTINCT_PACKET);
        $this->assertDecodesAs($imageBytes, 'JPEG', 640, 480, self::PRECONDITION . 'the fixture');
        self::assertStringStartsWith(ImageMarkFixtureBuilder::EXTENDED_XMP_IDENTIFIER, $extendedXmpPayload, self::PRECONDITION . 'the payload starts with the extended XMP identifier');
        self::assertSame(
            [$extendedXmpPayload],
            array_column(ImageStructureWalker::listJpegSegmentsOfKind($imageBytes, ImageStructureWalker::JPEG_KIND_EXTENDED_XMP), 'payload'),
            self::PRECONDITION . 'the fixture carries one extended XMP APP1',
        );
        foreach ([
            ImageStructureWalker::JPEG_KIND_XMP,
            ImageStructureWalker::JPEG_KIND_EXIF,
            ImageStructureWalker::JPEG_KIND_IPTC,
            ImageStructureWalker::JPEG_KIND_APP11,
        ] as $kind) {
            self::assertSame([], ImageStructureWalker::listJpegSegmentsOfKind($imageBytes, $kind), self::PRECONDITION . "the fixture carries no {$kind}");
        }
    }

    /** The noisy PNG with one XMP iTXt of the distinct packet and no other mark. */
    protected function assertPngWithOnlyDistinctXmpFixture(string $imageBytes): void
    {
        $this->assertPngFixture($imageBytes, 600, 400, 8, 2);
        $this->assertHoldsExactlyOneXmpPacket($imageBytes, self::DISTINCT_PACKET, self::PRECONDITION . 'the fixture');
        foreach (['eXIf', 'caBX'] as $chunkType) {
            self::assertNotContains($chunkType, ImageStructureWalker::listPngChunkTypes($imageBytes), self::PRECONDITION . "the fixture carries no {$chunkType}");
        }
    }

    /** The noisy PNG with one `Raw profile type xmp` tEXt chunk and no other mark. */
    protected function assertPngWithOnlyRawProfileXmpFixture(string $imageBytes): void
    {
        $this->assertPngFixture($imageBytes, 600, 400, 8, 2);
        self::assertSame([], ImageStructureWalker::listPngXmpChunks($imageBytes), self::PRECONDITION . 'the fixture carries no XML:com.adobe.xmp chunk');
        $xmpTextChunks = self::listPngXmpTextChunksOfEitherKeyword($imageBytes);
        self::assertCount(1, $xmpTextChunks, self::PRECONDITION . 'the fixture carries one Raw profile type xmp chunk');
        self::assertSame('tEXt', $xmpTextChunks[0]['type'], self::PRECONDITION . 'as a tEXt');
        self::assertStringStartsWith(self::RAW_PROFILE_XMP_PNG_KEYWORD . "\0", $xmpTextChunks[0]['data'], self::PRECONDITION . 'its keyword');
        foreach (['eXIf', 'caBX'] as $chunkType) {
            self::assertNotContains($chunkType, ImageStructureWalker::listPngChunkTypes($imageBytes), self::PRECONDITION . "the fixture carries no {$chunkType}");
        }
    }

    /**
     * ImageMagick's own XMP text chunk as a `tEXt`: keyword `Raw profile type xmp`, then the profile name, its length
     * and its bytes as hexadecimal text, 72 digits a line.
     */
    protected function buildRawProfileXmpTextChunk(string $xmpPacket): string
    {
        $rawProfileText = "\nxmp\n" . sprintf('%8d', strlen($xmpPacket)) . "\n" . chunk_split(bin2hex($xmpPacket), 72, "\n");
        return ImageMarkFixtureBuilder::buildPngChunk('tEXt', self::RAW_PROFILE_XMP_PNG_KEYWORD . "\0" . $rawProfileText);
    }

    /** A 300x300 grey PNG with a 100x100 square of another grey in its top-right corner. */
    protected function buildPngWithTopRightSquare(string $backgroundColour, string $cornerSquareColour): string
    {
        $imagick = new Imagick();
        $imagick->newImage(300, 300, new ImagickPixel($backgroundColour), 'png');
        $imagickDraw = new ImagickDraw();
        $imagickDraw->setFillColor(new ImagickPixel($cornerSquareColour));
        $imagickDraw->rectangle(200, 0, 299, 99);
        $imagick->drawImage($imagickDraw);
        $imagick->setImageFormat('png24');
        $imagick->stripImage();
        $pngBytes = $imagick->getImageBlob();
        $imagick->clear();
        return $pngBytes;
    }

    /** The fixture is a 300x300 PNG whose top-right 100x100 square is one grey and the rest another. */
    protected function assertPngWithTopRightSquare(string $imageBytes, int $backgroundGrey, int $cornerSquareGrey): void
    {
        $this->assertDecodesAs($imageBytes, 'PNG', 300, 300, self::PRECONDITION . 'the fixture');
        foreach ([[250, 50, $cornerSquareGrey], [200, 0, $cornerSquareGrey], [299, 99, $cornerSquareGrey], [199, 50, $backgroundGrey], [250, 100, $backgroundGrey], [10, 290, $backgroundGrey]] as [$x, $y, $grey]) {
            self::assertSame(
                ['red' => $grey, 'green' => $grey, 'blue' => $grey, 'alpha' => 255],
                $this->readPixel($imageBytes, $x, $y),
                self::PRECONDITION . "the fixture pixel ({$x}, {$y})",
            );
        }
    }

    /** A 300x300 PNG of one colour. */
    protected function buildFlatPng(string $colour): string
    {
        $imagick = new Imagick();
        $imagick->newImage(300, 300, new ImagickPixel($colour), 'png');
        $imagick->setImageFormat('png24');
        $imagick->stripImage();
        $pngBytes = $imagick->getImageBlob();
        $imagick->clear();
        return $pngBytes;
    }

    /**
     * Every pixel of the 300x300 fixture has exactly this opaque colour.
     *
     * @param int[] $expectedRgb
     */
    protected function assertFlatPng(string $imageBytes, array $expectedRgb): void
    {
        $this->assertDecodesAs($imageBytes, 'PNG', 300, 300, self::PRECONDITION . 'the fixture');
        self::assertSame(
            str_repeat(pack('C4', $expectedRgb[0], $expectedRgb[1], $expectedRgb[2], 255), 300 * 300),
            $this->readRgbaPixelBytes($imageBytes),
            self::PRECONDITION . 'every pixel of the fixture has the colour ' . json_encode($expectedRgb),
        );
    }

    /** A 300x300 RGBA PNG whose every pixel is (0,0,0,0). */
    protected function buildTransparentPng(): string
    {
        $imagick = new Imagick();
        $imagick->newImage(300, 300, new ImagickPixel('rgba(0,0,0,0)'), 'png');
        $imagick->setImageFormat('png32');
        $imagick->stripImage();
        $pngBytes = $imagick->getImageBlob();
        $imagick->clear();
        return $pngBytes;
    }

    protected function assertTransparentPng(string $imageBytes): void
    {
        $this->assertDecodesAs($imageBytes, 'PNG', 300, 300, self::PRECONDITION . 'the fixture');
        self::assertSame(
            str_repeat("\0", 4 * 300 * 300),
            $this->readRgbaPixelBytes($imageBytes),
            self::PRECONDITION . 'every pixel of the fixture is exactly (0,0,0,0)',
        );
    }

    /** A 640x480 grayscale JPEG of one grey (quality 85, no metadata). */
    protected function buildFlatGrayscaleJpeg(string $grey): string
    {
        $imagick = new Imagick();
        $imagick->newImage(640, 480, new ImagickPixel($grey), 'jpeg');
        $imagick->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $imagick->setImageCompressionQuality(85);
        $imagick->stripImage();
        $jpegBytes = $imagick->getImageBlob();
        $imagick->clear();
        return $jpegBytes;
    }

    protected function assertGrayscaleJpegFixture(string $imageBytes): void
    {
        $this->assertDecodesAs($imageBytes, 'JPEG', 640, 480, self::PRECONDITION . 'the fixture');
        $this->assertIsOneComponentGrayscaleJpeg($imageBytes, self::PRECONDITION . 'the fixture');
    }

    /** Imagick reads the JPEG as GRAY, and its SOF segment declares one component. */
    protected function assertIsOneComponentGrayscaleJpeg(string $jpegBytes, string $label): void
    {
        $imagick = new Imagick();
        $imagick->pingImageBlob($jpegBytes);
        self::assertSame(Imagick::COLORSPACE_GRAY, $imagick->getImageColorspace(), "{$label}: Imagick reads colourspace GRAY");
        $imagick->clear();
        self::assertSame(1, count($this->readJpegSofComponentSamplingFactors($jpegBytes)), "{$label}: the SOF segment declares 1 component");
    }

    /**
     * @return array<int, array{horizontal: int, vertical: int}> The sampling factors of each component the first SOF
     *     segment declares (SOF0 to SOF15, without DHT, JPG and DAC), in component order
     */
    protected function readJpegSofComponentSamplingFactors(string $jpegBytes): array
    {
        foreach (ImageStructureWalker::listJpegSegmentsUpToFirstScan($jpegBytes) as $segment) {
            if ($segment['marker'] < 0xC0 || $segment['marker'] > 0xCF || in_array($segment['marker'], [0xC4, 0xC8, 0xCC], true)) {
                continue;
            }
            // precision (1), height (2), width (2), component count (1), then 3 bytes per component: id, factors, table
            $componentCount = ord($segment['payload'][5]);
            $samplingFactors = [];
            for ($componentIndex = 0; $componentIndex < $componentCount; $componentIndex++) {
                $factorsByte = ord($segment['payload'][6 + 3 * $componentIndex + 1]);
                $samplingFactors[] = ['horizontal' => $factorsByte >> 4, 'vertical' => $factorsByte & 0x0F];
            }
            return $samplingFactors;
        }
        self::fail('The JPEG has no SOF segment before its first scan');
    }

    protected function assertJpegWithAllFourMarksFixture(string $imageBytes): void
    {
        $this->assertDecodesAs($imageBytes, 'JPEG', 640, 480, self::PRECONDITION . 'the fixture');
        $expectedPayloadsByKind = [
            ImageStructureWalker::JPEG_KIND_EXIF => ImageMarkFixtureBuilder::buildExifApp1Payload(),
            ImageStructureWalker::JPEG_KIND_XMP => ImageMarkFixtureBuilder::buildXmpApp1Payload(),
            ImageStructureWalker::JPEG_KIND_IPTC => ImageMarkFixtureBuilder::buildIptcPhotoshopPayload(),
            ImageStructureWalker::JPEG_KIND_APP11 => ImageMarkFixtureBuilder::buildJumbfApp11Payload(),
        ];
        foreach ($expectedPayloadsByKind as $kind => $expectedPayload) {
            self::assertSame(
                [$expectedPayload],
                array_column(ImageStructureWalker::listJpegSegmentsOfKind($imageBytes, $kind), 'payload'),
                self::PRECONDITION . "the fixture carries one {$kind}",
            );
        }
        self::assertSame(
            1,
            $this->readExifOrientationFromTiffBlock(ImageMarkFixtureBuilder::buildExifTiffBlock()),
            self::PRECONDITION . 'the fixture EXIF says orientation 1',
        );
    }

    /**
     * The Orientation (0x0112) of IFD0 of a little-endian TIFF block (the fixture builder writes `II`), or null when
     * IFD0 has none.
     */
    protected function readExifOrientationFromTiffBlock(string $tiffBlock): ?int
    {
        self::assertStringStartsWith("II\x2A\x00", $tiffBlock, 'the TIFF block is little-endian');
        $ifd0Offset = unpack('V', substr($tiffBlock, 4, 4))[1];
        $entryCount = unpack('v', substr($tiffBlock, $ifd0Offset, 2))[1];
        for ($entryIndex = 0; $entryIndex < $entryCount; $entryIndex++) {
            $ifd0EntryFields = unpack('vtag/vtype/Vcount/vvalue', substr($tiffBlock, $ifd0Offset + 2 + 12 * $entryIndex, 10));
            if ($ifd0EntryFields['tag'] === 0x0112) {
                return $ifd0EntryFields['value'];
            }
        }
        return null;
    }

    protected function assertPngWithExifXmpAndCaBxFixture(string $imageBytes): void
    {
        $this->assertPngFixture($imageBytes, 600, 400, 8, 2);
        self::assertSame(
            [ImageMarkFixtureBuilder::buildExifTiffBlock()],
            array_column(ImageStructureWalker::listPngChunksOfType($imageBytes, 'eXIf'), 'data'),
            self::PRECONDITION . 'the fixture carries one eXIf',
        );
        self::assertSame(
            [ImageMarkFixtureBuilder::buildXmpPacket()],
            ImageStructureWalker::listPngXmpPackets($imageBytes),
            self::PRECONDITION . 'the fixture carries one XMP packet',
        );
        self::assertCount(1, ImageStructureWalker::listPngChunksOfType($imageBytes, 'caBX'), self::PRECONDITION . 'the fixture carries caBX');
    }

    protected function assertJpegWithIccProfileFixture(string $imageBytes): void
    {
        $this->assertDecodesAs($imageBytes, 'JPEG', 640, 480, self::PRECONDITION . 'the fixture');
        self::assertSame(60960, strlen($this->readVendorSrgbIccProfile()), self::PRECONDITION . 'the vendor sRGB profile has 60,960 bytes');
        self::assertSame(
            $this->readVendorSrgbIccProfile(),
            $this->readJpegIccProfile($imageBytes),
            self::PRECONDITION . 'the fixture carries the vendor sRGB profile in its APP2 segments',
        );
    }

    protected function readVendorSrgbIccProfile(): string
    {
        $iccProfile = file_get_contents(dirname(__DIR__, 4) . '/' . self::VENDOR_SRGB_ICC_PROFILE_RELATIVE_TO_APP);
        self::assertIsString($iccProfile, self::PRECONDITION . 'the vendor sRGB profile is readable');
        return $iccProfile;
    }

    /**
     * The ICC profile of a JPEG: the APP2 `ICC_PROFILE` payloads before the first scan, joined in their sequence
     * order without the identifier, the sequence number and the count.
     */
    protected function readJpegIccProfile(string $jpegBytes): string
    {
        $iccProfileChunksBySequenceNumber = [];
        foreach (ImageStructureWalker::listJpegSegmentsUpToFirstScan($jpegBytes) as $segment) {
            if ($segment['marker'] !== 0xE2 || !str_starts_with($segment['payload'], self::ICC_PROFILE_APP2_IDENTIFIER)) {
                continue;
            }
            $sequenceNumber = ord($segment['payload'][strlen(self::ICC_PROFILE_APP2_IDENTIFIER)]);
            $iccProfileChunksBySequenceNumber[$sequenceNumber] = substr($segment['payload'], strlen(self::ICC_PROFILE_APP2_IDENTIFIER) + 2);
        }
        ksort($iccProfileChunksBySequenceNumber);
        return implode('', $iccProfileChunksBySequenceNumber);
    }

    /** The displayed form of the rotated JPEG fixtures: the noisy JPEG pattern at 480x320, in memory. */
    protected function buildDisplayedImagickForRotation(): Imagick
    {
        $displayedImagick = $this->buildNoisyJpegImagick(480, 320);
        $displayedImagick->setImageFormat('png');
        return $displayedImagick;
    }

    /**
     * The test's own table: turns or mirrors an upright (displayed) image into the stored form a viewer turns upright
     * for the EXIF orientation. Pinned to autoOrient() by a precondition of {@see assertRotatedJpegFixture()}.
     */
    protected function turnDisplayedIntoStored(Imagick $imagick, int $orientation): void
    {
        match ($orientation) {
            1 => null,
            2 => $imagick->flopImage(),
            3 => $imagick->rotateImage(new ImagickPixel('none'), 180),
            4 => $imagick->flipImage(),
            5 => $imagick->transposeImage(),
            6 => $imagick->rotateImage(new ImagickPixel('none'), 270),
            7 => $imagick->transverseImage(),
            8 => $imagick->rotateImage(new ImagickPixel('none'), 90),
            default => throw new LogicException("{$orientation} is not an EXIF orientation"),
        };
        $imagick->setImagePage(0, 0, 0, 0);
    }

    /** The displayed pattern stored for the orientation, as a JPEG (quality 85) with an Exif APP1 carrying the tag. */
    protected function buildRotatedJpeg(int $orientation): string
    {
        $storedImagick = $this->buildDisplayedImagickForRotation();
        $this->turnDisplayedIntoStored($storedImagick, $orientation);
        $storedImagick->setImageFormat('jpeg');
        $storedImagick->setImageCompressionQuality(85);
        $storedImagick->stripImage();
        $storedJpegBytes = $storedImagick->getImageBlob();
        $storedImagick->clear();
        return ImageMarkFixtureBuilder::insertIntoJpegAfterApp0OrSoi(
            $storedJpegBytes,
            ImageMarkFixtureBuilder::buildJpegSegment(ImageMarkFixtureBuilder::MARKER_APP1, ImageMarkFixtureBuilder::buildExifApp1Payload(null, $orientation)),
        );
    }

    protected function assertRotatedJpegFixture(string $imageBytes, int $orientation): void
    {
        [$storedWidth, $storedHeight] = $orientation >= 5 ? [320, 480] : [480, 320];
        $this->assertDecodesAs($imageBytes, 'JPEG', $storedWidth, $storedHeight, self::PRECONDITION . "the orientation-{$orientation} fixture");
        self::assertSame($orientation, $this->readImageOrientation($imageBytes), self::PRECONDITION . "getImageOrientation() reads {$orientation} on the encoded fixture");
        if ($orientation === 1) {
            return;
        }
        // the table, pinned to an oracle the test did not write: the stored pixels turned upright by ImageMagick
        $displayedImagick = $this->buildDisplayedImagickForRotation();
        $storedImagick = $this->buildDisplayedImagickForRotation();
        $this->turnDisplayedIntoStored($storedImagick, $orientation);
        $storedImagick->setImageOrientation($orientation);
        $storedImagick->autoOrient();
        $uprightPixelBytes = $this->exportRgbaPixelBytes($storedImagick);
        $displayedPixelBytes = $this->exportRgbaPixelBytes($displayedImagick);
        $storedImagick->clear();
        $displayedImagick->clear();
        self::assertSame(
            hash('sha256', $displayedPixelBytes),
            hash('sha256', $uprightPixelBytes),
            self::PRECONDITION . "the stored form for orientation {$orientation}, turned upright by autoOrient(), equals the displayed image pixel for pixel",
        );
    }

    /**
     * A white 640x480 JPEG (quality 85, no metadata, no profile) in the given colourspace; an sRGB one is kept
     * truecolour (ImageMagick would write an all-grey image as a grayscale JPEG).
     */
    protected function buildWhiteJpeg(int $colourspace): string
    {
        $imagick = new Imagick();
        $imagick->newImage(640, 480, new ImagickPixel('white'), 'jpeg');
        $imagick->transformImageColorspace($colourspace);
        if ($colourspace === Imagick::COLORSPACE_SRGB) {
            $imagick->setType(Imagick::IMGTYPE_TRUECOLOR);
        }
        $imagick->setImageCompressionQuality(85);
        $imagick->stripImage();
        $jpegBytes = $imagick->getImageBlob();
        $imagick->clear();
        return $jpegBytes;
    }

    protected function assertWhiteJpegFixture(string $imageBytes, int $colourspace, int $componentCount): void
    {
        $this->assertDecodesAs($imageBytes, 'JPEG', 640, 480, self::PRECONDITION . 'the fixture');
        self::assertSame($colourspace, $this->readImageColourspace($imageBytes), self::PRECONDITION . 'the fixture colourspace');
        self::assertCount($componentCount, $this->readJpegSofComponentSamplingFactors($imageBytes), self::PRECONDITION . "the SOF declares {$componentCount} components");
        self::assertSame('', $this->readJpegIccProfile($imageBytes), self::PRECONDITION . 'the fixture carries no ICC profile');
        self::assertSame(['red' => 255, 'green' => 255, 'blue' => 255, 'alpha' => 255], $this->readPixel($imageBytes, 573, 48), self::PRECONDITION . 'the fixture is white');
    }

    protected function assertQualityAndSamplingFixture(string $imageBytes, int $quality, int $samplingFactor): void
    {
        $this->assertDecodesAs($imageBytes, 'JPEG', 640, 480, self::PRECONDITION . 'the fixture');
        self::assertSame($quality, $this->readJpegQuality($imageBytes), self::PRECONDITION . "the fixture's quality estimate reads {$quality}");
        self::assertSame(
            [
                ['horizontal' => $samplingFactor, 'vertical' => $samplingFactor],
                ['horizontal' => 1, 'vertical' => 1],
                ['horizontal' => 1, 'vertical' => 1],
            ],
            $this->readJpegSofComponentSamplingFactors($imageBytes),
            self::PRECONDITION . "the fixture's SOF declares the sampling {$samplingFactor}x{$samplingFactor},1x1,1x1",
        );
    }

    protected function assertPngWithExifOrientation6Fixture(string $imageBytes): void
    {
        $this->assertPngFixture($imageBytes, 600, 400, 8, 2);
        $exifChunks = ImageStructureWalker::listPngChunksOfType($imageBytes, 'eXIf');
        self::assertCount(1, $exifChunks, self::PRECONDITION . 'the fixture carries one eXIf');
        self::assertSame(6, $this->readExifOrientationFromTiffBlock($exifChunks[0]['data']), self::PRECONDITION . 'the eXIf says orientation 6');
        self::assertSame(6, $this->readImageOrientation($imageBytes), self::PRECONDITION . 'Imagick reads the PNG\'s orientation as 6');
    }

    /** A 300x300 light grey image written by Imagick in the given format. */
    protected function buildFlatImageInFormat(string $format): string
    {
        $imagick = new Imagick();
        $imagick->newImage(300, 300, new ImagickPixel('#E0E0E0'), $format);
        $imagick->stripImage();
        $imageBytes = $imagick->getImageBlob();
        $imagick->clear();
        return $imageBytes;
    }

    protected function assertGifFixture(string $imageBytes): void
    {
        self::assertStringStartsWith('GIF8', $imageBytes, self::PRECONDITION . 'the fixture has the GIF signature');
        $this->assertDecodesAs($imageBytes, 'GIF', 300, 300, self::PRECONDITION . 'the fixture');
    }

    protected function assertWebpFixture(string $imageBytes): void
    {
        self::assertStringStartsWith('RIFF', $imageBytes, self::PRECONDITION . 'the fixture is a RIFF file');
        self::assertSame('WEBP', substr($imageBytes, 8, 4), self::PRECONDITION . 'the fixture is a WebP');
        $this->assertDecodesAs($imageBytes, 'WEBP', 300, 300, self::PRECONDITION . 'the fixture');
    }

    /**
     * SOI, a JFIF APP0, an SOS for one component with no SOF, DQT or DHT before it, two bytes of scan data, EOI:
     * ImageMagick cannot decode it (a JPEG cut in half, by contrast, decodes).
     */
    protected function buildJpegWithSosButNoSof(): string
    {
        return "\xFF\xD8"
            . ImageMarkFixtureBuilder::buildJpegSegment(ImageMarkFixtureBuilder::MARKER_APP0, "JFIF\0\x01\x01\x00\x00\x01\x00\x01\x00\x00")
            . ImageMarkFixtureBuilder::buildJpegSegment(0xDA, "\x01\x01\x00\x00\x3F\x00")
            . "\x00\x00"
            . "\xFF\xD9";
    }

    protected function assertJpegWithSosButNoSofFixture(string $imageBytes): void
    {
        self::assertSame(
            [ImageStructureWalker::JPEG_KIND_APP0, ImageStructureWalker::JPEG_KIND_START_OF_SCAN],
            ImageStructureWalker::listJpegSegmentKinds($imageBytes),
            self::PRECONDITION . 'the fixture is SOI, APP0, SOS',
        );
        $imagickException = null;
        $imagick = new Imagick();
        try {
            $imagick->readImageBlob($imageBytes);
        } catch (ImagickException $caughtImagickException) {
            $imagickException = $caughtImagickException;
        }
        $imagick->clear();
        self::assertInstanceOf(ImagickException::class, $imagickException, self::PRECONDITION . 'a bare readImageBlob() on the fixture throws');
    }

    protected function assertPngWithCorruptXmpFixture(string $imageBytes): void
    {
        $this->assertPngFixture($imageBytes, 600, 400, 8, 2);
        $xmpChunks = ImageStructureWalker::listPngXmpChunks($imageBytes);
        self::assertCount(1, $xmpChunks, self::PRECONDITION . 'the fixture carries one XMP chunk');
        self::assertSame('iTXt', $xmpChunks[0]['type'], self::PRECONDITION . 'the XMP chunk is an iTXt');
        self::assertTrue($xmpChunks[0]['hasValidCrc'], self::PRECONDITION . 'the XMP chunk CRC is valid');
        $inflationFailed = false;
        try {
            ImageStructureWalker::decodePngXmpPacket($xmpChunks[0]);
        } catch (UnexpectedValueException) {
            $inflationFailed = true;
        }
        self::assertTrue($inflationFailed, self::PRECONDITION . 'the compressed XMP does not inflate');
    }

    protected function assertPngFixture(string $imageBytes, int $width, int $height, int $bitDepth, int $colourType): void
    {
        $this->assertDecodesAs($imageBytes, 'PNG', $width, $height, self::PRECONDITION . 'the fixture');
        self::assertSame(
            ['bitDepth' => $bitDepth, 'colourType' => $colourType],
            ImageStructureWalker::getPngBitDepthAndColourType($imageBytes),
            self::PRECONDITION . "the fixture's IHDR declares bit depth {$bitDepth} and colour type {$colourType}",
        );
    }

    /**
     * The noisy pattern with an alpha channel set byte by byte: alpha 0 in the 40 leftmost columns, and from 1 to 254
     * elsewhere ((x + 2y) mod 254 + 1), so the fixture has fully transparent and partly transparent pixels away from
     * the icon.
     */
    protected function buildNoisyRgbaPng(int $width, int $height): string
    {
        $rgbaSamples = array_values(unpack('C*', $this->readRgbaPixelBytes($this->buildNoisyPng($width, $height))));
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgbaSamples[4 * ($y * $width + $x) + 3] = $x < 40 ? 0 : ($x + 2 * $y) % 254 + 1;
            }
        }
        $imagick = new Imagick();
        $imagick->newImage($width, $height, new ImagickPixel('rgba(0,0,0,0)'), 'png');
        $imagick->importImagePixels(0, 0, $width, $height, 'RGBA', Imagick::PIXEL_CHAR, $rgbaSamples);
        $imagick->setImageFormat('png32');
        $imagick->stripImage();
        $pngBytes = $imagick->getImageBlob();
        $imagick->clear();
        return $pngBytes;
    }

    protected function assertNoisyRgbaPng(string $imageBytes): void
    {
        $this->assertPngFixture($imageBytes, 600, 400, 8, 6);
        $rgbaPixelBytes = $this->readRgbaPixelBytes($imageBytes);
        $transparentPixelCount = 0;
        $partlyTransparentPixelCount = 0;
        for ($y = 0; $y < 400; $y++) {
            for ($x = 0; $x < 600; $x++) {
                if ($x >= 540 && $x < 580 && $y >= 20 && $y < 60) {
                    continue;
                }
                $alpha = ord($rgbaPixelBytes[4 * (600 * $y + $x) + 3]);
                $transparentPixelCount += $alpha === 0 ? 1 : 0;
                $partlyTransparentPixelCount += $alpha > 0 && $alpha < 255 ? 1 : 0;
            }
        }
        self::assertGreaterThanOrEqual(1000, $transparentPixelCount, self::PRECONDITION . 'at least 1,000 pixels outside the icon square have alpha 0');
        self::assertGreaterThanOrEqual(1000, $partlyTransparentPixelCount, self::PRECONDITION . 'at least 1,000 pixels outside the icon square have alpha strictly between 0 and 255');
    }

    /** A 16-bit RGB PNG of the gradient with seeded random noise. */
    protected function buildNoisySixteenBitPng(int $width, int $height): string
    {
        $this->seedImageMagickNoise();
        $imagick = new Imagick();
        $imagick->newPseudoImage($width, $height, 'gradient:red-blue');
        $imagick->setImageDepth(16);
        $imagick->addNoiseImage(Imagick::NOISE_RANDOM);
        $imagick->setImageFormat('png48');
        $imagick->stripImage();
        $pngBytes = $imagick->getImageBlob();
        $imagick->clear();
        return $pngBytes;
    }

    /** The noisy pattern written as a palette PNG. */
    protected function buildNoisyPalettePng(int $width, int $height): string
    {
        $imagick = $this->buildNoisyImagick($width, $height);
        $imagick->setImageFormat('png8');
        $imagick->stripImage();
        $pngBytes = $imagick->getImageBlob();
        $imagick->clear();
        return $pngBytes;
    }

    /**
     * A red-to-blue gradient (top red, so a top corner is dark) with seeded impulse noise: a disc of either colour
     * changes the pixels it covers.
     */
    protected function buildNoisyImagick(int $width, int $height): Imagick
    {
        $this->seedImageMagickNoise();
        $imagick = new Imagick();
        $imagick->newPseudoImage($width, $height, 'gradient:red-blue');
        $imagick->addNoiseImage(Imagick::NOISE_IMPULSE);
        return $imagick;
    }

    /** Seeds ImageMagick's noise, so every run builds the same pixels. */
    protected function seedImageMagickNoise(): void
    {
        // @phpstan-ignore staticMethod.notFound (in Imagick 3.7 of the production image; PHPStan's Imagick stub lacks it)
        Imagick::setSeed(self::NOISE_SEED);
    }

    /** The pattern of the noisy JPEGs: the red-to-blue gradient with seeded Gaussian noise at half strength. */
    protected function buildNoisyJpegImagick(int $width, int $height): Imagick
    {
        $this->seedImageMagickNoise();
        $imagick = new Imagick();
        $imagick->newPseudoImage($width, $height, 'gradient:red-blue');
        // @phpstan-ignore method.notFound (in Imagick 3.7 of the production image; PHPStan's Imagick stub lacks it)
        $imagick->addNoiseImageWithAttenuate(Imagick::NOISE_GAUSSIAN, 0.5);
        return $imagick;
    }

    /** An 8-bit RGB PNG of the noisy pattern, without metadata. */
    protected function buildNoisyPng(int $width, int $height): string
    {
        $imagick = $this->buildNoisyImagick($width, $height);
        $imagick->setImageFormat('png24');
        $imagick->stripImage();
        $pngBytes = $imagick->getImageBlob();
        $imagick->clear();
        return $pngBytes;
    }

    /**
     * A JPEG of the red-to-blue gradient with seeded Gaussian noise at half strength, at the given quality (and
     * sampling, e.g. `2x2`, when given), without metadata. Not the impulse noise of the PNGs: a plain decode and
     * re-encode of an impulse-noise JPEG moves channels by up to 65 (measured), so no tolerance could tell the stamp
     * from the re-encode; this pattern moves them by 5.
     */
    protected function buildNoisyJpeg(int $width, int $height, int $quality, ?string $lumaSamplingFactor = null): string
    {
        $imagick = $this->buildNoisyJpegImagick($width, $height);
        $imagick->setImageFormat('jpeg');
        $imagick->setImageCompressionQuality($quality);
        if ($lumaSamplingFactor !== null) {
            $imagick->setOption('jpeg:sampling-factor', "{$lumaSamplingFactor},1x1,1x1");
        }
        $imagick->stripImage();
        $jpegBytes = $imagick->getImageBlob();
        $imagick->clear();
        return $jpegBytes;
    }
}

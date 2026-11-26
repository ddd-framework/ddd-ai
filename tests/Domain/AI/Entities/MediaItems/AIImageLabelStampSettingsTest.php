<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Domain\AI\Entities\MediaItems;

use DDD\Domain\AI\Entities\MediaItems\AIImageLabelIconSquare;
use DDD\Domain\AI\Entities\MediaItems\AIImageLabelStampSettings;
use DDD\Domain\AI\Entities\MediaItems\AIImageLabelVariant;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use LogicException;

/**
 * The stamp settings built from a config array: the shipped config, the validation rows, the pure geometry and the
 * committed icon files. Every expected number is a literal from the accepted criteria, never recomputed with the
 * settings' formula.
 *
 * The "criterion N" labels number the cases of the specification the class was written against.
 */
class AIImageLabelStampSettingsTest extends TestCase
{
    /** @var string The committed icon folder, relative to the app folder */
    protected const string ICON_DIRECTORY_RELATIVE_TO_APP = 'config/app/AI/AIImageLabelIcons';

    /** @var string The shipped stamp config, relative to the app folder */
    protected const string SHIPPED_CONFIG_RELATIVE_TO_APP = 'config/app/AI/AIImageLabelStamp.php';

    /** @var array<string, string> The solid pair of icon files, by icon colour */
    protected const array SOLID_ICON_FILE_NAMES = [
        AIImageLabelStampSettings::ICON_COLOUR_BLACK => 'LABEL_AI_black.png',
        AIImageLabelStampSettings::ICON_COLOUR_WHITE => 'LABEL_AI_white.png',
    ];

    /** @var string Geometry row: the disc diameter for a shorter side */
    protected const string QUANTITY_DIAMETER = 'diameter';

    /** @var string Geometry row: the margin for a shorter side */
    protected const string QUANTITY_MARGIN = 'margin';

    /** @var string Geometry row: the displayed icon square for an image size */
    protected const string QUANTITY_SQUARE = 'square';

    public function testShippedConfig(): void
    {
        $iconDirectoryPath = $this->getCommittedIconDirectoryPath();

        $stampSettings = AIImageLabelStampSettings::fromConfigArray($this->readShippedConfigArray(), $iconDirectoryPath);

        self::assertSame(AIImageLabelStampSettings::CORNER_TOP_RIGHT, $stampSettings->corner, 'criterion 29: corner');
        self::assertSame('TOP_RIGHT', $stampSettings->corner, 'criterion 29: the corner value');
        self::assertSame(0.06, $stampSettings->iconDiameterShareOfShorterSide, 'criterion 29: size share');
        self::assertSame(40, $stampSettings->minimumIconDiameterInPixels, 'criterion 29: minimum');
        self::assertSame(0.015, $stampSettings->marginShareOfShorterSide, 'criterion 29: margin share');
        self::assertSame(AIImageLabelStampSettings::COLOUR_AUTOMATIC, $stampSettings->colourChoice, 'criterion 29: colour');
        self::assertNull($stampSettings->getPinnedIconColour(), 'criterion 29: no pinned colour');
        foreach ([AIImageLabelVariant::GENERATED, AIImageLabelVariant::EDITED] as $aiImageLabelVariant) {
            self::assertSame(
                [
                    AIImageLabelStampSettings::ICON_COLOUR_BLACK => $iconDirectoryPath . '/LABEL_AI_black_transparent.png',
                    AIImageLabelStampSettings::ICON_COLOUR_WHITE => $iconDirectoryPath . '/LABEL_AI_white_transparent.png',
                ],
                [
                    AIImageLabelStampSettings::ICON_COLOUR_BLACK => $stampSettings->getIconFilePath($aiImageLabelVariant, AIImageLabelStampSettings::ICON_COLOUR_BLACK),
                    AIImageLabelStampSettings::ICON_COLOUR_WHITE => $stampSettings->getIconFilePath($aiImageLabelVariant, AIImageLabelStampSettings::ICON_COLOUR_WHITE),
                ],
                "criterion 29: the transparent pair for {$aiImageLabelVariant}",
            );
        }
    }

    /**
     * criterion 2: the shipped config gives design version 1.
     */
    public function testShippedConfigGivesDesignVersionOne(): void
    {
        $stampSettings = AIImageLabelStampSettings::fromConfigArray(
            $this->readShippedConfigArray(),
            $this->getCommittedIconDirectoryPath(),
        );

        self::assertSame(1, $stampSettings->designVersion, 'criterion 2: the shipped design version');
    }

    /**
     * criterion 1: a design version that is not an integer of at least 1 is rejected.
     *
     * @param array<string, mixed> $stampConfig
     * @dataProvider provideInvalidDesignVersionConfigs
     */
    public function testInvalidDesignVersionThrows(array $stampConfig): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/designVersion/');

        AIImageLabelStampSettings::fromConfigArray($stampConfig, $this->getCommittedIconDirectoryPath());
    }

    /**
     * criterion 1: the integer 1 is accepted as given.
     */
    public function testDesignVersionOneIsAccepted(): void
    {
        $stampSettings = AIImageLabelStampSettings::fromConfigArray(
            array_replace(self::buildTestConfigArray(), ['designVersion' => 1]),
            $this->getCommittedIconDirectoryPath(),
        );

        self::assertSame(1, $stampSettings->designVersion, 'criterion 1: the design version');
    }

    /**
     * @param string $quantity One of the QUANTITY_* values
     * @param int $shorterSideOrWidth The shorter side (diameter, margin) or the displayed width (square)
     * @param int|array<string, int> $expected
     * @dataProvider provideGeometryRows
     */
    public function testGeometryIsExact(string $quantity, int $shorterSideOrWidth, ?int $displayedHeight, int|array $expected): void
    {
        $stampSettings = AIImageLabelStampSettings::fromConfigArray(self::buildTestConfigArray(), $this->getCommittedIconDirectoryPath());

        $settingsGeometryValue = match ($quantity) {
            self::QUANTITY_DIAMETER => $stampSettings->getIconDiameterForShorterSide($shorterSideOrWidth),
            self::QUANTITY_MARGIN => $stampSettings->getMarginForShorterSide($shorterSideOrWidth),
            self::QUANTITY_SQUARE => $this->buildIconSquareEdges(
                $stampSettings->buildIconSquareForDisplayedImageSize($shorterSideOrWidth, (int)$displayedHeight),
            ),
            default => throw new LogicException("Unknown geometry quantity {$quantity}"),
        };

        self::assertSame($expected, $settingsGeometryValue, "criterion 39: {$quantity}");
    }

    /**
     * @param array<string, mixed> $stampConfig
     * @param string $messageFragment What the message must name, so each row fails on its own check
     * @dataProvider provideInvalidConfigs
     */
    public function testInvalidConfigThrows(array $stampConfig, string $messageFragment): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($messageFragment, '/') . '/');

        AIImageLabelStampSettings::fromConfigArray($stampConfig, $this->getCommittedIconDirectoryPath());
    }

    /**
     * @param array<string, mixed> $stampConfig
     * @param array{float, int, float} $expectedDiameterShareMinimumAndMarginShare
     * @dataProvider provideBoundaryConfigs
     */
    public function testBoundaryConfigIsAccepted(array $stampConfig, array $expectedDiameterShareMinimumAndMarginShare): void
    {
        $stampSettings = AIImageLabelStampSettings::fromConfigArray($stampConfig, $this->getCommittedIconDirectoryPath());

        self::assertSame(
            $expectedDiameterShareMinimumAndMarginShare,
            [$stampSettings->iconDiameterShareOfShorterSide, $stampSettings->minimumIconDiameterInPixels, $stampSettings->marginShareOfShorterSide],
            'criterion 31: the boundary value is accepted as given',
        );
    }

    public function testCommittedIconsAreTheCommissionFiles(): void
    {
        $iconDirectoryPath = $this->getCommittedIconDirectoryPath();
        $shippedStampSettings = AIImageLabelStampSettings::fromConfigArray($this->readShippedConfigArray(), $iconDirectoryPath);

        // hashed with `shasum -a 256` on the files as received from the Commission, 2026-11-13
        $expectedSha256ByIconFilePath = [
            $iconDirectoryPath . '/LABEL_AI_black.png' => '0814d5f04533c118f81aed0d353ea75d1df4171c40b962dba1dfa044a3d84779',
            $shippedStampSettings->getIconFilePath(AIImageLabelVariant::GENERATED, AIImageLabelStampSettings::ICON_COLOUR_BLACK) => '24da93847c04a5c86c714c6cd018c45331b47df32936b617b3c8476933d97fa9',
            $iconDirectoryPath . '/LABEL_AI_white.png' => 'b9041e416fed455722a3aaa06a5ea0eb376d8a8e11385fdc8e3e8ee6998cdf26',
            $shippedStampSettings->getIconFilePath(AIImageLabelVariant::GENERATED, AIImageLabelStampSettings::ICON_COLOUR_WHITE) => '5b8c743b679a62453a4bc25070b82bb4db33ad9c68f4ef1b6ef7bb562dd3a4e3',
        ];

        self::assertSame(
            [
                $iconDirectoryPath . '/LABEL_AI_black.png',
                $iconDirectoryPath . '/LABEL_AI_black_transparent.png',
                $iconDirectoryPath . '/LABEL_AI_white.png',
                $iconDirectoryPath . '/LABEL_AI_white_transparent.png',
            ],
            array_keys($expectedSha256ByIconFilePath),
            'criterion 32: the shipped settings resolve the transparent pair to LABEL_AI_black_transparent.png and LABEL_AI_white_transparent.png',
        );
        foreach ($expectedSha256ByIconFilePath as $iconFilePath => $expectedSha256) {
            self::assertFileExists($iconFilePath, 'criterion 32: the icon file exists');
            self::assertSame($expectedSha256, hash_file('sha256', $iconFilePath), "criterion 32: {$iconFilePath} is the Commission's file");
        }
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Data providers
    // ---------------------------------------------------------------------------------------------------------------

    /** @return iterable<string, array{array<string, mixed>, array{float, int, float}}> criterion 31 */
    public static function provideBoundaryConfigs(): iterable
    {
        $testConfig = self::buildTestConfigArray();
        yield 'size share 0.99 with margin share 0' => [
            array_replace($testConfig, [
                AIImageLabelStampSettings::CONFIG_KEY_ICON_DIAMETER_SHARE => 0.99,
                AIImageLabelStampSettings::CONFIG_KEY_MARGIN_SHARE => 0,
            ]),
            [0.99, 24, 0.0],
        ];
        yield 'minimum 1' => [
            array_replace($testConfig, [AIImageLabelStampSettings::CONFIG_KEY_MINIMUM_ICON_DIAMETER => 1]),
            [0.10, 1, 0.05],
        ];
        yield 'margin share 0.49 with size share 0.5' => [
            array_replace($testConfig, [
                AIImageLabelStampSettings::CONFIG_KEY_ICON_DIAMETER_SHARE => 0.5,
                AIImageLabelStampSettings::CONFIG_KEY_MARGIN_SHARE => 0.49,
            ]),
            [0.5, 24, 0.49],
        ];
    }

    /** @return iterable<string, array{array<string, mixed>}> criterion 1 */
    public static function provideInvalidDesignVersionConfigs(): iterable
    {
        $configWithoutDesignVersion = self::buildTestConfigArray();
        unset($configWithoutDesignVersion['designVersion']);
        yield 'design version missing' => [$configWithoutDesignVersion];
        yield 'design version 0' => [array_replace(self::buildTestConfigArray(), ['designVersion' => 0])];
        yield 'design version -1' => [array_replace(self::buildTestConfigArray(), ['designVersion' => -1])];
        yield "design version '1' (a string)" => [array_replace(self::buildTestConfigArray(), ['designVersion' => '1'])];
        yield 'design version 1.0 (a float)' => [array_replace(self::buildTestConfigArray(), ['designVersion' => 1.0])];
    }

    /** @return iterable<string, array{array<string, mixed>, string}> criterion 30 */
    public static function provideInvalidConfigs(): iterable
    {
        $testConfig = self::buildTestConfigArray();
        yield 'unknown corner' => [
            array_replace($testConfig, [AIImageLabelStampSettings::CONFIG_KEY_CORNER => 'CENTRE']),
            AIImageLabelStampSettings::CONFIG_KEY_CORNER,
        ];
        yield 'unknown colour choice' => [
            array_replace($testConfig, [AIImageLabelStampSettings::CONFIG_KEY_COLOUR => 'GREEN']),
            AIImageLabelStampSettings::CONFIG_KEY_COLOUR,
        ];
        yield 'size share 0' => [
            array_replace($testConfig, [AIImageLabelStampSettings::CONFIG_KEY_ICON_DIAMETER_SHARE => 0]),
            AIImageLabelStampSettings::CONFIG_KEY_ICON_DIAMETER_SHARE,
        ];
        yield 'size share 1' => [
            array_replace($testConfig, [AIImageLabelStampSettings::CONFIG_KEY_ICON_DIAMETER_SHARE => 1]),
            AIImageLabelStampSettings::CONFIG_KEY_ICON_DIAMETER_SHARE,
        ];
        yield 'minimum 0' => [
            array_replace($testConfig, [AIImageLabelStampSettings::CONFIG_KEY_MINIMUM_ICON_DIAMETER => 0]),
            AIImageLabelStampSettings::CONFIG_KEY_MINIMUM_ICON_DIAMETER,
        ];
        yield 'margin share -0.01' => [
            array_replace($testConfig, [AIImageLabelStampSettings::CONFIG_KEY_MARGIN_SHARE => -0.01]),
            AIImageLabelStampSettings::CONFIG_KEY_MARGIN_SHARE,
        ];
        yield 'margin share 0.5' => [
            array_replace($testConfig, [AIImageLabelStampSettings::CONFIG_KEY_MARGIN_SHARE => 0.5]),
            AIImageLabelStampSettings::CONFIG_KEY_MARGIN_SHARE,
        ];
        yield 'size share 0.6 with margin share 0.4' => [
            array_replace($testConfig, [
                AIImageLabelStampSettings::CONFIG_KEY_ICON_DIAMETER_SHARE => 0.6,
                AIImageLabelStampSettings::CONFIG_KEY_MARGIN_SHARE => 0.4,
            ]),
            AIImageLabelStampSettings::CONFIG_KEY_ICON_DIAMETER_SHARE . ' + ' . AIImageLabelStampSettings::CONFIG_KEY_MARGIN_SHARE,
        ];
        $configWithoutTheWhiteIcon = $testConfig;
        unset($configWithoutTheWhiteIcon[AIImageLabelStampSettings::CONFIG_KEY_ICON_FILE_NAMES_BY_VARIANT][AIImageLabelVariant::GENERATED][AIImageLabelStampSettings::ICON_COLOUR_WHITE]);
        yield 'a variant without its white icon' => [$configWithoutTheWhiteIcon, AIImageLabelStampSettings::ICON_COLOUR_WHITE];
        $configWithoutEdited = $testConfig;
        unset($configWithoutEdited[AIImageLabelStampSettings::CONFIG_KEY_ICON_FILE_NAMES_BY_VARIANT][AIImageLabelVariant::EDITED]);
        yield 'a config without EDITED' => [$configWithoutEdited, AIImageLabelVariant::EDITED];
        $configWithAMissingIconFile = $testConfig;
        $configWithAMissingIconFile[AIImageLabelStampSettings::CONFIG_KEY_ICON_FILE_NAMES_BY_VARIANT][AIImageLabelVariant::GENERATED][AIImageLabelStampSettings::ICON_COLOUR_BLACK] = 'LABEL_AI_missing.png';
        yield 'an icon file that does not exist' => [$configWithAMissingIconFile, 'LABEL_AI_missing.png'];
    }

    /** @return iterable<string, array{string, int, int|null, int|array<string, int>}> */
    public static function provideGeometryRows(): iterable
    {
        yield 'margin for 150 at 0.05 is 8' => [self::QUANTITY_MARGIN, 150, null, 8];
        yield 'diameter for 415 at 0.10 is 42 (41.5 rounds half away from zero)' => [self::QUANTITY_DIAMETER, 415, null, 42];
        yield 'diameter for 150 at 0.10 is the minimum 24' => [self::QUANTITY_DIAMETER, 150, null, 24];
        yield 'displayed TOP_RIGHT square for 600x400 is [540,580)x[20,60)' => [
            self::QUANTITY_SQUARE,
            600,
            400,
            ['left' => 540, 'top' => 20, 'right' => 580, 'bottom' => 60],
        ];
        yield 'diameter for 413 at 0.10 is 41 (41.300000000000004; ceil gives 42)' => [self::QUANTITY_DIAMETER, 413, null, 41];
        yield 'diameter for 425 at 0.10 is 43 (42.5; half-even gives 42)' => [self::QUANTITY_DIAMETER, 425, null, 43];
        yield 'margin for 130 at 0.05 is 7 (6.5; half-even gives 6)' => [self::QUANTITY_MARGIN, 130, null, 7];
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Configs and paths
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Test settings T of the accepted criteria: TOP_RIGHT, size share 0.10, minimum 24 px, margin share 0.05, colour automatic,
     * both variants on the solid pair.
     *
     * @return array<string, mixed>
     */
    protected static function buildTestConfigArray(): array
    {
        return [
            AIImageLabelStampSettings::CONFIG_KEY_CORNER => AIImageLabelStampSettings::CORNER_TOP_RIGHT,
            AIImageLabelStampSettings::CONFIG_KEY_ICON_DIAMETER_SHARE => 0.10,
            AIImageLabelStampSettings::CONFIG_KEY_MINIMUM_ICON_DIAMETER => 24,
            AIImageLabelStampSettings::CONFIG_KEY_MARGIN_SHARE => 0.05,
            AIImageLabelStampSettings::CONFIG_KEY_COLOUR => AIImageLabelStampSettings::COLOUR_AUTOMATIC,
            AIImageLabelStampSettings::CONFIG_KEY_ICON_FILE_NAMES_BY_VARIANT => [
                AIImageLabelVariant::GENERATED => self::SOLID_ICON_FILE_NAMES,
                AIImageLabelVariant::EDITED => self::SOLID_ICON_FILE_NAMES,
            ],
            'designVersion' => 1,
        ];
    }

    /**
     * The four edges of a square, for comparing it with a literal of the accepted criteria.
     *
     * @return array{left: int, top: int, right: int, bottom: int}
     */
    protected function buildIconSquareEdges(AIImageLabelIconSquare $iconSquare): array
    {
        return ['left' => $iconSquare->left, 'top' => $iconSquare->top, 'right' => $iconSquare->getRight(), 'bottom' => $iconSquare->getBottom()];
    }

    /** The committed icon folder's real path. */
    protected function getCommittedIconDirectoryPath(): string
    {
        $iconDirectoryPath = realpath($this->getAppDirectoryPath() . '/' . self::ICON_DIRECTORY_RELATIVE_TO_APP);
        self::assertIsString($iconDirectoryPath, 'PRECONDITION: the committed icon folder exists');
        return $iconDirectoryPath;
    }

    /**
     * The shipped config array, read from its file (never through Config::get(), whose first read of a key resets
     * the cached Common subtree for every later test of the process).
     *
     * @return array<string, mixed>
     */
    protected function readShippedConfigArray(): array
    {
        $shippedConfigArray = require $this->getAppDirectoryPath() . '/' . self::SHIPPED_CONFIG_RELATIVE_TO_APP;
        self::assertIsArray($shippedConfigArray, 'PRECONDITION: the shipped config returns an array');
        return $shippedConfigArray;
    }

    /** The app folder: this file sits in app/Tests/Domain/Common/Entities/MediaItems. */
    protected function getAppDirectoryPath(): string
    {
        return dirname(__DIR__, 5);
    }
}

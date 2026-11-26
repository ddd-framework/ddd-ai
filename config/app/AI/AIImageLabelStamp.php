<?php

declare(strict_types=1);

use DDD\Domain\AI\Entities\MediaItems\AIImageLabelStampSettings;
use DDD\Domain\AI\Entities\MediaItems\AIImageLabelVariant;

/**
 * How AIImageLabelStamper stamps the EU "AI" icon on an image; read whole as `AI.AIImageLabelStamp` and validated
 * by {@see AIImageLabelStampSettings::fromConfigArray()}.
 *
 * - corner: one of the AIImageLabelStampSettings::CORNER_* values, on the image as it displays.
 * - iconDiameterShareOfShorterSide: the disc diameter as a share of the displayed image's shorter side, above 0 and
 *   below 1; minimumIconDiameterInPixels is the smallest diameter (at least 1).
 * - marginShareOfShorterSide: the gap between the disc and the two image edges of the corner, as a share of the shorter
 *   side, from 0 to below 0.5; diameter share + margin share must be below 1.
 * - colour: AUTOMATIC (the black icon on a light corner, the white icon on a dark one), BLACK or WHITE.
 * - iconFileNamesByVariant: for every label variant, the BLACK and WHITE icon file in AIImageLabelIcons/ (the
 *   Commission's files; the *_transparent pair has a 50 % disc).
 * - designVersion: the version of this design, an integer of at least 1. Raise it whenever the design changes (any
 *   value above): a labelled copy is stored under, and linked with, the version it was stamped with, so a link already
 *   sent keeps serving the copy it named.
 *
 * These numbers and the transparent pair were chosen from a sample sheet: on post
 * photos of 720 to 2,000 px per side they give a disc of 43 to 120 px and a margin of 11 to 30 px.
 */
return [
    'corner' => AIImageLabelStampSettings::CORNER_TOP_RIGHT,
    'iconDiameterShareOfShorterSide' => 0.06,
    'minimumIconDiameterInPixels' => 40,
    'marginShareOfShorterSide' => 0.015,
    'colour' => AIImageLabelStampSettings::COLOUR_AUTOMATIC,
    'iconFileNamesByVariant' => [
        AIImageLabelVariant::GENERATED => [
            AIImageLabelStampSettings::ICON_COLOUR_BLACK => 'LABEL_AI_black_transparent.png',
            AIImageLabelStampSettings::ICON_COLOUR_WHITE => 'LABEL_AI_white_transparent.png',
        ],
        AIImageLabelVariant::EDITED => [
            AIImageLabelStampSettings::ICON_COLOUR_BLACK => 'LABEL_AI_black_transparent.png',
            AIImageLabelStampSettings::ICON_COLOUR_WHITE => 'LABEL_AI_white_transparent.png',
        ],
    ],
    'designVersion' => 1,
];

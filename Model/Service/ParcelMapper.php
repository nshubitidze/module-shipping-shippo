<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Model\Service;

use Shubo\ShippingCore\Api\Data\Dto\ParcelSpec;

/**
 * Maps a normalized {@see ParcelSpec} to the Shippo parcel payload.
 *
 * ShippingCore stores dimensions in millimetres and weight in grams (design
 * §16). Shippo accepts metric, but the default integration lane is
 * inches + pounds and that's what Shippo's service-level defaults quote
 * fastest; we convert at the boundary via bcmath to avoid float drift.
 *
 * Conversions:
 *   mm → in: divide by 25.4 (exact), 2-decimal precision
 *   g  → lb: divide by 453.592 (USPS-legal avoirdupois), 3-decimal precision
 *
 * `bcdiv` truncates — that's fine: a parcel slightly lighter on-wire is
 * never a rating surprise (Shippo catches gross under-declaration at the
 * label purchase step anyway).
 *
 * Zero-weight parcels would be rejected by Shippo, so we floor the weight
 * at 0.001 lb (~0.45 g) to keep the caller's dry-run smoke calls working.
 */
class ParcelMapper
{
    private const MM_PER_INCH = '25.4';
    private const G_PER_LB = '453.592';
    private const DIMENSION_SCALE = 2;
    private const WEIGHT_SCALE = 3;
    private const WEIGHT_FLOOR = '0.001';

    /**
     * @return array{
     *     length: string,
     *     width: string,
     *     height: string,
     *     distance_unit: string,
     *     weight: string,
     *     mass_unit: string
     * }
     */
    public function toShippoPayload(ParcelSpec $parcel): array
    {
        $weight = bcdiv((string)$parcel->weightGrams, self::G_PER_LB, self::WEIGHT_SCALE);
        if (bccomp($weight, self::WEIGHT_FLOOR, self::WEIGHT_SCALE) < 0) {
            $weight = self::WEIGHT_FLOOR;
        }

        return [
            'length' => bcdiv((string)$parcel->lengthMm, self::MM_PER_INCH, self::DIMENSION_SCALE),
            'width' => bcdiv((string)$parcel->widthMm, self::MM_PER_INCH, self::DIMENSION_SCALE),
            'height' => bcdiv((string)$parcel->heightMm, self::MM_PER_INCH, self::DIMENSION_SCALE),
            'distance_unit' => 'in',
            'weight' => $weight,
            'mass_unit' => 'lb',
        ];
    }
}

<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Model\Service;

use Psr\Log\LoggerInterface;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;

/**
 * Maps Shippo tracking statuses to ShippingCore normalized statuses.
 *
 * Shippo → Core table (see design doc §13):
 *   UNKNOWN      → pending
 *   PRE_TRANSIT  → awaiting_pickup (core: ready_for_pickup)
 *   TRANSIT      → in_transit
 *   DELIVERED    → delivered
 *   RETURNED     → returned (core: returned_to_sender)
 *   FAILURE      → failed
 *
 * Unknown status strings fall through to `pending` with a WARN log so
 * operators notice new Shippo status values landing in production.
 *
 * The design doc targets the string literals `awaiting_pickup` and
 * `returned` — we keep those verbatim because they are the contract
 * exposed over the webhook and gateway boundary; any later alignment
 * with {@see ShipmentInterface} constants happens inside core, not here.
 */
class StatusMapper
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_AWAITING_PICKUP = 'awaiting_pickup';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_FAILED = 'failed';

    /** @var array<string, string> */
    private const MAP = [
        'UNKNOWN' => self::STATUS_PENDING,
        'PRE_TRANSIT' => self::STATUS_AWAITING_PICKUP,
        'TRANSIT' => self::STATUS_IN_TRANSIT,
        'DELIVERED' => self::STATUS_DELIVERED,
        'RETURNED' => self::STATUS_RETURNED,
        'FAILURE' => self::STATUS_FAILED,
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function map(string $shippoStatus): string
    {
        if (array_key_exists($shippoStatus, self::MAP)) {
            return self::MAP[$shippoStatus];
        }

        $this->logger->warning(
            'Unknown Shippo tracking status; falling back to pending',
            ['shippo_status' => $shippoStatus],
        );
        return self::STATUS_PENDING;
    }
}

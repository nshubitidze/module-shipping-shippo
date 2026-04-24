<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Api;

use Magento\Framework\Exception\NoSuchEntityException;
use Shubo\ShippingShippo\Api\Data\ShippoTransactionInterface;

/**
 * Repository contract for the Shippo transaction idempotency table.
 *
 * @api
 */
interface ShippoTransactionRepositoryInterface
{
    public function save(ShippoTransactionInterface $transaction): ShippoTransactionInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getByClientTrackingCode(string $clientTrackingCode): ShippoTransactionInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getByTrackingNumber(string $trackingNumber): ShippoTransactionInterface;
}

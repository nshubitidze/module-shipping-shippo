<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Test\Integration\Helper;

use Magento\Framework\Exception\NoSuchEntityException;
use Shubo\ShippingShippo\Api\Data\ShippoTransactionInterface;
use Shubo\ShippingShippo\Api\ShippoTransactionRepositoryInterface;

/**
 * In-memory ShippoTransaction repository for the integration tests.
 *
 * The lifecycle test exercises the gateway's HTTP path against the live
 * Shippo sandbox; the local idempotency table is incidental (it just lets
 * the gateway's status / cancel / fetchLabel methods find the row by
 * tracking number after createShipment runs). Mocking the DB keeps the
 * test fast and DB-independent without sacrificing Shippo coverage.
 */
class InMemoryShippoTransactionRepository implements ShippoTransactionRepositoryInterface
{
    /** @var array<string, ShippoTransactionInterface> Keyed by client_tracking_code */
    private array $byCode = [];

    /** @var array<string, ShippoTransactionInterface> Keyed by tracking_number */
    private array $byTracking = [];

    public function save(ShippoTransactionInterface $transaction): ShippoTransactionInterface
    {
        $this->byCode[$transaction->getClientTrackingCode()] = $transaction;
        $this->byTracking[$transaction->getTrackingNumber()] = $transaction;
        return $transaction;
    }

    public function getByClientTrackingCode(string $clientTrackingCode): ShippoTransactionInterface
    {
        if (!isset($this->byCode[$clientTrackingCode])) {
            throw new NoSuchEntityException(
                __('No Shippo transaction with client_tracking_code "%1".', $clientTrackingCode),
            );
        }
        return $this->byCode[$clientTrackingCode];
    }

    public function getByTrackingNumber(string $trackingNumber): ShippoTransactionInterface
    {
        if (!isset($this->byTracking[$trackingNumber])) {
            throw new NoSuchEntityException(
                __('No Shippo transaction with tracking_number "%1".', $trackingNumber),
            );
        }
        return $this->byTracking[$trackingNumber];
    }
}

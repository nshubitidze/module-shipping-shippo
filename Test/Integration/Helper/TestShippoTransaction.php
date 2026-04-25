<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Test\Integration\Helper;

use Shubo\ShippingShippo\Api\Data\ShippoTransactionInterface;

/**
 * Plain in-memory ShippoTransaction model for the integration test factory.
 *
 * Avoids pulling in {@see \Magento\Framework\Model\AbstractModel} which needs
 * the full DI graph (event manager, registry, etc.). The integration test
 * does not exercise persistence; it just needs a settable + readable model
 * the gateway can stash and retrieve via the in-memory repository.
 */
class TestShippoTransaction implements ShippoTransactionInterface
{
    private string $clientTrackingCode = '';
    private string $shippoTransactionId = '';
    private string $trackingNumber = '';
    private string $carrier = '';
    private ?string $labelUrl = null;
    private string $status = '';
    private ?string $createdAt = null;

    public function getClientTrackingCode(): string
    {
        return $this->clientTrackingCode;
    }

    public function setClientTrackingCode(string $code): ShippoTransactionInterface
    {
        $this->clientTrackingCode = $code;
        return $this;
    }

    public function getShippoTransactionId(): string
    {
        return $this->shippoTransactionId;
    }

    public function setShippoTransactionId(string $id): ShippoTransactionInterface
    {
        $this->shippoTransactionId = $id;
        return $this;
    }

    public function getTrackingNumber(): string
    {
        return $this->trackingNumber;
    }

    public function setTrackingNumber(string $number): ShippoTransactionInterface
    {
        $this->trackingNumber = $number;
        return $this;
    }

    public function getCarrier(): string
    {
        return $this->carrier;
    }

    public function setCarrier(string $carrier): ShippoTransactionInterface
    {
        $this->carrier = $carrier;
        return $this;
    }

    public function getLabelUrl(): ?string
    {
        return $this->labelUrl;
    }

    public function setLabelUrl(?string $url): ShippoTransactionInterface
    {
        $this->labelUrl = $url;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): ShippoTransactionInterface
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }
}

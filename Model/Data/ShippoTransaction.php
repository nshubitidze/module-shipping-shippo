<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Model\Data;

use Magento\Framework\Model\AbstractModel;
use Shubo\ShippingShippo\Api\Data\ShippoTransactionInterface;
use Shubo\ShippingShippo\Model\ResourceModel\ShippoTransaction as ResourceModel;

/**
 * Concrete model for one Shippo transaction row.
 *
 * Uses a string primary key (`client_tracking_code`); Magento's
 * AbstractModel tolerates non-integer PKs as long as `_idFieldName` on
 * the resource model matches what we return from the getter below.
 */
class ShippoTransaction extends AbstractModel implements ShippoTransactionInterface
{
    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init(ResourceModel::class);
    }

    public function getClientTrackingCode(): string
    {
        return (string)$this->getData(self::FIELD_CLIENT_TRACKING_CODE);
    }

    public function setClientTrackingCode(string $code): ShippoTransactionInterface
    {
        $this->setData(self::FIELD_CLIENT_TRACKING_CODE, $code);
        return $this;
    }

    public function getShippoTransactionId(): string
    {
        return (string)$this->getData(self::FIELD_SHIPPO_TRANSACTION_ID);
    }

    public function setShippoTransactionId(string $id): ShippoTransactionInterface
    {
        $this->setData(self::FIELD_SHIPPO_TRANSACTION_ID, $id);
        return $this;
    }

    public function getTrackingNumber(): string
    {
        return (string)$this->getData(self::FIELD_TRACKING_NUMBER);
    }

    public function setTrackingNumber(string $number): ShippoTransactionInterface
    {
        $this->setData(self::FIELD_TRACKING_NUMBER, $number);
        return $this;
    }

    public function getCarrier(): string
    {
        return (string)$this->getData(self::FIELD_CARRIER);
    }

    public function setCarrier(string $carrier): ShippoTransactionInterface
    {
        $this->setData(self::FIELD_CARRIER, $carrier);
        return $this;
    }

    public function getLabelUrl(): ?string
    {
        $value = $this->getData(self::FIELD_LABEL_URL);
        return $value === null ? null : (string)$value;
    }

    public function setLabelUrl(?string $url): ShippoTransactionInterface
    {
        $this->setData(self::FIELD_LABEL_URL, $url);
        return $this;
    }

    public function getStatus(): string
    {
        return (string)$this->getData(self::FIELD_STATUS);
    }

    public function setStatus(string $status): ShippoTransactionInterface
    {
        $this->setData(self::FIELD_STATUS, $status);
        return $this;
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData(self::FIELD_CREATED_AT);
        return $value === null ? null : (string)$value;
    }
}

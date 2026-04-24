<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Shubo\ShippingShippo\Api\Data\ShippoTransactionInterface;

/**
 * Resource model for the Shippo transaction table.
 *
 * Primary key is the client_tracking_code (varchar), not an auto-increment
 * integer — matches the idempotency guarantee defined in design §15.
 */
class ShippoTransaction extends AbstractDb
{
    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init(
            ShippoTransactionInterface::TABLE,
            ShippoTransactionInterface::FIELD_CLIENT_TRACKING_CODE,
        );
        $this->_isPkAutoIncrement = false;
    }
}

<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Model\ResourceModel\ShippoTransaction;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Shubo\ShippingShippo\Model\Data\ShippoTransaction as Model;
use Shubo\ShippingShippo\Model\ResourceModel\ShippoTransaction as ResourceModel;

/**
 * Collection for the Shippo transaction table.
 */
class Collection extends AbstractCollection
{
    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init(Model::class, ResourceModel::class);
    }
}

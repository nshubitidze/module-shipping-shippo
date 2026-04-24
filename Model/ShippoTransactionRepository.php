<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Model;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Shubo\ShippingShippo\Api\Data\ShippoTransactionInterface;
use Shubo\ShippingShippo\Api\Data\ShippoTransactionInterfaceFactory;
use Shubo\ShippingShippo\Api\ShippoTransactionRepositoryInterface;
use Shubo\ShippingShippo\Model\Data\ShippoTransaction as Model;
use Shubo\ShippingShippo\Model\ResourceModel\ShippoTransaction as ResourceModel;
use Shubo\ShippingShippo\Model\ResourceModel\ShippoTransaction\CollectionFactory;

/**
 * Default repository for Shippo transaction rows.
 *
 * Thin by design: the only reads we perform are indexed lookups on
 * `client_tracking_code` (PK) and `tracking_number` (unique index).
 * No SearchCriteria-driven queries — the surface is narrow and opinionated,
 * which lets the adapter remain predictable in the hot path.
 */
class ShippoTransactionRepository implements ShippoTransactionRepositoryInterface
{
    public function __construct(
        private readonly ResourceModel $resource,
        private readonly ShippoTransactionInterfaceFactory $modelFactory,
        private readonly CollectionFactory $collectionFactory,
    ) {
    }

    public function save(ShippoTransactionInterface $transaction): ShippoTransactionInterface
    {
        if (!$transaction instanceof Model) {
            throw new CouldNotSaveException(
                __('Unsupported ShippoTransaction implementation passed to save().'),
            );
        }
        try {
            $this->resource->save($transaction);
        } catch (\Throwable $e) {
            throw new CouldNotSaveException(
                __('Unable to persist Shippo transaction: %1', $e->getMessage()),
                $e instanceof \Exception ? $e : null,
            );
        }
        return $transaction;
    }

    public function getByClientTrackingCode(string $clientTrackingCode): ShippoTransactionInterface
    {
        /** @var Model $model */
        $model = $this->modelFactory->create();
        $this->resource->load($model, $clientTrackingCode, ShippoTransactionInterface::FIELD_CLIENT_TRACKING_CODE);
        if ((string)$model->getClientTrackingCode() === '') {
            throw NoSuchEntityException::singleField(
                ShippoTransactionInterface::FIELD_CLIENT_TRACKING_CODE,
                $clientTrackingCode,
            );
        }
        return $model;
    }

    public function getByTrackingNumber(string $trackingNumber): ShippoTransactionInterface
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(ShippoTransactionInterface::FIELD_TRACKING_NUMBER, $trackingNumber);
        $collection->setPageSize(1);
        $first = $collection->getFirstItem();
        if (!$first instanceof Model || (string)$first->getClientTrackingCode() === '') {
            throw NoSuchEntityException::singleField(
                ShippoTransactionInterface::FIELD_TRACKING_NUMBER,
                $trackingNumber,
            );
        }
        return $first;
    }
}

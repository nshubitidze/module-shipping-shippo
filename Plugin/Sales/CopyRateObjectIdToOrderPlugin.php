<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Plugin\Sales;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * After order placement, extracts the Shippo rate_object_id from the
 * quote_shipping_rate row and persists it to sales_order.shippo_rate_object_id
 * so the admin Ship action can purchase the exact label the customer saw.
 *
 * The rate_object_id is encoded as JSON in quote_shipping_rate.method_description
 * by ShippoMagentoCarrier::buildRateMethod() — the only text column in that table
 * that survives the DB round-trip via Rate::importShippingRate().
 */
class CopyRateObjectIdToOrderPlugin
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param QuoteManagement $subject
     * @param OrderInterface $result
     * @param Quote $quote
     * @return OrderInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterSubmit(
        QuoteManagement $subject,
        OrderInterface $result,
        Quote $quote,
    ): OrderInterface {
        if (!$result instanceof Order) {
            return $result;
        }

        $shippingMethod = is_string($result->getData('shipping_method'))
            ? (string)$result->getData('shipping_method')
            : '';
        if (!str_starts_with($shippingMethod, 'shippo_')) {
            return $result;
        }

        try {
            $rateObjectId = $this->extractRateObjectId($quote, $shippingMethod);
            if ($rateObjectId === null) {
                $this->logger->warning(
                    'CopyRateObjectIdToOrderPlugin: no rate_object_id found for Shippo order',
                    ['order_increment_id' => $result->getIncrementId(), 'shipping_method' => $shippingMethod],
                );
                return $result;
            }

            $result->setData('shippo_rate_object_id', $rateObjectId);
            $this->orderRepository->save($result);
        } catch (\Throwable $e) {
            $this->logger->error(
                'CopyRateObjectIdToOrderPlugin: failed to persist rate_object_id to order',
                ['order_increment_id' => $result->getIncrementId(), 'error' => $e->getMessage()],
            );
        }

        return $result;
    }

    /**
     * Read the rate_object_id from quote_shipping_rate.method_description JSON
     * for the rate matching $shippingMethod (e.g. "shippo_USPS_PRIORITY").
     */
    private function extractRateObjectId(Quote $quote, string $shippingMethod): ?string
    {
        $address = $quote->getShippingAddress();
        if ($address === null) {
            return null;
        }

        foreach ($address->getAllShippingRates() as $rate) {
            $code = is_string($rate->getData('code')) ? (string)$rate->getData('code') : '';
            if ($code !== $shippingMethod) {
                continue;
            }

            $description = is_string($rate->getMethodDescription()) ? (string)$rate->getMethodDescription() : '';
            if ($description === '') {
                return null;
            }

            $decoded = json_decode($description, true);
            if (!is_array($decoded)) {
                return null;
            }

            $id = $decoded['rate_object_id'] ?? null;
            return is_string($id) && $id !== '' ? $id : null;
        }

        return null;
    }
}

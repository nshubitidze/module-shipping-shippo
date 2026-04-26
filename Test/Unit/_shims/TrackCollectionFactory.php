<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 *
 * phpcs:ignoreFile
 *
 * Shim for Magento's auto-generated
 * `Magento\Sales\Model\ResourceModel\Order\Shipment\Track\CollectionFactory`.
 * See _shims/CurlFactory.php for the rationale.
 */

declare(strict_types=1);

namespace Magento\Sales\Model\ResourceModel\Order\Shipment\Track;

if (!class_exists(CollectionFactory::class, false)) {
    class CollectionFactory
    {
        /**
         * @param array<string, mixed> $data
         */
        public function create(array $data = []): Collection
        {
            throw new \RuntimeException('stub factory — not callable at runtime');
        }
    }
}

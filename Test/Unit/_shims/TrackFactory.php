<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 *
 * phpcs:ignoreFile
 *
 * Shim for Magento's auto-generated `Magento\Sales\Model\Order\Shipment\TrackFactory`.
 * Magento ObjectManager creates the *Factory class at runtime under generated/code, so
 * a standalone unit-test bootstrap has no way to autoload it. We provide a minimal
 * concrete class so PHPUnit's mock generator can build MockObjects of it.
 */

declare(strict_types=1);

namespace Magento\Sales\Model\Order\Shipment;

if (!class_exists(TrackFactory::class, false)) {
    class TrackFactory
    {
        /**
         * @param array<string, mixed> $data
         */
        public function create(array $data = []): Track
        {
            throw new \RuntimeException('stub factory — not callable at runtime');
        }
    }
}

<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 *
 * phpcs:ignoreFile
 *
 * Shim for Magento's auto-generated factory class. Magento ObjectManager
 * creates `Magento\Shipping\Model\Rate\ResultFactory` at runtime under
 * generated/code, so a standalone unit-test bootstrap has no way to
 * autoload it. We provide a minimal concrete class so PHPUnit's mock
 * generator can build MockObjects of it.
 */

declare(strict_types=1);

namespace Magento\Shipping\Model\Rate;

if (!class_exists(ResultFactory::class, false)) {
    class ResultFactory
    {
        /**
         * @param array<string, mixed> $data
         */
        public function create(array $data = []): Result
        {
            throw new \RuntimeException('stub factory — not callable at runtime');
        }
    }
}

<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 *
 * phpcs:ignoreFile
 *
 * Shim for Magento's auto-generated factory class. Magento ObjectManager
 * creates `Magento\Quote\Model\Quote\Address\RateResult\MethodFactory` at
 * runtime under generated/code, so a standalone unit-test bootstrap has
 * no way to autoload it. We provide a minimal concrete class so PHPUnit's
 * mock generator can build MockObjects of it.
 */

declare(strict_types=1);

namespace Magento\Quote\Model\Quote\Address\RateResult;

if (!class_exists(MethodFactory::class, false)) {
    class MethodFactory
    {
        /**
         * @param array<string, mixed> $data
         */
        public function create(array $data = []): Method
        {
            throw new \RuntimeException('stub factory — not callable at runtime');
        }
    }
}

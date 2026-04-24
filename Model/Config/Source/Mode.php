<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Test/live toggle for the Shippo API key. Live mode is a documentation
 * flag — the key string itself (prefix `shippo_test_*` vs `shippo_live_*`)
 * is what Shippo honours server-side.
 */
class Mode implements OptionSourceInterface
{
    /**
     * @inheritDoc
     *
     * @return list<array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'test', 'label' => __('Test (sandbox)')],
            ['value' => 'live', 'label' => __('Live')],
        ];
    }
}

<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Api\Data\Dto;

use Shubo\ShippingCore\Api\Data\Dto\ContactAddress;

/**
 * Result of {@see \Shubo\ShippingShippo\Service\AddressValidator::validate()}.
 *
 * Three outcomes:
 *   - valid=true,  suggestion=null   -> Shippo accepted the address as-is
 *   - valid=false, suggestion=set    -> Shippo rejected and offered a corrected
 *                                        address (caller may show a "Did you mean…?")
 *   - valid=false, suggestion=null   -> Shippo rejected and could not correct it
 *
 * `messages` carries Shippo's own validation_results.messages[] verbatim
 * (de-duplicated, English text only — Shippo does not localise these). The
 * caller is responsible for any user-facing translation.
 *
 * @api
 */
class AddressValidationResult
{
    /**
     * @param list<string> $messages
     */
    public function __construct(
        public readonly bool $valid,
        public readonly ?ContactAddress $suggestion,
        public readonly array $messages,
    ) {
    }
}

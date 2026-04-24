<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Model\Service;

use Shubo\ShippingCore\Api\Data\Dto\ContactAddress;

/**
 * Maps a normalized {@see ContactAddress} to the Shippo address payload.
 *
 * Shippo expects `street1` / `street2` instead of Georgia-specific
 * `street / building / floor / apartment`. We concatenate street + building
 * into `street1` (comma-separated when both present) and apartment + floor
 * into `street2`. `postcode` is optional for some Shippo lanes — when
 * absent we send an empty string rather than null so the payload
 * serialization stays stable.
 */
class AddressMapper
{
    /**
     * @return array{
     *     name: string,
     *     phone: string,
     *     email: ?string,
     *     street1: string,
     *     street2: string,
     *     city: string,
     *     state: string,
     *     zip: string,
     *     country: string
     * }
     */
    public function toShippoPayload(ContactAddress $addr): array
    {
        return [
            'name' => $addr->name,
            'phone' => $addr->phone,
            'email' => $addr->email,
            'street1' => $this->joinStreetLine($addr->street, $addr->building),
            'street2' => $this->joinExtras($addr->apartment, $addr->floor),
            'city' => $addr->city,
            'state' => $addr->subdivision,
            'zip' => $addr->postcode ?? '',
            'country' => $addr->country,
        ];
    }

    private function joinStreetLine(string $street, ?string $building): string
    {
        if ($building === null || $building === '') {
            return $street;
        }
        return $street . ', ' . $building;
    }

    private function joinExtras(?string $apartment, ?string $floor): string
    {
        $parts = [];
        if ($apartment !== null && $apartment !== '') {
            $parts[] = $apartment;
        }
        if ($floor !== null && $floor !== '') {
            $parts[] = 'Floor ' . $floor;
        }
        return implode(', ', $parts);
    }
}

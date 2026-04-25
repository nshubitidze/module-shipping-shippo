<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 *
 * Fixture: builds a deterministic QuoteRequest pair (US -> US) that the
 * Shippo sandbox can reliably rate. Tbilisi -> US would also work but
 * adds international customs fields that aren't necessary for adapter
 * lifecycle proof.
 */

declare(strict_types=1);

use Shubo\ShippingCore\Api\Data\Dto\ContactAddress;
use Shubo\ShippingCore\Api\Data\Dto\ParcelSpec;
use Shubo\ShippingCore\Api\Data\Dto\QuoteRequest;

return new QuoteRequest(
    merchantId: 4, // Tikha
    origin: new ContactAddress(
        name: 'Shubo Lifecycle Origin',
        phone: '+14155551212',
        email: null,
        country: 'US',
        subdivision: 'CA',
        city: 'San Francisco',
        district: null,
        street: '215 Clayton St',
        building: null,
        floor: null,
        apartment: null,
        postcode: '94117',
        latitude: null,
        longitude: null,
        instructions: null,
    ),
    destination: new ContactAddress(
        name: 'Shubo Lifecycle Destination',
        phone: '+12125551212',
        email: null,
        country: 'US',
        subdivision: 'NY',
        city: 'New York',
        district: null,
        street: '20 W 34th St',
        building: null,
        floor: null,
        apartment: null,
        postcode: '10001',
        latitude: null,
        longitude: null,
        instructions: null,
    ),
    parcel: new ParcelSpec(
        weightGrams: 500,
        lengthMm: 200,
        widthMm: 150,
        heightMm: 100,
        declaredValueCents: 2500,
    ),
);

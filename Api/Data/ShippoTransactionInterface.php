<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Api\Data;

/**
 * Data interface for one Shippo transaction row.
 *
 * Represents the durable mapping between our {@see clientTrackingCode}
 * idempotency key and the Shippo `transactions[]` response. The table
 * holds only what the adapter needs to recover state after a restart:
 * Shippo transaction id, tracking number, provider carrier token, the
 * label URL, and the last-known Shippo status.
 *
 * @api
 */
interface ShippoTransactionInterface
{
    public const TABLE = 'shubo_shipping_shippo_transaction';

    public const FIELD_CLIENT_TRACKING_CODE = 'client_tracking_code';
    public const FIELD_SHIPPO_TRANSACTION_ID = 'shippo_transaction_id';
    public const FIELD_TRACKING_NUMBER = 'tracking_number';
    public const FIELD_CARRIER = 'carrier';
    public const FIELD_LABEL_URL = 'label_url';
    public const FIELD_STATUS = 'status';
    public const FIELD_CREATED_AT = 'created_at';

    public function getClientTrackingCode(): string;

    public function setClientTrackingCode(string $code): self;

    public function getShippoTransactionId(): string;

    public function setShippoTransactionId(string $id): self;

    public function getTrackingNumber(): string;

    public function setTrackingNumber(string $number): self;

    public function getCarrier(): string;

    public function setCarrier(string $carrier): self;

    public function getLabelUrl(): ?string;

    public function setLabelUrl(?string $url): self;

    public function getStatus(): string;

    public function setStatus(string $status): self;

    public function getCreatedAt(): ?string;
}

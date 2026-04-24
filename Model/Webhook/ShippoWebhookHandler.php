<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Model\Webhook;

use Psr\Log\LoggerInterface;
use Shubo\ShippingCore\Api\Data\Dto\WebhookResult;
use Shubo\ShippingCore\Api\WebhookHandlerInterface;
use Shubo\ShippingShippo\Model\Data\Config;
use Shubo\ShippingShippo\Model\Service\StatusMapper;

/**
 * Parses Shippo webhook payloads into a normalized {@see WebhookResult}.
 *
 * Contract (design §12):
 *   - verify `X-Shippo-Signature` via shared secret — reject on mismatch
 *   - filter events to `track_updated` only — other events accepted no-op
 *   - pull tracking_number / tracking_status.status / status_date / object_id
 *   - NO database writes, NO event dispatch — core applies the effect
 */
class ShippoWebhookHandler implements WebhookHandlerInterface
{
    public const CARRIER_CODE = 'shippo';
    public const EVENT_TRACK_UPDATED = 'track_updated';

    private const SIGNATURE_HEADER = 'x-shippo-signature';

    public function __construct(
        private readonly SignatureVerifier $verifier,
        private readonly Config $config,
        private readonly StatusMapper $statusMapper,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function code(): string
    {
        return self::CARRIER_CODE;
    }

    /**
     * @inheritDoc
     */
    public function handle(string $rawBody, array $headers): WebhookResult
    {
        $signature = $this->pullSignatureHeader($headers);
        $secret = $this->config->getWebhookSecret();

        if (!$this->verifier->verify($rawBody, $signature, $secret)) {
            return $this->rejected($rawBody, 'bad_signature');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning(
                'Shippo webhook body was not valid JSON',
                ['exception' => $e->getMessage()],
            );
            return $this->rejected($rawBody, 'invalid_json');
        }

        if (!is_array($decoded)) {
            return $this->rejected($rawBody, 'invalid_json');
        }

        $event = is_string($decoded['event'] ?? null) ? $decoded['event'] : '';
        if ($event !== self::EVENT_TRACK_UPDATED) {
            $this->logger->warning(
                'Ignoring non-track_updated Shippo webhook event',
                ['event' => $event],
            );
            return new WebhookResult(
                status: WebhookResult::STATUS_ACCEPTED,
                carrierTrackingId: null,
                normalizedStatus: null,
                externalEventId: null,
                occurredAt: null,
                rawPayload: $rawBody,
            );
        }

        /** @var array<string, mixed> $data */
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $trackingNumber = is_string($data['tracking_number'] ?? null) ? $data['tracking_number'] : '';
        /** @var array<string, mixed> $trackingStatus */
        $trackingStatus = is_array($data['tracking_status'] ?? null) ? $data['tracking_status'] : [];
        $rawStatus = is_string($trackingStatus['status'] ?? null) ? $trackingStatus['status'] : '';
        $occurredAt = is_string($trackingStatus['status_date'] ?? null) ? $trackingStatus['status_date'] : null;
        $externalEventId = $this->resolveExternalEventId($decoded, $data);

        if ($trackingNumber === '' || $rawStatus === '' || $externalEventId === '') {
            return $this->rejected($rawBody, 'malformed_payload');
        }

        $normalized = $this->statusMapper->map($rawStatus);

        return new WebhookResult(
            status: WebhookResult::STATUS_ACCEPTED,
            carrierTrackingId: $trackingNumber,
            normalizedStatus: $normalized,
            externalEventId: $externalEventId,
            occurredAt: $occurredAt,
            rawPayload: $rawBody,
        );
    }

    /**
     * @param array<string, mixed> $decoded
     * @param array<string, mixed> $data
     */
    private function resolveExternalEventId(array $decoded, array $data): string
    {
        $fromData = is_string($data['object_id'] ?? null) ? $data['object_id'] : '';
        if ($fromData !== '') {
            return $fromData;
        }
        $fromRoot = is_string($decoded['event_id'] ?? null) ? $decoded['event_id'] : '';
        return $fromRoot;
    }

    /**
     * @param array<string, string> $headers
     */
    private function pullSignatureHeader(array $headers): string
    {
        foreach ($headers as $name => $value) {
            if (strtolower((string)$name) === self::SIGNATURE_HEADER) {
                return (string)$value;
            }
        }
        return '';
    }

    private function rejected(string $rawBody, string $reason): WebhookResult
    {
        return new WebhookResult(
            status: WebhookResult::STATUS_REJECTED,
            carrierTrackingId: null,
            normalizedStatus: null,
            externalEventId: null,
            occurredAt: null,
            rawPayload: $rawBody,
            rejectionReason: $reason,
        );
    }
}

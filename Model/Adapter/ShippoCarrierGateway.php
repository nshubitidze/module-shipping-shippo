<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Model\Adapter;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;
use Shubo\ShippingCore\Api\CarrierGatewayInterface;
use Shubo\ShippingCore\Api\Data\Dto\CancelResponse;
use Shubo\ShippingCore\Api\Data\Dto\ContactAddress;
use Shubo\ShippingCore\Api\Data\Dto\LabelResponse;
use Shubo\ShippingCore\Api\Data\Dto\ParcelSpec;
use Shubo\ShippingCore\Api\Data\Dto\QuoteRequest;
use Shubo\ShippingCore\Api\Data\Dto\QuoteResponse;
use Shubo\ShippingCore\Api\Data\Dto\RateOption;
use Shubo\ShippingCore\Api\Data\Dto\ShipmentRequest;
use Shubo\ShippingCore\Api\Data\Dto\ShipmentResponse;
use Shubo\ShippingCore\Api\Data\Dto\StatusResponse;
use Shubo\ShippingCore\Exception\NoCarrierAvailableException;
use Shubo\ShippingCore\Exception\ShipmentDispatchFailedException;
use Shubo\ShippingShippo\Api\Data\ShippoTransactionInterface;
use Shubo\ShippingShippo\Api\Data\ShippoTransactionInterfaceFactory;
use Shubo\ShippingShippo\Api\ShippoTransactionRepositoryInterface;
use Shubo\ShippingShippo\Model\Data\Config;
use Shubo\ShippingShippo\Model\Service\AddressMapper;
use Shubo\ShippingShippo\Model\Service\ParcelMapper;
use Shubo\ShippingShippo\Model\Service\ShippoClient;
use Shubo\ShippingShippo\Model\Service\StatusMapper;

/**
 * Shippo adapter for the ShippingCore CarrierGateway contract.
 *
 * Translation layer only — no DB writes outside the idempotency table,
 * no Magento event dispatch, no order mutation. The adapter:
 *   - turns normalized DTOs into Shippo payloads
 *   - delegates HTTP to {@see ShippoClient}
 *   - filters rates by `allowed_carriers` config
 *   - maintains the idempotency table so `createShipment` reruns
 *     cheaply after a retry or a partial failure
 *
 * Design reference: docs/design.md §§ 6–11.
 */
class ShippoCarrierGateway implements CarrierGatewayInterface
{
    public const CARRIER_CODE = 'shippo';

    private const STATUS_SUCCESS = 'SUCCESS';
    private const STATUS_QUEUED = 'QUEUED';

    private const LOCAL_STATUS_CREATED = 'created';

    /** @var array<string, array<string, mixed>> */
    private array $rateCache = [];

    public function __construct(
        private readonly ShippoClient $client,
        private readonly Config $config,
        private readonly AddressMapper $addressMapper,
        private readonly ParcelMapper $parcelMapper,
        private readonly StatusMapper $statusMapper,
        private readonly ShippoTransactionRepositoryInterface $txRepo,
        private readonly ShippoTransactionInterfaceFactory $txFactory,
        private readonly LoggerInterface $logger,
        private readonly ?SerializerInterface $serializer = null,
    ) {
    }

    public function code(): string
    {
        return self::CARRIER_CODE;
    }

    public function quote(QuoteRequest $request): QuoteResponse
    {
        $response = $this->fetchShipmentQuote($request);
        /** @var list<array<string, mixed>> $rates */
        $rates = $this->filterRatesByAllowedCarriers($this->rawRates($response));

        $options = [];
        foreach ($rates as $rate) {
            $options[] = $this->rateToOption($rate);
        }

        return new QuoteResponse($options, $this->extractMessages($response));
    }

    public function createShipment(ShipmentRequest $request): ShipmentResponse
    {
        try {
            $existing = $this->txRepo->getByClientTrackingCode($request->clientTrackingCode);
            $this->logger->info(
                'Shippo createShipment idempotent hit — returning cached ShipmentResponse',
                [
                    'client_tracking_code' => $request->clientTrackingCode,
                    'shippo_transaction_id' => $existing->getShippoTransactionId(),
                ],
            );
            return new ShipmentResponse(
                carrierTrackingId: $existing->getTrackingNumber(),
                labelUrl: $existing->getLabelUrl(),
                status: $existing->getStatus(),
                raw: ['idempotent' => true, 'shippo_transaction_id' => $existing->getShippoTransactionId()],
            );
        } catch (NoSuchEntityException) {
            // fall through — first time seeing this code.
        }

        // When the order carries a pre-selected rate_object_id (persisted during checkout),
        // buy the label directly without requoting — preserves the exact rate the customer saw.
        $rateObjectId = isset($request->metadata['shippo_rate_object_id'])
            && is_string($request->metadata['shippo_rate_object_id'])
            && $request->metadata['shippo_rate_object_id'] !== ''
            ? $request->metadata['shippo_rate_object_id']
            : null;

        if ($rateObjectId !== null) {
            $tx = $this->client->createTransaction($rateObjectId);
            $status = is_string($tx['status'] ?? null) ? $tx['status'] : '';

            if ($status === self::STATUS_QUEUED) {
                $this->logger->warning(
                    'Shippo transaction QUEUED on rate-object-id path',
                    [
                        'client_tracking_code' => $request->clientTrackingCode,
                        'rate_object_id' => $rateObjectId,
                    ],
                );
                throw new ShipmentDispatchFailedException(
                    __('Shippo transaction queued — retry once async processing completes.'),
                );
            }

            if ($status !== self::STATUS_SUCCESS) {
                $message = $this->extractTxMessage($tx);
                $this->logger->error(
                    'Shippo rejected label purchase on rate-object-id path',
                    [
                        'client_tracking_code' => $request->clientTrackingCode,
                        'status' => $status,
                        'message' => $message,
                    ],
                );
                throw new ShipmentDispatchFailedException(
                    __('Shippo rejected label purchase: %1', $message),
                );
            }

            $trackingNumber = (string)($tx['tracking_number'] ?? '');
            $labelUrl = isset($tx['label_url']) && is_string($tx['label_url']) && $tx['label_url'] !== ''
                ? $tx['label_url']
                : null;
            $shippoTxId = (string)($tx['object_id'] ?? '');
            $carrierToken = '';
            $nested = is_array($tx['rate'] ?? null) ? $tx['rate'] : [];
            if (is_string($nested['provider'] ?? null) && $nested['provider'] !== '') {
                $carrierToken = (string)$nested['provider'];
            }

            $this->persistTransaction(
                clientTrackingCode: $request->clientTrackingCode,
                shippoTxId: $shippoTxId,
                trackingNumber: $trackingNumber,
                carrier: $carrierToken,
                labelUrl: $labelUrl,
                status: self::LOCAL_STATUS_CREATED,
            );

            return new ShipmentResponse(
                carrierTrackingId: $trackingNumber,
                labelUrl: $labelUrl,
                status: self::LOCAL_STATUS_CREATED,
                raw: $tx,
            );
        }

        $quoteRequest = new QuoteRequest(
            merchantId: $request->merchantId,
            origin: $request->origin,
            destination: $request->destination,
            parcel: $request->parcel,
            codRequested: $request->codEnabled,
            codAmountCents: $request->codAmountCents,
            preferredCarrierCode: $request->preferredCarrierCode,
        );

        $shipmentResponse = $this->fetchShipmentQuote($quoteRequest);
        /** @var list<array<string, mixed>> $rates */
        $rates = $this->filterRatesByAllowedCarriers($this->rawRates($shipmentResponse));
        if ($request->preferredCarrierCode !== null && $request->preferredCarrierCode !== '') {
            $rates = $this->filterRatesByPreferredProvider($rates, $request->preferredCarrierCode);
        }

        if ($rates === []) {
            throw new NoCarrierAvailableException(
                __('Shippo returned no eligible rates for this shipment.'),
            );
        }

        $cheapest = $this->pickCheapestRate($rates);
        $rateId = (string)($cheapest['object_id'] ?? '');
        if ($rateId === '') {
            throw new ShipmentDispatchFailedException(
                __('Shippo rate response missing object_id — cannot purchase label.'),
            );
        }

        $tx = $this->client->createTransaction($rateId);
        $status = is_string($tx['status'] ?? null) ? $tx['status'] : '';

        if ($status === self::STATUS_QUEUED) {
            $this->logger->warning(
                'Shippo transaction stuck in QUEUED — async processing not complete',
                [
                    'client_tracking_code' => $request->clientTrackingCode,
                    'rate_id' => $rateId,
                ],
            );
            throw new ShipmentDispatchFailedException(
                __('Shippo transaction queued — retry once async processing completes.'),
            );
        }

        if ($status !== self::STATUS_SUCCESS) {
            $message = $this->extractTxMessage($tx);
            $this->logger->error(
                'Shippo rejected label purchase',
                [
                    'client_tracking_code' => $request->clientTrackingCode,
                    'status' => $status,
                    'message' => $message,
                ],
            );
            throw new ShipmentDispatchFailedException(
                __('Shippo rejected label purchase: %1', $message),
            );
        }

        $trackingNumber = (string)($tx['tracking_number'] ?? '');
        $labelUrl = isset($tx['label_url']) && is_string($tx['label_url']) && $tx['label_url'] !== ''
            ? $tx['label_url']
            : null;
        $shippoTxId = (string)($tx['object_id'] ?? '');
        $carrierToken = $this->extractCarrierToken($cheapest, $tx);

        $this->persistTransaction(
            clientTrackingCode: $request->clientTrackingCode,
            shippoTxId: $shippoTxId,
            trackingNumber: $trackingNumber,
            carrier: $carrierToken,
            labelUrl: $labelUrl,
            status: self::LOCAL_STATUS_CREATED,
        );

        return new ShipmentResponse(
            carrierTrackingId: $trackingNumber,
            labelUrl: $labelUrl,
            status: self::LOCAL_STATUS_CREATED,
            raw: $tx,
        );
    }

    public function cancelShipment(string $carrierTrackingId, ?string $reason = null): CancelResponse
    {
        try {
            $existing = $this->txRepo->getByTrackingNumber($carrierTrackingId);
        } catch (NoSuchEntityException $e) {
            return new CancelResponse(
                success: false,
                carrierMessage: 'Unknown tracking number: ' . $carrierTrackingId,
                raw: ['reason' => $reason],
            );
        }

        $refund = $this->client->createRefund($existing->getShippoTransactionId());
        $refundStatus = is_string($refund['status'] ?? null) ? $refund['status'] : '';
        // Shippo returns QUEUED or SUCCESS for an accepted refund request.
        $success = in_array($refundStatus, ['QUEUED', 'SUCCESS'], true);

        return new CancelResponse(
            success: $success,
            carrierMessage: $success ? null : $this->extractTxMessage($refund),
            raw: $refund,
        );
    }

    public function getShipmentStatus(string $carrierTrackingId): StatusResponse
    {
        $existing = $this->txRepo->getByTrackingNumber($carrierTrackingId);
        $carrier = $existing->getCarrier();
        $trackingNumber = $existing->getTrackingNumber();

        $track = $this->client->getTrack($carrier, $trackingNumber);
        /** @var array<string, mixed> $trackingStatus */
        $trackingStatus = is_array($track['tracking_status'] ?? null) ? $track['tracking_status'] : [];
        $raw = is_string($trackingStatus['status'] ?? null) ? $trackingStatus['status'] : 'UNKNOWN';
        $normalized = $this->statusMapper->map($raw);
        $occurredAt = is_string($trackingStatus['status_date'] ?? null) ? $trackingStatus['status_date'] : null;

        return new StatusResponse(
            normalizedStatus: $normalized,
            carrierStatusRaw: $raw,
            occurredAt: $occurredAt,
            codCollectedAt: null,
            raw: $track,
        );
    }

    public function fetchLabel(string $carrierTrackingId): LabelResponse
    {
        $existing = $this->txRepo->getByTrackingNumber($carrierTrackingId);
        $labelUrl = $existing->getLabelUrl();
        if ($labelUrl === null || $labelUrl === '') {
            throw new ShipmentDispatchFailedException(
                __('Label not available for tracking id %1.', $carrierTrackingId),
            );
        }

        $bytes = $this->client->fetchBinary($labelUrl);
        return new LabelResponse(
            pdfBytes: $bytes,
            contentType: 'application/pdf',
            filename: sprintf('shippo-%s.pdf', $existing->getTrackingNumber()),
        );
    }

    /**
     * @return list<\Shubo\ShippingCore\Api\Data\GeoCacheInterface>
     */
    public function listCities(): array
    {
        return [];
    }

    /**
     * @return list<\Shubo\ShippingCore\Api\Data\GeoCacheInterface>
     */
    public function listPudos(?string $cityCode = null): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchShipmentQuote(QuoteRequest $request): array
    {
        $cacheSource = [
            'origin' => $this->addressMapper->toShippoPayload($request->origin),
            'destination' => $this->addressMapper->toShippoPayload($request->destination),
            'parcel' => $this->parcelMapper->toShippoPayload($request->parcel),
            'cod' => $request->codRequested,
            'cod_amount' => $request->codAmountCents,
        ];
        // Prefer Magento's SerializerInterface (JSON-safe, framework-sanctioned);
        // fall back to json_encode when the DI slot is unbound under unit tests.
        if ($this->serializer !== null) {
            $serialized = (string)$this->serializer->serialize($cacheSource);
        } else {
            $fallback = json_encode($cacheSource);
            $serialized = $fallback === false ? '' : $fallback;
        }
        $cacheKey = hash('sha256', $serialized);

        if (isset($this->rateCache[$cacheKey])) {
            return $this->rateCache[$cacheKey];
        }

        $payload = [
            'address_from' => $this->addressMapper->toShippoPayload($request->origin),
            'address_to' => $this->addressMapper->toShippoPayload($request->destination),
            'parcels' => [$this->parcelMapper->toShippoPayload($request->parcel)],
            'async' => false,
        ];

        $response = $this->client->createShipment($payload);
        $this->rateCache[$cacheKey] = $response;
        return $response;
    }

    /**
     * @param array<string, mixed> $response
     * @return list<array<string, mixed>>
     */
    private function rawRates(array $response): array
    {
        /** @var list<array<string, mixed>> $rates */
        $rates = [];
        if (isset($response['rates']) && is_array($response['rates'])) {
            foreach ($response['rates'] as $rate) {
                if (is_array($rate)) {
                    /** @var array<string, mixed> $rate */
                    $rates[] = $rate;
                }
            }
        }
        return $rates;
    }

    /**
     * @param list<array<string, mixed>> $rates
     * @return list<array<string, mixed>>
     */
    private function filterRatesByAllowedCarriers(array $rates): array
    {
        $allowed = $this->config->getAllowedCarriers();
        if ($allowed === []) {
            return $rates;
        }
        $out = [];
        foreach ($rates as $rate) {
            $provider = is_string($rate['provider'] ?? null) ? $rate['provider'] : '';
            if (in_array($provider, $allowed, true)) {
                $out[] = $rate;
            }
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rates
     * @return list<array<string, mixed>>
     */
    private function filterRatesByPreferredProvider(array $rates, string $preferred): array
    {
        $out = [];
        foreach ($rates as $rate) {
            $provider = is_string($rate['provider'] ?? null) ? $rate['provider'] : '';
            if (strcasecmp($provider, $preferred) === 0) {
                $out[] = $rate;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $rate
     */
    private function rateToOption(array $rate): RateOption
    {
        $provider = is_string($rate['provider'] ?? null) ? $rate['provider'] : '';
        $servicelevel = is_array($rate['servicelevel'] ?? null) ? $rate['servicelevel'] : [];
        $token = is_string($servicelevel['token'] ?? null) ? $servicelevel['token'] : 'standard';
        $name = is_string($servicelevel['name'] ?? null) ? $servicelevel['name'] : $token;
        $amount = is_string($rate['amount'] ?? null) ? $rate['amount'] : '0';
        $etaDays = is_int($rate['estimated_days'] ?? null) ? (int)$rate['estimated_days'] : 0;
        $objectId = is_string($rate['object_id'] ?? null) ? $rate['object_id'] : '';

        return new RateOption(
            carrierCode: $provider,
            methodCode: sprintf('%s_%s', $provider, $token),
            priceCents: (int)bcmul($amount, '100', 0),
            etaDays: $etaDays,
            serviceLevel: $name,
            rationale: 'shippo-rate-' . $objectId,
            pudoExternalId: null,
            adapterMetadata: $objectId !== ''
                ? ['rate_object_id' => $objectId, 'carrier_token' => $provider]
                : null,
        );
    }

    /**
     * @param list<array<string, mixed>> $rates
     * @return array<string, mixed>
     */
    private function pickCheapestRate(array $rates): array
    {
        $cheapest = null;
        $cheapestCents = PHP_INT_MAX;
        foreach ($rates as $rate) {
            $amount = is_string($rate['amount'] ?? null) ? $rate['amount'] : '0';
            $cents = (int)bcmul($amount, '100', 0);
            if ($cents < $cheapestCents) {
                $cheapestCents = $cents;
                $cheapest = $rate;
            }
        }
        if ($cheapest === null) {
            throw new NoCarrierAvailableException(
                __('No rates in Shippo response to choose from.'),
            );
        }
        return $cheapest;
    }

    /**
     * @param array<string, mixed> $response
     * @return list<string>
     */
    private function extractMessages(array $response): array
    {
        $messages = [];
        if (isset($response['messages']) && is_array($response['messages'])) {
            foreach ($response['messages'] as $message) {
                if (is_array($message) && isset($message['text']) && is_string($message['text'])) {
                    $messages[] = $message['text'];
                }
            }
        }
        return $messages;
    }

    /**
     * @param array<string, mixed> $tx
     */
    private function extractTxMessage(array $tx): string
    {
        if (isset($tx['messages']) && is_array($tx['messages'])) {
            $first = $tx['messages'][0] ?? null;
            if (is_array($first) && isset($first['text']) && is_string($first['text'])) {
                return $first['text'];
            }
        }
        return 'unknown';
    }

    /**
     * @param array<string, mixed> $rate
     * @param array<string, mixed> $tx
     */
    private function extractCarrierToken(array $rate, array $tx): string
    {
        $fromRate = is_string($rate['provider'] ?? null) ? $rate['provider'] : '';
        if ($fromRate !== '') {
            return $fromRate;
        }
        $nested = is_array($tx['rate'] ?? null) ? $tx['rate'] : [];
        $fromTx = is_string($nested['provider'] ?? null) ? $nested['provider'] : '';
        return $fromTx;
    }

    private function persistTransaction(
        string $clientTrackingCode,
        string $shippoTxId,
        string $trackingNumber,
        string $carrier,
        ?string $labelUrl,
        string $status,
    ): void {
        /** @var ShippoTransactionInterface $model */
        $model = $this->txFactory->create();
        $model->setClientTrackingCode($clientTrackingCode)
            ->setShippoTransactionId($shippoTxId)
            ->setTrackingNumber($trackingNumber)
            ->setCarrier($carrier)
            ->setLabelUrl($labelUrl)
            ->setStatus($status);

        $this->txRepo->save($model);
    }
}

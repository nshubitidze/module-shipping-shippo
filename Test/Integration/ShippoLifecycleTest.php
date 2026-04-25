<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

// Decision (per design.md §2 Phase A): use plain PHPUnit\Framework\TestCase, NOT
// Magento's integration TestCase. The gateway only needs an HTTP client + a Config
// stub + a logger + an in-memory transaction repository — Magento's bootstrap, DB,
// fixtures, and event bus add ~30s of setup for zero coverage benefit. Trade-off
// documented: the local idempotency table is mocked in-memory; the Shippo HTTP
// path is real and lives or dies against the live sandbox.

namespace Shubo\ShippingShippo\Test\Integration;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shubo\ShippingCore\Api\Data\Dto\QuoteRequest;
use Shubo\ShippingCore\Api\Data\Dto\ShipmentRequest;
use Shubo\ShippingCore\Api\Data\Dto\WebhookResult;
use Shubo\ShippingShippo\Api\Data\ShippoTransactionInterface;
use Shubo\ShippingShippo\Api\Data\ShippoTransactionInterfaceFactory;
use Shubo\ShippingShippo\Model\Adapter\ShippoCarrierGateway;
use Shubo\ShippingShippo\Model\Data\Config;
use Shubo\ShippingShippo\Model\Service\AddressMapper;
use Shubo\ShippingShippo\Model\Service\ParcelMapper;
use Shubo\ShippingShippo\Model\Service\ShippoClient;
use Shubo\ShippingShippo\Model\Service\StatusMapper;
use Shubo\ShippingShippo\Model\Webhook\ShippoWebhookHandler;
use Shubo\ShippingShippo\Model\Webhook\SignatureVerifier;
use Shubo\ShippingShippo\Test\Integration\Helper\InMemoryShippoTransactionRepository;
use Shubo\ShippingShippo\Test\Integration\Helper\ShippoSandboxClient;
use Shubo\ShippingShippo\Test\Integration\Helper\TestShippoTransaction;

/**
 * End-to-end lifecycle test against the live Shippo sandbox.
 *
 * Steps (per session-5 plan §Phase A):
 *   1. quote()           -> >=1 RateOption, capture rate_object_id
 *   2. createShipment()  -> label_url + tracking_number + transaction_object_id
 *   3. HEAD label_url    -> 200 + PDF (or octet-stream)
 *   4. getShipmentStatus -> StatusResponse
 *   5. Webhook handler   -> signed track_updated body -> STATUS_ACCEPTED
 *
 * Captured Shippo object_ids are dumped to /tmp/shippo-lifecycle-trace.json so the
 * developer-side MCP read-back can diff them independently after the test passes.
 *
 * @covers \Shubo\ShippingShippo\Model\Adapter\ShippoCarrierGateway
 * @covers \Shubo\ShippingShippo\Model\Webhook\ShippoWebhookHandler
 *
 * @group integration
 */
class ShippoLifecycleTest extends TestCase
{
    private const TRACE_FILE = '/tmp/shippo-lifecycle-trace.json';
    private const TEST_WEBHOOK_SECRET = 'integration-test-webhook-secret-do-not-use-in-prod';

    private ShippoCarrierGateway $gateway;
    private ShippoWebhookHandler $webhookHandler;
    private InMemoryShippoTransactionRepository $repo;

    protected function setUp(): void
    {
        $key = ShippoSandboxClient::loadKey();
        if ($key === null) {
            self::markTestSkipped(
                'Shippo sandbox key not available (set SHIPPO_API_KEY env or ~/.shippo-key).',
            );
        }
        if (!ShippoSandboxClient::isSandboxKey($key)) {
            self::fail(
                'Refusing to run integration test against a non-sandbox key (must start with shippo_test_).',
            );
        }

        $config = $this->buildConfig($key, self::TEST_WEBHOOK_SECRET);
        $logger = new NullLogger();
        $curlFactory = $this->buildCurlFactory();
        $client = new ShippoClient(
            curlFactory: $curlFactory,
            config: $config,
            logger: $logger,
            sleeper: static function (int $ms): void {
                // no real sleep in tests
            },
        );

        $statusMapper = new StatusMapper($logger);
        $this->repo = new InMemoryShippoTransactionRepository();
        $txFactory = $this->buildTxFactory();

        $this->gateway = new ShippoCarrierGateway(
            client: $client,
            config: $config,
            addressMapper: new AddressMapper(),
            parcelMapper: new ParcelMapper(),
            statusMapper: $statusMapper,
            txRepo: $this->repo,
            txFactory: $txFactory,
            logger: $logger,
        );

        $this->webhookHandler = new ShippoWebhookHandler(
            verifier: new SignatureVerifier(),
            config: $config,
            statusMapper: $statusMapper,
            logger: $logger,
        );
    }

    public function testFullLifecycleAgainstSandbox(): void
    {
        // Step 1 - quote()
        /** @var QuoteRequest $quoteRequest */
        $quoteRequest = require __DIR__ . '/_files/sample_quote_request.php';

        $quoteResponse = $this->gateway->quote($quoteRequest);
        self::assertNotEmpty(
            $quoteResponse->options,
            'Shippo sandbox returned zero rates for the lifecycle test parcel '
            . '(US -> US, 500g). Check sandbox carrier configuration. Errors: '
            . implode(' | ', $quoteResponse->errors),
        );

        // Pin to USPS — Shippo's sandbox UPS account is not activated by
        // default, and picking the cheapest rate without filtering can land
        // on UPS which then errors at carrier-account level. USPS works
        // out-of-the-box on every Shippo sandbox.
        $usps = $this->cheapestUspsOption($quoteResponse->options);
        self::assertNotNull($usps, 'Shippo sandbox returned no USPS rate for the lifecycle parcel.');
        self::assertGreaterThan(0, $usps->priceCents);
        $rateObjectId = str_replace('shippo-rate-', '', $usps->rationale);
        self::assertNotEmpty($rateObjectId);

        // Step 2 - createShipment()
        $clientTrackingCode = 'lifecycle-' . bin2hex(random_bytes(6));
        $shipmentRequest = new ShipmentRequest(
            orderId: 999_001,
            merchantId: $quoteRequest->merchantId,
            clientTrackingCode: $clientTrackingCode,
            origin: $quoteRequest->origin,
            destination: $quoteRequest->destination,
            parcel: $quoteRequest->parcel,
            codEnabled: false,
            codAmountCents: 0,
            preferredCarrierCode: 'usps',
        );

        $shipmentResponse = $this->gateway->createShipment($shipmentRequest);
        self::assertNotEmpty($shipmentResponse->carrierTrackingId, 'Shippo did not return a tracking number.');
        self::assertNotNull($shipmentResponse->labelUrl, 'Shippo did not return a label URL.');
        self::assertSame('created', $shipmentResponse->status);

        // Recover the persisted Shippo transaction id (gateway hides it on the row).
        $persisted = $this->repo->getByTrackingNumber($shipmentResponse->carrierTrackingId);
        $shippoTxId = $persisted->getShippoTransactionId();
        self::assertNotEmpty($shippoTxId, 'Persisted shippo_transaction_id is empty.');

        // Step 3 - HEAD label_url
        $labelUrl = $shipmentResponse->labelUrl;
        self::assertIsString($labelUrl);
        $head = $this->headRequest($labelUrl);
        self::assertGreaterThanOrEqual(200, $head['status']);
        self::assertLessThan(400, $head['status']);
        $contentType = strtolower($head['content_type']);
        self::assertTrue(
            str_contains($contentType, 'application/pdf')
            || str_contains($contentType, 'application/octet-stream'),
            'Label URL did not return a PDF/octet-stream content type. Got: ' . $contentType,
        );

        // Step 4 - getShipmentStatus()
        //
        // Important Shippo sandbox quirk: the `/tracks/{carrier}/{tracking_number}`
        // endpoint refuses any test carrier other than the literal `shippo` mock
        // carrier (USPS test rates produce labels but cannot be tracked through
        // the same endpoint). To exercise the real getShipmentStatus HTTP path,
        // we seed an in-memory transaction with the SHIPPO_TRANSIT mock tracking
        // number and call against that. The production flow is identical — the
        // gateway looks up by tracking number, reads carrier from the row, and
        // calls /tracks/{carrier}/{tracking_number}.
        $mockTracking = 'SHIPPO_TRANSIT';
        $mockTx = new TestShippoTransaction();
        $mockTx
            ->setClientTrackingCode('lifecycle-status-mock-' . bin2hex(random_bytes(4)))
            ->setShippoTransactionId('mock_status_check')
            ->setTrackingNumber($mockTracking)
            ->setCarrier('shippo')
            ->setLabelUrl(null)
            ->setStatus('created');
        $this->repo->save($mockTx);

        $statusResponse = $this->gateway->getShipmentStatus($mockTracking);
        self::assertSame('TRANSIT', $statusResponse->carrierStatusRaw);
        self::assertSame('in_transit', $statusResponse->normalizedStatus);

        // Step 5 - signed webhook -> handler
        $webhookBody = $this->buildSignedWebhookBody(
            trackingNumber: $shipmentResponse->carrierTrackingId,
            shippoTxObjectId: $shippoTxId,
        );
        $signature = hash_hmac('sha256', $webhookBody, self::TEST_WEBHOOK_SECRET);
        $webhookResult = $this->webhookHandler->handle(
            $webhookBody,
            ['X-Shippo-Signature' => $signature],
        );
        self::assertSame(WebhookResult::STATUS_ACCEPTED, $webhookResult->status);
        self::assertSame($shipmentResponse->carrierTrackingId, $webhookResult->carrierTrackingId);
        self::assertSame('in_transit', $webhookResult->normalizedStatus);
        self::assertSame($shippoTxId, $webhookResult->externalEventId);

        // Negative path: same body with a bad signature must be rejected
        $badResult = $this->webhookHandler->handle(
            $webhookBody,
            ['X-Shippo-Signature' => str_repeat('0', 64)],
        );
        self::assertSame(WebhookResult::STATUS_REJECTED, $badResult->status);
        self::assertSame('bad_signature', $badResult->rejectionReason);

        // Dump the captured object_ids for the developer-side MCP read-back.
        $this->writeTrace([
            'client_tracking_code' => $clientTrackingCode,
            'rate_object_id' => $rateObjectId,
            'shippo_transaction_id' => $shippoTxId,
            'tracking_number' => $shipmentResponse->carrierTrackingId,
            'carrier_token' => $persisted->getCarrier(),
            'label_url' => $labelUrl,
            'cheapest_rate' => [
                'carrier' => $usps->carrierCode,
                'method' => $usps->methodCode,
                'price_cents' => $usps->priceCents,
                'eta_days' => $usps->etaDays,
            ],
            'webhook_simulation' => [
                'normalized_status' => $webhookResult->normalizedStatus,
                'occurred_at' => $webhookResult->occurredAt,
            ],
            'cleanup_required' => true,
        ]);
    }

    private function buildConfig(string $apiKey, string $webhookSecret): Config
    {
        // We cannot mock readonly classes via createMock without trickery; build a
        // small anonymous-class subclass that overrides the public surface.
        return new class ($apiKey, $webhookSecret) extends Config {
            public function __construct(
                private readonly string $apiKeyOverride,
                private readonly string $webhookSecretOverride,
            ) {
                // Intentionally do NOT call parent::__construct — we never read scope.
            }

            public function isEnabled(): bool
            {
                return true;
            }

            public function getMode(): string
            {
                return 'test';
            }

            public function getApiKey(): string
            {
                return $this->apiKeyOverride;
            }

            public function getWebhookSecret(): string
            {
                return $this->webhookSecretOverride;
            }

            /** @return list<string> */
            public function getAllowedCarriers(): array
            {
                return [];
            }

            public function getRateCacheTtl(): int
            {
                return 60;
            }

            public function getApiBaseUrl(): string
            {
                return 'https://api.goshippo.com';
            }
        };
    }

    private function buildCurlFactory(): CurlFactory
    {
        // Real Magento Curl client — same code path the production gateway uses.
        return new class extends CurlFactory {
            public function __construct()
            {
                // skip parent ObjectManager init.
            }

            /** @param array<string, mixed> $data */
            public function create(array $data = []): Curl
            {
                return new Curl();
            }
        };
    }

    private function buildTxFactory(): ShippoTransactionInterfaceFactory
    {
        return new class extends ShippoTransactionInterfaceFactory {
            public function __construct()
            {
                // skip parent
            }

            /** @param array<string, mixed> $data */
            public function create(array $data = []): ShippoTransactionInterface
            {
                return new TestShippoTransaction();
            }
        };
    }

    /**
     * @param list<\Shubo\ShippingCore\Api\Data\Dto\RateOption> $options
     */
    private function cheapestUspsOption(array $options): ?\Shubo\ShippingCore\Api\Data\Dto\RateOption
    {
        $usps = array_values(array_filter(
            $options,
            static fn (\Shubo\ShippingCore\Api\Data\Dto\RateOption $opt): bool
                => strcasecmp($opt->carrierCode, 'usps') === 0,
        ));
        if ($usps === []) {
            return null;
        }
        usort($usps, static fn ($a, $b) => $a->priceCents <=> $b->priceCents);
        return $usps[0];
    }

    /**
     * @return array{status: int, content_type: string}
     */
    private function headRequest(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            self::fail('curl_init failed for ' . $url);
        }
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'shippo-lifecycle-test/1.0',
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            self::fail('HEAD on label URL failed: ' . $err);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        return ['status' => $status, 'content_type' => $contentType];
    }

    private function buildSignedWebhookBody(string $trackingNumber, string $shippoTxObjectId): string
    {
        $body = json_encode([
            'event' => 'track_updated',
            'data' => [
                'tracking_number' => $trackingNumber,
                'tracking_status' => [
                    'status' => 'TRANSIT',
                    'status_date' => '2026-04-25T12:00:00Z',
                    'status_details' => 'Package handed off to carrier (lifecycle test)',
                ],
                'object_id' => $shippoTxObjectId,
            ],
        ]);
        if ($body === false) {
            self::fail('json_encode webhook body failed.');
        }
        return $body;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeTrace(array $data): void
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            self::fail('Failed to encode lifecycle trace.');
        }
        $written = file_put_contents(self::TRACE_FILE, $encoded . "\n");
        if ($written === false) {
            self::fail('Failed to write lifecycle trace to ' . self::TRACE_FILE);
        }
    }
}


<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Test\Integration;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shubo\ShippingCore\Api\Data\Dto\ContactAddress;
use Shubo\ShippingCore\Api\Data\Dto\ParcelSpec;
use Shubo\ShippingCore\Api\Data\Dto\QuoteRequest;
use Shubo\ShippingCore\Api\Data\Dto\ShipmentRequest;
use Shubo\ShippingShippo\Api\Data\ShippoTransactionInterface;
use Shubo\ShippingShippo\Api\Data\ShippoTransactionInterfaceFactory;
use Shubo\ShippingShippo\Model\Adapter\ShippoCarrierGateway;
use Shubo\ShippingShippo\Model\Data\Config;
use Shubo\ShippingShippo\Model\Service\AddressMapper;
use Shubo\ShippingShippo\Model\Service\ParcelMapper;
use Shubo\ShippingShippo\Model\Service\ShippoClient;
use Shubo\ShippingShippo\Model\Service\StatusMapper;
use Shubo\ShippingShippo\Test\Integration\Helper\InMemoryShippoTransactionRepository;
use Shubo\ShippingShippo\Test\Integration\Helper\ShippoSandboxClient;
use Shubo\ShippingShippo\Test\Integration\Helper\TestShippoTransaction;

/**
 * Multi-merchant adapter test (Phase C of session-5 plan).
 *
 * Drives two QuoteRequest DTOs with different merchantId values (1 and 4 —
 * Tikha) and DISTINCT origin addresses. Calls quote() then createShipment()
 * for each. Asserts:
 *   - 2 distinct Shippo transaction object_ids
 *   - 2 distinct tracking_numbers
 *   - DISTINCT address_from on each Shippo transaction (the per-merchant
 *     origin survives the round-trip into Shippo's records)
 *   - No field bleed: merchant 1's tracking_number does NOT appear on the
 *     merchant 4 transaction and vice versa
 *
 * What this test does NOT assert: per-merchant ledger entries in
 * shubo_payout_ledger_entry. That requires the full Magento order chain
 * (commission -> split -> ledger) which requires checkout integration —
 * deferred to Session 6 per architect scope decision §4.
 *
 * @covers \Shubo\ShippingShippo\Model\Adapter\ShippoCarrierGateway
 *
 * @group integration
 */
class ShippoMultiMerchantTest extends TestCase
{
    private const TRACE_FILE = '/tmp/shippo-multi-merchant-trace.json';

    private ShippoCarrierGateway $gateway;
    private InMemoryShippoTransactionRepository $repo;
    private string $apiKey;

    protected function setUp(): void
    {
        $key = ShippoSandboxClient::loadKey();
        if ($key === null) {
            self::markTestSkipped(
                'Shippo sandbox key not available (set SHIPPO_API_KEY env or ~/.shippo-key).',
            );
        }
        if (!ShippoSandboxClient::isSandboxKey($key)) {
            self::fail('Refusing to run integration test against a non-sandbox key.');
        }
        $this->apiKey = $key;

        $config = $this->buildConfig($key);
        $logger = new NullLogger();
        $client = new ShippoClient(
            curlFactory: $this->buildCurlFactory(),
            config: $config,
            logger: $logger,
            sleeper: static function (int $ms): void {
                // no real sleep
            },
        );
        $this->repo = new InMemoryShippoTransactionRepository();
        $this->gateway = new ShippoCarrierGateway(
            client: $client,
            config: $config,
            addressMapper: new AddressMapper(),
            parcelMapper: new ParcelMapper(),
            statusMapper: new StatusMapper($logger),
            txRepo: $this->repo,
            txFactory: $this->buildTxFactory(),
            logger: $logger,
        );
    }

    public function testTwoMerchantsProduceDistinctShippoTransactions(): void
    {
        // Pin both shipments to USPS via preferredCarrierCode. Shippo's sandbox
        // sometimes returns UPS as the cheapest rate, but the sandbox UPS
        // account is not activated by default — purchasing the cheapest UPS
        // rate then errors at the carrier-account level. Pinning to USPS makes
        // the test deterministic against any out-of-the-box Shippo sandbox.
        $preferred = 'usps';

        // Merchant 1 — generic Shubo seller, San Francisco origin
        $req1 = $this->buildShipmentRequest(
            merchantId: 1,
            originStreet: '215 Clayton St',
            originCity: 'San Francisco',
            originState: 'CA',
            originZip: '94117',
            destStreet: '20 W 34th St',
            destCity: 'New York',
            destState: 'NY',
            destZip: '10001',
            clientCode: 'multi-m1-' . bin2hex(random_bytes(4)),
            preferredCarrierCode: $preferred,
        );

        // Merchant 4 — Tikha (showcase merchant per reference_showcase_merchant.md),
        // distinct origin address (Brooklyn) so we can prove address_from differs
        // on the Shippo side.
        $req4 = $this->buildShipmentRequest(
            merchantId: 4,
            originStreet: '50 N 4th St',
            originCity: 'Brooklyn',
            originState: 'NY',
            originZip: '11249',
            destStreet: '233 S Wacker Dr',
            destCity: 'Chicago',
            destState: 'IL',
            destZip: '60606',
            clientCode: 'multi-m4-' . bin2hex(random_bytes(4)),
            preferredCarrierCode: $preferred,
        );

        // Quote both
        $quote1 = $this->gateway->quote($this->toQuoteRequest($req1));
        self::assertNotEmpty($quote1->options, 'Merchant 1 got zero rates: ' . implode(' | ', $quote1->errors));
        $quote4 = $this->gateway->quote($this->toQuoteRequest($req4));
        self::assertNotEmpty($quote4->options, 'Merchant 4 got zero rates: ' . implode(' | ', $quote4->errors));

        // Buy a label for each
        $resp1 = $this->gateway->createShipment($req1);
        $resp4 = $this->gateway->createShipment($req4);

        // Distinct shippo transaction ids
        $row1 = $this->repo->getByTrackingNumber($resp1->carrierTrackingId);
        $row4 = $this->repo->getByTrackingNumber($resp4->carrierTrackingId);
        $tx1Id = $row1->getShippoTransactionId();
        $tx4Id = $row4->getShippoTransactionId();
        self::assertNotEmpty($tx1Id);
        self::assertNotEmpty($tx4Id);
        self::assertNotSame($tx1Id, $tx4Id, 'Shippo returned the same transaction id for two different shipments.');

        // Distinct tracking numbers
        self::assertNotEmpty($resp1->carrierTrackingId);
        self::assertNotEmpty($resp4->carrierTrackingId);
        self::assertNotSame(
            $resp1->carrierTrackingId,
            $resp4->carrierTrackingId,
            'Shippo returned the same tracking number for two different shipments.',
        );

        // Distinct label URLs
        self::assertNotNull($resp1->labelUrl);
        self::assertNotNull($resp4->labelUrl);
        self::assertNotSame($resp1->labelUrl, $resp4->labelUrl);

        // Independent read-back from Shippo to prove distinct address_from on
        // the Shippo-side transaction record (not just our local repo).
        $shippoTx1 = $this->fetchTransactionFromShippo($tx1Id);
        $shippoTx4 = $this->fetchTransactionFromShippo($tx4Id);

        $this->assertAddressFromIsDistinct($shippoTx1, $shippoTx4);

        // No field bleed (defensive check — would catch a really nasty bug
        // where the gateway mutated the wrong row before persisting).
        self::assertSame($resp1->carrierTrackingId, $this->extractTrackingNumber($shippoTx1));
        self::assertSame($resp4->carrierTrackingId, $this->extractTrackingNumber($shippoTx4));
        self::assertNotSame(
            $this->extractTrackingNumber($shippoTx1),
            $this->extractTrackingNumber($shippoTx4),
        );

        $this->writeTrace([
            'merchant_1' => [
                'client_tracking_code' => $req1->clientTrackingCode,
                'shippo_transaction_id' => $tx1Id,
                'tracking_number' => $resp1->carrierTrackingId,
                'origin_city' => $req1->origin->city,
                'origin_zip' => $req1->origin->postcode,
                'label_url' => $resp1->labelUrl,
            ],
            'merchant_4' => [
                'client_tracking_code' => $req4->clientTrackingCode,
                'shippo_transaction_id' => $tx4Id,
                'tracking_number' => $resp4->carrierTrackingId,
                'origin_city' => $req4->origin->city,
                'origin_zip' => $req4->origin->postcode,
                'label_url' => $resp4->labelUrl,
            ],
            'cleanup_required' => true,
        ]);

        // Cleanup: refund both transactions so the sandbox doesn't accumulate
        // throwaway labels (real money charge would be ~$0.05 each on live).
        $this->refundTransaction($tx1Id);
        $this->refundTransaction($tx4Id);
    }

    /**
     * @param array<string, mixed> $tx
     */
    private function extractTrackingNumber(array $tx): string
    {
        return is_string($tx['tracking_number'] ?? null) ? $tx['tracking_number'] : '';
    }

    /**
     * @param array<string, mixed> $tx1
     * @param array<string, mixed> $tx2
     */
    private function assertAddressFromIsDistinct(array $tx1, array $tx2): void
    {
        // Shippo nests the parcel and rate as object_ids; the address_from
        // is similarly an object_id pointer. We dereference both to compare
        // the actual city/zip.
        $rateId1 = is_string($tx1['rate'] ?? null) ? $tx1['rate'] : '';
        $rateId2 = is_string($tx2['rate'] ?? null) ? $tx2['rate'] : '';
        self::assertNotEmpty($rateId1);
        self::assertNotEmpty($rateId2);
        self::assertNotSame($rateId1, $rateId2, 'Both transactions point at the same Shippo rate object.');

        // Pull the rate to get the shipment, then the shipment to get the
        // address_from. (Two API calls per side — acceptable for this guard.)
        $rate1 = $this->fetchFromShippo('/rates/' . $rateId1);
        $rate2 = $this->fetchFromShippo('/rates/' . $rateId2);
        $shipmentId1 = is_string($rate1['shipment'] ?? null) ? $rate1['shipment'] : '';
        $shipmentId2 = is_string($rate2['shipment'] ?? null) ? $rate2['shipment'] : '';
        self::assertNotEmpty($shipmentId1);
        self::assertNotEmpty($shipmentId2);
        self::assertNotSame($shipmentId1, $shipmentId2, 'Both transactions point at the same Shippo shipment object.');

        $shipment1 = $this->fetchFromShippo('/shipments/' . $shipmentId1);
        $shipment2 = $this->fetchFromShippo('/shipments/' . $shipmentId2);
        $from1 = is_array($shipment1['address_from'] ?? null) ? $shipment1['address_from'] : [];
        $from2 = is_array($shipment2['address_from'] ?? null) ? $shipment2['address_from'] : [];
        $city1 = is_string($from1['city'] ?? null) ? $from1['city'] : '';
        $city2 = is_string($from2['city'] ?? null) ? $from2['city'] : '';
        $zip1 = is_string($from1['zip'] ?? null) ? $from1['zip'] : '';
        $zip2 = is_string($from2['zip'] ?? null) ? $from2['zip'] : '';

        self::assertNotEmpty($city1, 'Shippo shipment 1 has empty address_from.city');
        self::assertNotEmpty($city2, 'Shippo shipment 2 has empty address_from.city');
        self::assertNotSame(
            $city1,
            $city2,
            "Shippo address_from.city is identical on both shipments ({$city1}) — per-merchant origin "
            . 'did not survive the round-trip to Shippo.',
        );
        self::assertNotSame(
            $zip1,
            $zip2,
            "Shippo address_from.zip is identical on both shipments ({$zip1}) — per-merchant origin "
            . 'did not survive the round-trip to Shippo.',
        );
    }

    private function buildShipmentRequest(
        int $merchantId,
        string $originStreet,
        string $originCity,
        string $originState,
        string $originZip,
        string $destStreet,
        string $destCity,
        string $destState,
        string $destZip,
        string $clientCode,
        ?string $preferredCarrierCode = null,
    ): ShipmentRequest {
        $origin = new ContactAddress(
            name: 'Merchant ' . $merchantId . ' Origin',
            phone: '+10000000000',
            email: null,
            country: 'US',
            subdivision: $originState,
            city: $originCity,
            district: null,
            street: $originStreet,
            building: null,
            floor: null,
            apartment: null,
            postcode: $originZip,
            latitude: null,
            longitude: null,
            instructions: null,
        );
        $dest = new ContactAddress(
            name: 'Merchant ' . $merchantId . ' Recipient',
            phone: '+10000000000',
            email: null,
            country: 'US',
            subdivision: $destState,
            city: $destCity,
            district: null,
            street: $destStreet,
            building: null,
            floor: null,
            apartment: null,
            postcode: $destZip,
            latitude: null,
            longitude: null,
            instructions: null,
        );
        return new ShipmentRequest(
            orderId: 100_000 + $merchantId,
            merchantId: $merchantId,
            clientTrackingCode: $clientCode,
            origin: $origin,
            destination: $dest,
            parcel: new ParcelSpec(
                weightGrams: 500,
                lengthMm: 200,
                widthMm: 150,
                heightMm: 100,
                declaredValueCents: 2500,
            ),
            codEnabled: false,
            codAmountCents: 0,
            preferredCarrierCode: $preferredCarrierCode,
        );
    }

    private function toQuoteRequest(ShipmentRequest $req): QuoteRequest
    {
        return new QuoteRequest(
            merchantId: $req->merchantId,
            origin: $req->origin,
            destination: $req->destination,
            parcel: $req->parcel,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchTransactionFromShippo(string $objectId): array
    {
        return $this->fetchFromShippo('/transactions/' . $objectId);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchFromShippo(string $path): array
    {
        $ch = curl_init('https://api.goshippo.com' . $path);
        if ($ch === false) {
            self::fail('curl_init failed for ' . $path);
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: ShippoToken ' . $this->apiKey,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            self::fail('Shippo read-back failed for ' . $path . ': ' . $err);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status < 200 || $status >= 300) {
            self::fail('Shippo read-back HTTP ' . $status . ' for ' . $path);
        }
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            self::fail('Shippo read-back returned non-array for ' . $path);
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function refundTransaction(string $transactionId): void
    {
        $ch = curl_init('https://api.goshippo.com/refunds');
        if ($ch === false) {
            return; // best-effort cleanup
        }
        $payload = json_encode(['transaction' => $transactionId]);
        if ($payload === false) {
            curl_close($ch);
            return;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: ShippoToken ' . $this->apiKey,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeTrace(array $data): void
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            file_put_contents(self::TRACE_FILE, $encoded . "\n");
        }
    }

    private function buildConfig(string $apiKey): Config
    {
        return new class ($apiKey) extends Config {
            public function __construct(private readonly string $apiKeyOverride)
            {
                // skip parent
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
                return '';
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
        return new class extends CurlFactory {
            public function __construct()
            {
                // skip parent
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
}

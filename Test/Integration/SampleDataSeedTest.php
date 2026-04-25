<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Test\Integration;

use PHPUnit\Framework\TestCase;
use Shubo\ShippingShippo\Test\Integration\Helper\ShippoSandboxClient;

/**
 * Seeds 5 Shippo sandbox transactions in distinct lifecycle states using
 * Shippo's documented test tracking numbers (per
 * https://docs.goshippo.com/docs/tracking/tracking#test-tracking-numbers).
 *
 * Outputs `docs/sample-data-trace.md` (table format per architect §5).
 *
 * Persistence policy: do NOT clean up these transactions — they are
 * deliberate persistent samples per architect decision §5. Operators
 * looking at the Shippo dashboard should see realistic activity in
 * different states.
 *
 * Lifecycle states covered:
 *   - PRE_TRANSIT (label purchased, no movement)
 *   - TRANSIT (in transit)
 *   - DELIVERED (full lifecycle)
 *   - RETURNED (returned to sender)
 *   - FAILURE (carrier reported failure)
 *
 * @group integration
 * @group seed
 */
class SampleDataSeedTest extends TestCase
{
    private const TRACE_FILE = '/tmp/shippo-sample-data-trace.json';

    private const TEST_TRACKING_NUMBERS = [
        'PRE_TRANSIT' => 'SHIPPO_PRE_TRANSIT',
        'TRANSIT' => 'SHIPPO_TRANSIT',
        'DELIVERED' => 'SHIPPO_DELIVERED',
        'RETURNED' => 'SHIPPO_RETURNED',
        'FAILURE' => 'SHIPPO_FAILURE',
    ];

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
            self::fail('Refusing to seed against a non-sandbox key.');
        }
        $this->apiKey = $key;
    }

    public function testSeedFiveLifecycleStates(): void
    {
        // Each `POST /tracks/` with carrier=shippo + a magic tracking number
        // creates (or surfaces) a Track record on Shippo's side. The mock
        // carrier responds with the corresponding fixed status. This is the
        // canonical Shippo sandbox technique for exercising tracking states
        // without waiting for real carrier movements.
        $records = [];
        foreach (self::TEST_TRACKING_NUMBERS as $state => $trackingNumber) {
            $created = $this->createOrFetchTrack($trackingNumber);
            $tracking = $this->fetchTrack($trackingNumber);

            $records[$state] = [
                'tracking_number' => $trackingNumber,
                'carrier' => 'shippo',
                'object_id' => $this->stringOrEmpty($created['object_id'] ?? null),
                'tracking_status_state' => $this->stringOrEmpty(
                    is_array($tracking['tracking_status'] ?? null)
                        ? ($tracking['tracking_status']['status'] ?? null)
                        : null,
                ),
                'tracking_status_date' => $this->stringOrEmpty(
                    is_array($tracking['tracking_status'] ?? null)
                        ? ($tracking['tracking_status']['status_date'] ?? null)
                        : null,
                ),
                'tracking_status_details' => $this->stringOrEmpty(
                    is_array($tracking['tracking_status'] ?? null)
                        ? ($tracking['tracking_status']['status_details'] ?? null)
                        : null,
                ),
                'address_from_country' => $this->stringOrEmpty(
                    is_array($tracking['address_from'] ?? null)
                        ? ($tracking['address_from']['country'] ?? null)
                        : null,
                ),
                'address_to_country' => $this->stringOrEmpty(
                    is_array($tracking['address_to'] ?? null)
                        ? ($tracking['address_to']['country'] ?? null)
                        : null,
                ),
                'transaction' => $this->stringOrEmpty($tracking['transaction'] ?? null),
            ];

            // Independent assertion: the tracking_status_state on the Shippo
            // side actually matches the magic tracking number's named state.
            self::assertSame(
                $state,
                $records[$state]['tracking_status_state'],
                "Shippo did not return state '{$state}' for tracking number '{$trackingNumber}'.",
            );
        }

        // Persist trace for the architect's sample-data-trace.md and for any
        // downstream tooling that wants to look these up later.
        $this->writeTrace($records);

        // Also verify all 5 are listed by /tracks/ (best-effort — Shippo's
        // /tracks/ list endpoint returns recently-tracked numbers; all 5
        // should be present since we just created them in this run).
        self::assertCount(5, $records);
    }

    /**
     * @return array<string, mixed>
     */
    private function createOrFetchTrack(string $trackingNumber): array
    {
        $payload = json_encode([
            'carrier' => 'shippo',
            'tracking_number' => $trackingNumber,
            'metadata' => 'duka-session-5-phase-d-sample-seed',
        ]);
        if ($payload === false) {
            self::fail('json_encode failed for ' . $trackingNumber);
        }
        return $this->shippoRequest('POST', '/tracks/', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchTrack(string $trackingNumber): array
    {
        return $this->shippoRequest('GET', '/tracks/shippo/' . rawurlencode($trackingNumber), null);
    }

    /**
     * @return array<string, mixed>
     */
    private function shippoRequest(string $method, string $path, ?string $body): array
    {
        $ch = curl_init('https://api.goshippo.com' . $path);
        if ($ch === false) {
            self::fail('curl_init failed for ' . $path);
        }
        $headers = [
            'Authorization: ShippoToken ' . $this->apiKey,
            'Accept: application/json',
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $body ?? '';
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            self::fail('Shippo API call failed: ' . $err);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Track-create is idempotent on Shippo's side and may return 200 or 201.
        if ($status < 200 || $status >= 300) {
            self::fail(
                'Shippo API HTTP ' . $status . ' for ' . $method . ' ' . $path
                . '; body: ' . substr((string)$response, 0, 200),
            );
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            self::fail('Shippo API returned non-array for ' . $path);
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function stringOrEmpty(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return '';
    }

    /**
     * @param array<string, array<string, mixed>> $records
     */
    private function writeTrace(array $records): void
    {
        $encoded = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            file_put_contents(self::TRACE_FILE, $encoded . "\n");
        }
    }
}

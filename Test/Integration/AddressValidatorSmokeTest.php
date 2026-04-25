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
use Shubo\ShippingShippo\Model\Data\Config;
use Shubo\ShippingShippo\Model\Service\AddressMapper;
use Shubo\ShippingShippo\Model\Service\ShippoClient;
use Shubo\ShippingShippo\Service\AddressValidator;
use Shubo\ShippingShippo\Test\Integration\Helper\ShippoSandboxClient;

/**
 * Smoke test for {@see AddressValidator} against the live Shippo sandbox.
 *
 * Mirrors what `bin/magento shipping_shippo:smoke-validate-address` would do
 * if the module were composer-updated into duka. Because Phase B intentionally
 * holds the duka composer.lock bump until reviewer signs off (per architect's
 * scope decision §7), this test is the substitute exercise of the same code
 * path: AddressValidator -> ShippoClient -> live POST /addresses?validate=true.
 *
 * Captured Shippo address object_ids are written to /tmp/shippo-validate-trace.json
 * for the developer-side independent read-back.
 *
 * @covers \Shubo\ShippingShippo\Service\AddressValidator
 *
 * @group integration
 */
class AddressValidatorSmokeTest extends TestCase
{
    private const TRACE_FILE = '/tmp/shippo-validate-trace.json';

    private AddressValidator $validator;

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
        $this->validator = new AddressValidator(
            $client,
            new AddressMapper(),
            $logger,
        );
    }

    public function testKnownGoodAddressReturnsValid(): void
    {
        $whiteHouse = new ContactAddress(
            name: 'Smoke Test',
            phone: '+12024561111',
            email: null,
            country: 'US',
            subdivision: 'DC',
            city: 'Washington',
            district: null,
            street: '1600 Pennsylvania Ave NW',
            building: null,
            floor: null,
            apartment: null,
            postcode: '20500',
            latitude: null,
            longitude: null,
            instructions: null,
        );

        $result = $this->validator->validate($whiteHouse);

        self::assertTrue(
            $result->valid,
            'Expected the White House address to validate. Messages: '
            . implode(' | ', $result->messages),
        );

        $this->appendTrace('known_good', $whiteHouse, $result);
    }

    public function testDeliberatelyWrongAddressReturnsInvalid(): void
    {
        $bogus = new ContactAddress(
            name: 'Smoke Test',
            phone: '+10000000000',
            email: null,
            country: 'XX', // not a real country code
            subdivision: 'ZZ',
            city: 'Nowhere',
            district: null,
            street: '99999 Fakestreet',
            building: null,
            floor: null,
            apartment: null,
            postcode: '00000',
            latitude: null,
            longitude: null,
            instructions: null,
        );

        $result = $this->validator->validate($bogus);

        self::assertFalse(
            $result->valid,
            'Expected the bogus XX/ZZ/Nowhere address to be rejected. '
            . 'Messages: ' . implode(' | ', $result->messages),
        );
        // Either we got a suggestion OR a message — at least one piece of
        // diagnostic should come back from Shippo.
        self::assertTrue(
            $result->suggestion !== null || $result->messages !== [],
            'Expected Shippo to surface either a suggestion or a message for an invalid address.',
        );

        $this->appendTrace('known_bad', $bogus, $result);
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

    private function appendTrace(
        string $label,
        ContactAddress $input,
        \Shubo\ShippingShippo\Api\Data\Dto\AddressValidationResult $result,
    ): void {
        $existing = [];
        if (is_file(self::TRACE_FILE)) {
            $raw = file_get_contents(self::TRACE_FILE);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $existing = $decoded;
                }
            }
        }

        $existing[$label] = [
            'input' => [
                'street' => $input->street,
                'city' => $input->city,
                'state' => $input->subdivision,
                'country' => $input->country,
                'postcode' => $input->postcode,
            ],
            'output' => [
                'valid' => $result->valid,
                'suggestion' => $result->suggestion === null ? null : [
                    'street' => $result->suggestion->street,
                    'city' => $result->suggestion->city,
                    'state' => $result->suggestion->subdivision,
                    'country' => $result->suggestion->country,
                    'postcode' => $result->suggestion->postcode,
                ],
                'messages' => $result->messages,
            ],
        ];

        $encoded = json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            file_put_contents(self::TRACE_FILE, $encoded . "\n");
        }
    }
}

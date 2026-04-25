<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Test\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\ShippingCore\Api\Data\Dto\ContactAddress;
use Shubo\ShippingCore\Exception\NetworkException;
use Shubo\ShippingCore\Exception\ShipmentDispatchFailedException;
use Shubo\ShippingShippo\Model\Service\AddressMapper;
use Shubo\ShippingShippo\Model\Service\ShippoClient;
use Shubo\ShippingShippo\Service\AddressValidator;

/**
 * @covers \Shubo\ShippingShippo\Service\AddressValidator
 */
class AddressValidatorTest extends TestCase
{
    private ShippoClient&MockObject $client;
    private LoggerInterface&MockObject $logger;
    private AddressValidator $validator;

    protected function setUp(): void
    {
        $this->client = $this->createMock(ShippoClient::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->validator = new AddressValidator(
            $this->client,
            new AddressMapper(),
            $this->logger,
        );
    }

    public function testValidAddressReturnsValidWithoutSuggestion(): void
    {
        $this->client->expects(self::once())
            ->method('validateAddress')
            ->willReturn([
                'object_id' => 'addr_valid',
                'street1' => '1600 Pennsylvania Ave NW',
                'city' => 'Washington',
                'state' => 'DC',
                'zip' => '20500',
                'country' => 'US',
                'validation_results' => [
                    'is_valid' => true,
                    'messages' => [],
                ],
            ]);

        $result = $this->validator->validate($this->whiteHouseAddress());

        self::assertTrue($result->valid);
        self::assertNull($result->suggestion);
        self::assertSame([], $result->messages);
    }

    public function testInvalidAddressWithSuggestionReturnsCorrectedAddress(): void
    {
        $this->client->expects(self::once())
            ->method('validateAddress')
            ->willReturn([
                'object_id' => 'addr_invalid',
                'street1' => '1600 PENNSYLVANIA AVE NW',
                'city' => 'WASHINGTON',
                'state' => 'DC',
                'zip' => '20500-0003',
                'country' => 'US',
                'validation_results' => [
                    'is_valid' => false,
                    'messages' => [
                        ['source' => 'USPS', 'code' => 'D2200', 'text' => 'Did you mean 1600 PENNSYLVANIA AVE NW?'],
                    ],
                ],
            ]);

        $original = new ContactAddress(
            name: 'Test Recipient',
            phone: '+12025550100',
            email: null,
            country: 'US',
            subdivision: 'DC',
            city: 'Washington',
            district: null,
            street: '1600 Pennsy Ave',
            building: null,
            floor: null,
            apartment: null,
            postcode: '20500',
            latitude: null,
            longitude: null,
            instructions: null,
        );

        $result = $this->validator->validate($original);

        self::assertFalse($result->valid);
        self::assertNotNull($result->suggestion);
        self::assertSame('1600 PENNSYLVANIA AVE NW', $result->suggestion->street);
        self::assertSame('20500-0003', $result->suggestion->postcode);
        self::assertSame('Test Recipient', $result->suggestion->name); // preserved
        self::assertSame(['Did you mean 1600 PENNSYLVANIA AVE NW?'], $result->messages);
    }

    public function testInvalidAddressWithoutSuggestionReturnsNullSuggestion(): void
    {
        $this->client->expects(self::once())
            ->method('validateAddress')
            ->willReturn([
                'object_id' => 'addr_invalid_nosug',
                // Shippo did not echo any corrected address fields.
                'validation_results' => [
                    'is_valid' => false,
                    'messages' => [
                        ['source' => 'USPS', 'code' => 'E0000', 'text' => 'Address not found in USPS database'],
                    ],
                ],
            ]);

        $result = $this->validator->validate($this->madeUpAddress());

        self::assertFalse($result->valid);
        self::assertNull($result->suggestion);
        self::assertSame(['Address not found in USPS database'], $result->messages);
    }

    public function testShippoTransientErrorFailsOpenWithLoggedWarning(): void
    {
        $this->client->expects(self::once())
            ->method('validateAddress')
            ->willThrowException(new NetworkException(__('curl error: connection refused')));

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                'Shippo address validation transport error — failing open',
                self::callback(static function (array $context): bool {
                    return isset($context['exception'])
                        && is_string($context['exception'])
                        && str_contains($context['exception'], 'connection refused');
                }),
            );

        $result = $this->validator->validate($this->whiteHouseAddress());

        self::assertTrue($result->valid, 'Validator must fail open on Shippo transient errors.');
        self::assertNull($result->suggestion);
        self::assertNotEmpty($result->messages);
        self::assertStringContainsString('unavailable', $result->messages[0]);
    }

    public function testShippoFourHundredReturnsInvalidWithMessage(): void
    {
        // Shippo rejects structurally-malformed payloads (unknown country code,
        // missing required field, etc.) at HTTP 400 — that's a valid=false
        // signal, NOT a fail-open situation, because the customer cannot be
        // charged for shipping to a country Shippo refuses to acknowledge.
        $this->client->expects(self::once())
            ->method('validateAddress')
            ->willThrowException(new ShipmentDispatchFailedException(
                __('Shippo request rejected: %1', '{"country":["Invalid value specified for country \'XX\'"]}'),
            ));

        $this->logger->expects(self::once())
            ->method('info')
            ->with(
                'Shippo address validation rejected payload at HTTP layer',
                self::callback(static function (array $context): bool {
                    return isset($context['exception'])
                        && is_string($context['exception'])
                        && str_contains($context['exception'], 'Invalid value');
                }),
            );

        $result = $this->validator->validate($this->madeUpAddress());

        self::assertFalse($result->valid);
        self::assertNull($result->suggestion);
        self::assertCount(1, $result->messages);
        self::assertStringContainsString('Invalid value', $result->messages[0]);
    }

    private function whiteHouseAddress(): ContactAddress
    {
        return new ContactAddress(
            name: 'POTUS',
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
    }

    private function madeUpAddress(): ContactAddress
    {
        return new ContactAddress(
            name: 'Nobody',
            phone: '+10000000000',
            email: null,
            country: 'XX',
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
    }
}

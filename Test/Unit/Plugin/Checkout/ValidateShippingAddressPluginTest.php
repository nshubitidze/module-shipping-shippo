<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Test\Unit\Plugin\Checkout;

use Magento\Checkout\Api\Data\PaymentDetailsInterface;
use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Api\ShippingInformationManagementInterface;
use Magento\Quote\Api\Data\AddressInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\ShippingCore\Api\Data\Dto\ContactAddress;
use Shubo\ShippingShippo\Api\Data\Dto\AddressValidationResult;
use Shubo\ShippingShippo\Plugin\Checkout\ValidateShippingAddressPlugin;
use Shubo\ShippingShippo\Service\AddressValidator;

/**
 * @covers \Shubo\ShippingShippo\Plugin\Checkout\ValidateShippingAddressPlugin
 */
class ValidateShippingAddressPluginTest extends TestCase
{
    private AddressValidator&MockObject $validator;
    private LoggerInterface&MockObject $logger;
    private ValidateShippingAddressPlugin $plugin;
    private ShippingInformationManagementInterface&MockObject $subject;
    private PaymentDetailsInterface&MockObject $result;

    protected function setUp(): void
    {
        $this->validator = $this->createMock(AddressValidator::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->plugin = new ValidateShippingAddressPlugin($this->validator, $this->logger);

        $this->subject = $this->createMock(ShippingInformationManagementInterface::class);
        $this->result = $this->createMock(PaymentDetailsInterface::class);
    }

    public function testReturnsResultUntouchedWhenValidatorAccepts(): void
    {
        $this->validator->expects(self::once())
            ->method('validate')
            ->with(self::isInstanceOf(ContactAddress::class))
            ->willReturn(new AddressValidationResult(valid: true, suggestion: null, messages: []));

        // No log entry on success.
        $this->logger->expects(self::never())->method('notice');
        $this->logger->expects(self::never())->method('warning');
        $this->logger->expects(self::never())->method('error');

        $returned = $this->plugin->afterSaveAddressInformation(
            $this->subject,
            $this->result,
            42,
            $this->buildShippingInfo($this->buildAddress()),
        );

        self::assertSame($this->result, $returned, 'Plugin must return the original PaymentDetails.');
    }

    public function testLogsNoticeAndPassesThroughWhenValidatorRejects(): void
    {
        $suggestion = new ContactAddress(
            name: '',
            phone: '',
            email: null,
            country: 'US',
            subdivision: 'DC',
            city: 'WASHINGTON',
            district: null,
            street: '1600 PENNSYLVANIA AVE NW',
            building: null,
            floor: null,
            apartment: null,
            postcode: '20500-0003',
            latitude: null,
            longitude: null,
            instructions: null,
        );

        $this->validator->expects(self::once())
            ->method('validate')
            ->willReturn(new AddressValidationResult(
                valid: false,
                suggestion: $suggestion,
                messages: ['Did you mean 1600 PENNSYLVANIA AVE NW?'],
            ));

        $this->logger->expects(self::once())
            ->method('notice')
            ->with(
                'Shippo address validation returned invalid — checkout allowed (non-blocking)',
                self::callback(static function (array $ctx): bool {
                    return ($ctx['cart_id'] ?? null) === 42
                        && ($ctx['messages'] ?? null) === ['Did you mean 1600 PENNSYLVANIA AVE NW?']
                        && isset($ctx['suggestion']['street'])
                        && $ctx['suggestion']['street'] === '1600 PENNSYLVANIA AVE NW';
                }),
            );

        $returned = $this->plugin->afterSaveAddressInformation(
            $this->subject,
            $this->result,
            42,
            $this->buildShippingInfo($this->buildAddress()),
        );

        self::assertSame($this->result, $returned);
    }

    public function testLogsNoticeWithoutSuggestionWhenInvalidAndUncorrectable(): void
    {
        $this->validator->method('validate')->willReturn(
            new AddressValidationResult(
                valid: false,
                suggestion: null,
                messages: ['Address not found in USPS database'],
            ),
        );

        $this->logger->expects(self::once())
            ->method('notice')
            ->with(
                'Shippo address validation returned invalid — checkout allowed (non-blocking)',
                self::callback(static function (array $ctx): bool {
                    return ($ctx['cart_id'] ?? null) === 7
                        && !array_key_exists('suggestion', $ctx)
                        && ($ctx['messages'] ?? null) === ['Address not found in USPS database'];
                }),
            );

        $returned = $this->plugin->afterSaveAddressInformation(
            $this->subject,
            $this->result,
            7,
            $this->buildShippingInfo($this->buildAddress()),
        );

        self::assertSame($this->result, $returned);
    }

    public function testSwallowsAndLogsWhenValidatorThrows(): void
    {
        $this->validator->method('validate')
            ->willThrowException(new \RuntimeException('shippo broken'));

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                'ValidateShippingAddressPlugin: validation call failed, passing through',
                self::callback(static function (array $ctx): bool {
                    return ($ctx['cart_id'] ?? null) === 99
                        && ($ctx['error'] ?? null) === 'shippo broken';
                }),
            );

        // Plugin must NEVER throw — checkout cannot be gated by an internal validator bug.
        $returned = $this->plugin->afterSaveAddressInformation(
            $this->subject,
            $this->result,
            99,
            $this->buildShippingInfo($this->buildAddress()),
        );

        self::assertSame($this->result, $returned);
    }

    public function testHandlesArrayStreetByJoiningWithSpaces(): void
    {
        $address = $this->createMock(AddressInterface::class);
        $address->method('getStreet')->willReturn(['1600 Pennsylvania Ave', 'NW']);
        $address->method('getCountryId')->willReturn('US');
        $address->method('getRegionCode')->willReturn('DC');
        $address->method('getCity')->willReturn('Washington');
        $address->method('getPostcode')->willReturn('20500');

        $this->validator->expects(self::once())
            ->method('validate')
            ->with(self::callback(static function (ContactAddress $contact): bool {
                return $contact->street === '1600 Pennsylvania Ave NW'
                    && $contact->country === 'US'
                    && $contact->subdivision === 'DC'
                    && $contact->city === 'Washington'
                    && $contact->postcode === '20500';
            }))
            ->willReturn(new AddressValidationResult(valid: true, suggestion: null, messages: []));

        $this->plugin->afterSaveAddressInformation(
            $this->subject,
            $this->result,
            1,
            $this->buildShippingInfo($address),
        );
    }

    private function buildAddress(): AddressInterface&MockObject
    {
        $address = $this->createMock(AddressInterface::class);
        $address->method('getStreet')->willReturn(['1600 Pennsylvania Ave NW']);
        $address->method('getCountryId')->willReturn('US');
        $address->method('getRegionCode')->willReturn('DC');
        $address->method('getCity')->willReturn('Washington');
        $address->method('getPostcode')->willReturn('20500');
        return $address;
    }

    private function buildShippingInfo(AddressInterface $address): ShippingInformationInterface&MockObject
    {
        $info = $this->createMock(ShippingInformationInterface::class);
        $info->method('getShippingAddress')->willReturn($address);
        return $info;
    }
}

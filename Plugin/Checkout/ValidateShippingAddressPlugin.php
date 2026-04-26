<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Plugin\Checkout;

use Magento\Checkout\Api\Data\PaymentDetailsInterface;
use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Api\ShippingInformationManagementInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Psr\Log\LoggerInterface;
use Shubo\ShippingCore\Api\Data\Dto\ContactAddress;
use Shubo\ShippingShippo\Service\AddressValidator;

/**
 * After the customer saves shipping information at checkout, runs Shippo's
 * address-validation lane on the destination so we can warn (in the log) on
 * undeliverable addresses before the order is placed.
 *
 * Strict non-blocking contract: this plugin NEVER throws and NEVER mutates
 * the result. Address validation here is a quality-of-service guard, not a
 * gate — the AddressValidator service itself fails open on transport errors
 * (see its docblock), and the plugin keeps the same posture: any failure
 * (including a `valid=false` from Shippo) is logged for the operator and
 * the customer is allowed to proceed. The actual rate quote in the
 * subsequent `estimate-shipping-methods` call is the real gate.
 */
class ValidateShippingAddressPlugin
{
    public function __construct(
        private readonly AddressValidator $validator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param ShippingInformationManagementInterface $subject
     * @param PaymentDetailsInterface $result
     * @param int $cartId
     * @param ShippingInformationInterface $addressInformation
     * @return PaymentDetailsInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterSaveAddressInformation(
        ShippingInformationManagementInterface $subject,
        PaymentDetailsInterface $result,
        int $cartId,
        ShippingInformationInterface $addressInformation,
    ): PaymentDetailsInterface {
        try {
            $shippingAddress = $addressInformation->getShippingAddress();
            if (!$shippingAddress instanceof AddressInterface) {
                return $result;
            }

            $address = $this->buildContactAddress($shippingAddress);
            $validationResult = $this->validator->validate($address);

            if (!$validationResult->valid) {
                $context = [
                    'cart_id' => $cartId,
                    'messages' => $validationResult->messages,
                ];
                if ($validationResult->suggestion !== null) {
                    $context['suggestion'] = [
                        'street' => $validationResult->suggestion->street,
                        'city' => $validationResult->suggestion->city,
                        'subdivision' => $validationResult->suggestion->subdivision,
                        'postcode' => $validationResult->suggestion->postcode,
                        'country' => $validationResult->suggestion->country,
                    ];
                }
                $this->logger->notice(
                    'Shippo address validation returned invalid — checkout allowed (non-blocking)',
                    $context,
                );
            }
        } catch (\Throwable $e) {
            // Never block checkout due to address validation errors.
            $this->logger->warning(
                'ValidateShippingAddressPlugin: validation call failed, passing through',
                ['cart_id' => $cartId, 'error' => $e->getMessage()],
            );
        }

        return $result;
    }

    /**
     * Map a Magento quote AddressInterface onto the ShippingCore ContactAddress
     * DTO that the validator expects. Most Shippo address-validation fields
     * (name, phone, email) are immaterial to USPS's lookup, so we pass empty
     * strings rather than dragging customer PII through the validation log.
     */
    private function buildContactAddress(AddressInterface $address): ContactAddress
    {
        $street = $address->getStreet();
        $streetLine = is_array($street) ? trim(implode(' ', $street)) : '';

        $postcode = $address->getPostcode();
        $postcodeStr = is_string($postcode) && $postcode !== '' ? $postcode : null;

        return new ContactAddress(
            name: '',
            phone: '',
            email: null,
            country: $this->stringOrEmpty($address->getCountryId()),
            subdivision: $this->stringOrEmpty($address->getRegionCode()),
            city: $this->stringOrEmpty($address->getCity()),
            district: null,
            street: $streetLine,
            building: null,
            floor: null,
            apartment: null,
            postcode: $postcodeStr,
            latitude: null,
            longitude: null,
            instructions: null,
        );
    }

    private function stringOrEmpty(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}

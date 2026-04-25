<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Service;

use Psr\Log\LoggerInterface;
use Shubo\ShippingCore\Api\Data\Dto\ContactAddress;
use Shubo\ShippingCore\Exception\AuthException;
use Shubo\ShippingCore\Exception\NetworkException;
use Shubo\ShippingCore\Exception\ShipmentDispatchFailedException;
use Shubo\ShippingCore\Exception\TransientHttpException;
use Shubo\ShippingShippo\Api\Data\Dto\AddressValidationResult;
use Shubo\ShippingShippo\Model\Service\AddressMapper;
use Shubo\ShippingShippo\Model\Service\ShippoClient;

/**
 * Wraps Shippo `POST /addresses` with `validate=true` query — Shippo's
 * documented address-validation lane. The addresses-create endpoint
 * returns the same envelope (`object_id`, `is_complete`,
 * `validation_results.{is_valid, messages[]}`) regardless of whether
 * the address was rejected, accepted as-is, or corrected.
 *
 * Failure philosophy (fail-open): when Shippo is unhealthy (5xx /
 * network / auth) we return `valid=true` with an explanatory message
 * and a logged warning. Address validation is a quality-of-service
 * guard, not a security gate; degrading shipping availability when
 * Shippo's validate endpoint is down would compound the outage. This
 * mirrors the BOG/TBC payment modules' resilience pattern (warn, do
 * not block).
 *
 * Justification doc: docs/address-validator-justification.md.
 */
class AddressValidator
{
    private const FAIL_OPEN_MESSAGE = 'Shippo address validation unavailable; accepting address as-is.';

    public function __construct(
        private readonly ShippoClient $client,
        private readonly AddressMapper $addressMapper,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function validate(ContactAddress $address): AddressValidationResult
    {
        $payload = $this->addressMapper->toShippoPayload($address);

        try {
            $response = $this->client->validateAddress($payload);
        } catch (NetworkException | TransientHttpException $e) {
            $this->logger->warning(
                'Shippo address validation transport error — failing open',
                ['exception' => $e->getMessage()],
            );
            return new AddressValidationResult(
                valid: true,
                suggestion: null,
                messages: [self::FAIL_OPEN_MESSAGE],
            );
        } catch (AuthException $e) {
            // An auth failure is a configuration error, NOT a runtime carrier
            // outage — log it loudly and fail open so the customer can still
            // checkout, but the operator gets a clear signal in the logs.
            $this->logger->error(
                'Shippo address validation rejected our credentials — failing open',
                ['exception' => $e->getMessage()],
            );
            return new AddressValidationResult(
                valid: true,
                suggestion: null,
                messages: [self::FAIL_OPEN_MESSAGE],
            );
        } catch (ShipmentDispatchFailedException $e) {
            // Shippo returned a 4xx, which for the addresses-validate endpoint
            // means "the input is structurally malformed" (unknown country code,
            // missing required field, etc.). That is itself a `valid=false`
            // signal — surface the HTTP message to the caller and DO NOT fail
            // open: the customer cannot be charged for shipping to a country
            // Shippo refuses to acknowledge.
            $this->logger->info(
                'Shippo address validation rejected payload at HTTP layer',
                ['exception' => $e->getMessage()],
            );
            return new AddressValidationResult(
                valid: false,
                suggestion: null,
                messages: [$e->getMessage()],
            );
        }

        $validationResults = is_array($response['validation_results'] ?? null)
            ? $response['validation_results']
            : [];
        $isValid = $this->extractBool($validationResults, 'is_valid');
        $messages = $this->extractMessages($validationResults);
        $suggestion = $isValid ? null : $this->maybeBuildSuggestion($address, $response);

        return new AddressValidationResult(
            valid: $isValid,
            suggestion: $suggestion,
            messages: $messages,
        );
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function extractBool(array $envelope, string $key): bool
    {
        $value = $envelope[$key] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes'], true);
        }
        if (is_int($value)) {
            return $value === 1;
        }
        return false;
    }

    /**
     * @param array<string, mixed> $envelope
     * @return list<string>
     */
    private function extractMessages(array $envelope): array
    {
        $raw = $envelope['messages'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $entry) {
            if (is_array($entry) && isset($entry['text']) && is_string($entry['text']) && $entry['text'] !== '') {
                $out[] = $entry['text'];
                continue;
            }
            if (is_string($entry) && $entry !== '') {
                $out[] = $entry;
            }
        }
        // Preserve order, drop duplicates so the caller does not see the same
        // "Street name unknown" twice.
        return array_values(array_unique($out));
    }

    /**
     * Build a suggestion ContactAddress from Shippo's corrected fields. Shippo
     * returns the cleaned address fields at the top level of the response (the
     * same shape as the request payload, minus `validate`), so we can lift the
     * fields directly. We only build a suggestion when Shippo actually changed
     * something — if every field round-trips identically, there's nothing to
     * suggest.
     *
     * @param array<string, mixed> $response
     */
    private function maybeBuildSuggestion(ContactAddress $original, array $response): ?ContactAddress
    {
        $street1 = $this->stringField($response, 'street1');
        $street2 = $this->stringField($response, 'street2');
        $city = $this->stringField($response, 'city');
        $state = $this->stringField($response, 'state');
        $zip = $this->stringField($response, 'zip');
        $country = $this->stringField($response, 'country');

        if ($street1 === '' && $city === '' && $state === '' && $zip === '' && $country === '') {
            // Shippo returned no corrected fields — no suggestion to surface.
            return null;
        }

        // If Shippo's payload matches the original byte-for-byte, suggesting
        // the same address back to the user is noise. Detect that.
        $unchanged = $street1 === $original->street
            && $city === $original->city
            && $state === $original->subdivision
            && $zip === ($original->postcode ?? '')
            && $country === $original->country;
        if ($unchanged) {
            return null;
        }

        return new ContactAddress(
            name: $original->name,
            phone: $original->phone,
            email: $original->email,
            country: $country !== '' ? $country : $original->country,
            subdivision: $state !== '' ? $state : $original->subdivision,
            city: $city !== '' ? $city : $original->city,
            district: $original->district,
            street: $street1 !== '' ? $street1 : $original->street,
            building: $original->building,
            floor: $original->floor,
            apartment: $street2 !== '' ? $street2 : $original->apartment,
            postcode: $zip !== '' ? $zip : $original->postcode,
            latitude: $original->latitude,
            longitude: $original->longitude,
            instructions: $original->instructions,
        );
    }

    /**
     * @param array<string, mixed> $response
     */
    private function stringField(array $response, string $key): string
    {
        $value = $response[$key] ?? null;
        return is_string($value) ? $value : '';
    }
}

<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Console\Command;

use Shubo\ShippingCore\Api\CarrierGatewayInterface;
use Shubo\ShippingCore\Api\Data\Dto\ContactAddress;
use Shubo\ShippingCore\Api\Data\Dto\ParcelSpec;
use Shubo\ShippingCore\Api\Data\Dto\QuoteRequest;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI smoke test: builds a minimal QuoteRequest and prints the carrier's
 * rate table. Used to verify the API key + sandbox connectivity without
 * going through the orchestrator.
 *
 * Example:
 *   bin/magento shipping_shippo:smoke-rate \
 *     --from-country=US --from-zip=94107 \
 *     --to-country=DE --to-zip=10115 --weight-kg=1.2
 */
class SmokeRateCommand extends Command
{
    public const NAME = 'shipping_shippo:smoke-rate';

    private const OPT_FROM_COUNTRY = 'from-country';
    private const OPT_FROM_STATE = 'from-state';
    private const OPT_FROM_CITY = 'from-city';
    private const OPT_FROM_ZIP = 'from-zip';
    private const OPT_TO_COUNTRY = 'to-country';
    private const OPT_TO_STATE = 'to-state';
    private const OPT_TO_CITY = 'to-city';
    private const OPT_TO_ZIP = 'to-zip';
    private const OPT_WEIGHT_KG = 'weight-kg';

    public function __construct(
        private readonly CarrierGatewayInterface $gateway,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::NAME);
        $this->setDescription('Fetch a live rate quote from Shippo for a minimal synthetic shipment.');
        $this->addOption(self::OPT_FROM_COUNTRY, null, InputOption::VALUE_REQUIRED, 'Origin ISO-2 country', 'US');
        $this->addOption(self::OPT_FROM_STATE, null, InputOption::VALUE_REQUIRED, 'Origin state/subdivision', 'CA');
        $this->addOption(self::OPT_FROM_CITY, null, InputOption::VALUE_REQUIRED, 'Origin city', 'San Francisco');
        $this->addOption(self::OPT_FROM_ZIP, null, InputOption::VALUE_REQUIRED, 'Origin postal code', '94107');
        $this->addOption(self::OPT_TO_COUNTRY, null, InputOption::VALUE_REQUIRED, 'Destination ISO-2 country', 'US');
        $this->addOption(self::OPT_TO_STATE, null, InputOption::VALUE_REQUIRED, 'Destination state/subdivision', 'NY');
        $this->addOption(self::OPT_TO_CITY, null, InputOption::VALUE_REQUIRED, 'Destination city', 'New York');
        $this->addOption(self::OPT_TO_ZIP, null, InputOption::VALUE_REQUIRED, 'Destination postal code', '10001');
        $this->addOption(self::OPT_WEIGHT_KG, null, InputOption::VALUE_REQUIRED, 'Parcel weight in kg', '1.0');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fromCountry = (string)$input->getOption(self::OPT_FROM_COUNTRY);
        $fromState = (string)$input->getOption(self::OPT_FROM_STATE);
        $fromCity = (string)$input->getOption(self::OPT_FROM_CITY);
        $fromZip = (string)$input->getOption(self::OPT_FROM_ZIP);
        $toCountry = (string)$input->getOption(self::OPT_TO_COUNTRY);
        $toState = (string)$input->getOption(self::OPT_TO_STATE);
        $toCity = (string)$input->getOption(self::OPT_TO_CITY);
        $toZip = (string)$input->getOption(self::OPT_TO_ZIP);
        $weightKg = (string)$input->getOption(self::OPT_WEIGHT_KG);

        $weightGrams = (int)bcmul($weightKg, '1000', 0);
        if ($weightGrams <= 0) {
            $weightGrams = 100;
        }

        $request = new QuoteRequest(
            merchantId: 0,
            origin: $this->syntheticAddress($fromCountry, $fromState, $fromCity, $fromZip, 'Shubo Sender'),
            destination: $this->syntheticAddress($toCountry, $toState, $toCity, $toZip, 'Shubo Receiver'),
            parcel: new ParcelSpec(
                weightGrams: $weightGrams,
                lengthMm: 200,
                widthMm: 150,
                heightMm: 100,
                declaredValueCents: 5000,
            ),
        );

        $response = $this->gateway->quote($request);

        if ($response->options === []) {
            $output->writeln('<error>No rates returned.</error>');
            foreach ($response->errors as $err) {
                $output->writeln(' - ' . $err);
            }
            return 1;
        }

        $output->writeln(
            str_pad('Provider', 16)
            . str_pad('Method', 28)
            . str_pad('Price', 10)
            . str_pad('ETA', 5)
            . 'Rationale',
        );
        $output->writeln(str_repeat('-', 80));
        foreach ($response->options as $opt) {
            $price = bcdiv((string)$opt->priceCents, '100', 2);
            $output->writeln(
                str_pad($opt->carrierCode, 16)
                . str_pad($opt->methodCode, 28)
                . str_pad($price, 10)
                . str_pad((string)$opt->etaDays . 'd', 5)
                . $opt->rationale,
            );
        }
        return 0;
    }

    private function syntheticAddress(
        string $country,
        string $state,
        string $city,
        string $zip,
        string $name,
    ): ContactAddress {
        return new ContactAddress(
            name: $name,
            phone: '+10000000000',
            email: null,
            country: $country,
            subdivision: $state,
            city: $city,
            district: null,
            street: '1 Main Street',
            building: null,
            floor: null,
            apartment: null,
            postcode: $zip,
            latitude: null,
            longitude: null,
            instructions: null,
        );
    }
}

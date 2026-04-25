<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Console\Command;

use Shubo\ShippingCore\Api\Data\Dto\ContactAddress;
use Shubo\ShippingShippo\Service\AddressValidator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI smoke test for the address validator: prints Shippo's verdict and any
 * suggestion for a synthetic address. Mirrors {@see SmokeRateCommand}.
 *
 * Examples:
 *   bin/magento shipping_shippo:smoke-validate-address \
 *     --street="1600 Pennsylvania Ave NW" --city=Washington \
 *     --state=DC --country=US --postcode=20500
 *
 *   bin/magento shipping_shippo:smoke-validate-address \
 *     --street="99999 Fakestreet" --city=Nowhere \
 *     --state=ZZ --country=XX --postcode=00000
 *
 * Used to verify the API key + sandbox connectivity for the address-validate
 * lane without going through the orchestrator or the full integration test.
 */
class SmokeValidateAddressCommand extends Command
{
    public const NAME = 'shipping_shippo:smoke-validate-address';

    private const OPT_STREET = 'street';
    private const OPT_CITY = 'city';
    private const OPT_STATE = 'state';
    private const OPT_COUNTRY = 'country';
    private const OPT_POSTCODE = 'postcode';
    private const OPT_NAME = 'name';
    private const OPT_PHONE = 'phone';

    public function __construct(
        private readonly AddressValidator $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::NAME);
        $this->setDescription('Validate a synthetic address against Shippo and print the result.');
        $this->addOption(self::OPT_STREET, null, InputOption::VALUE_REQUIRED, 'Street line', '1600 Pennsylvania Ave NW');
        $this->addOption(self::OPT_CITY, null, InputOption::VALUE_REQUIRED, 'City', 'Washington');
        $this->addOption(self::OPT_STATE, null, InputOption::VALUE_REQUIRED, 'State / subdivision', 'DC');
        $this->addOption(self::OPT_COUNTRY, null, InputOption::VALUE_REQUIRED, 'ISO-2 country', 'US');
        $this->addOption(self::OPT_POSTCODE, null, InputOption::VALUE_REQUIRED, 'Postal code', '20500');
        $this->addOption(self::OPT_NAME, null, InputOption::VALUE_REQUIRED, 'Recipient name', 'Smoke Test');
        $this->addOption(self::OPT_PHONE, null, InputOption::VALUE_REQUIRED, 'Phone number', '+10000000000');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $address = new ContactAddress(
            name: (string)$input->getOption(self::OPT_NAME),
            phone: (string)$input->getOption(self::OPT_PHONE),
            email: null,
            country: (string)$input->getOption(self::OPT_COUNTRY),
            subdivision: (string)$input->getOption(self::OPT_STATE),
            city: (string)$input->getOption(self::OPT_CITY),
            district: null,
            street: (string)$input->getOption(self::OPT_STREET),
            building: null,
            floor: null,
            apartment: null,
            postcode: (string)$input->getOption(self::OPT_POSTCODE),
            latitude: null,
            longitude: null,
            instructions: null,
        );

        $result = $this->validator->validate($address);

        $output->writeln('valid:       ' . ($result->valid ? 'true' : 'false'));
        if ($result->suggestion !== null) {
            $sug = $result->suggestion;
            $output->writeln('suggestion:');
            $output->writeln('  street:    ' . $sug->street);
            $output->writeln('  city:      ' . $sug->city);
            $output->writeln('  state:     ' . $sug->subdivision);
            $output->writeln('  postcode:  ' . ($sug->postcode ?? ''));
            $output->writeln('  country:   ' . $sug->country);
        } else {
            $output->writeln('suggestion:  (none)');
        }
        if ($result->messages === []) {
            $output->writeln('messages:    (none)');
        } else {
            $output->writeln('messages:');
            foreach ($result->messages as $msg) {
                $output->writeln('  - ' . $msg);
            }
        }
        return $result->valid ? 0 : 1;
    }
}

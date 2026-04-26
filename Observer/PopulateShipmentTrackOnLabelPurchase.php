<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\ShipmentTrackInterface;
use Magento\Sales\Api\ShipmentTrackRepositoryInterface;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Track\CollectionFactory as TrackCollectionFactory;
use Psr\Log\LoggerInterface;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingShippo\Api\ShippoTransactionRepositoryInterface;

/**
 * Listens to `shubo_shipping_shipment_dispatched` and creates a Magento
 * `sales_shipment_track` row pointing at the carrier's public tracking
 * page so admins (and customer e-mails) can deep-link to live tracking.
 *
 * Strict no-op contract: any failure is logged and swallowed. The event
 * dispatcher ignores observer return values, but the upstream label
 * purchase has already succeeded by the time we run — throwing here
 * would not undo it and would only spam the order log.
 *
 * Idempotency: the observer queries the track collection by
 * (parent_id, number) before inserting so duplicate event fires (or a
 * manual re-dispatch from admin) do not create stacked rows.
 */
class PopulateShipmentTrackOnLabelPurchase implements ObserverInterface
{
    public function __construct(
        private readonly ShippoTransactionRepositoryInterface $txRepo,
        private readonly TrackFactory $trackFactory,
        private readonly ShipmentTrackRepositoryInterface $trackRepository,
        private readonly TrackCollectionFactory $trackCollectionFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $shipment = $event->getData('shipment');

        if (!$shipment instanceof ShipmentInterface) {
            return;
        }

        if ($shipment->getCarrierCode() !== 'shippo') {
            return;
        }

        $magentoShipmentId = $shipment->getMagentoShipmentId();
        if ($magentoShipmentId === null || $magentoShipmentId === 0) {
            $this->logger->warning(
                'PopulateShipmentTrackOnLabelPurchase: no Magento shipment id, skipping track creation',
                ['shubo_shipment_id' => $shipment->getShipmentId()],
            );
            return;
        }

        $trackingNumber = $shipment->getCarrierTrackingId();
        if ($trackingNumber === null || $trackingNumber === '') {
            return;
        }

        // Idempotency: skip if a track with this number already exists on this shipment.
        // addFieldToFilter() second arg is array|string|null per the AbstractDb signature,
        // so wrap the int parent_id in the documented `eq` filter form.
        $existing = $this->trackCollectionFactory->create()
            ->addFieldToFilter('parent_id', ['eq' => $magentoShipmentId])
            ->addFieldToFilter('number', $trackingNumber);
        if ($existing->getSize() > 0) {
            return;
        }

        try {
            $tx = $this->txRepo->getByTrackingNumber($trackingNumber);
            $carrier = $tx->getCarrier();
        } catch (NoSuchEntityException) {
            $carrier = '';
        }

        $title = $carrier !== '' ? sprintf('%s via Shippo', strtoupper($carrier)) : 'Shippo';

        /** @var ShipmentTrackInterface $track */
        $track = $this->trackFactory->create();
        $track->setParentId($magentoShipmentId);
        $track->setCarrierCode('shippo');
        $track->setTitle($title);
        $track->setTrackNumber($trackingNumber);
        if ($carrier !== '') {
            // Only set a deep-link description when we know the carrier — otherwise
            // we would emit a broken Shippo tracker URL with an empty path segment.
            $track->setDescription($this->buildTrackingUrl($carrier, $trackingNumber));
        }

        try {
            $this->trackRepository->save($track);
            $this->logger->info(
                'PopulateShipmentTrackOnLabelPurchase: track row created',
                [
                    'magento_shipment_id' => $magentoShipmentId,
                    'tracking_number' => $trackingNumber,
                    'carrier' => $carrier,
                ],
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'PopulateShipmentTrackOnLabelPurchase: failed to save track',
                [
                    'magento_shipment_id' => $magentoShipmentId,
                    'tracking_number' => $trackingNumber,
                    'error' => $e->getMessage(),
                ],
            );
        }
    }

    /**
     * Build a deep-link tracking URL for the four major carriers we expect to
     * see via Shippo. Anything else falls back to Shippo's own tracker, which
     * accepts the carrier token + tracking number and renders the unified UI.
     */
    private function buildTrackingUrl(string $carrier, string $trackingNumber): string
    {
        return match (strtolower($carrier)) {
            'usps' => sprintf(
                'https://tools.usps.com/go/TrackConfirmAction?tLabels=%s',
                urlencode($trackingNumber),
            ),
            'ups' => sprintf(
                'https://www.ups.com/track?tracknum=%s',
                urlencode($trackingNumber),
            ),
            'fedex' => sprintf(
                'https://www.fedex.com/fedextrack/?trknbr=%s',
                urlencode($trackingNumber),
            ),
            'dhl_express', 'dhl' => sprintf(
                'https://www.dhl.com/en/express/tracking.html?AWB=%s',
                urlencode($trackingNumber),
            ),
            default => sprintf(
                'https://track.goshippo.com/track/%s/%s',
                urlencode(strtolower($carrier)),
                urlencode($trackingNumber),
            ),
        };
    }
}

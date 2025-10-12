<?php

namespace Madar\StockAvailability\Plugin\Sales;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Madar\StockAvailability\Helper\StockHelper;
use Madar\StockAvailability\Model\Session;
use Psr\Log\LoggerInterface;

class OrderRepositoryPlugin
{
    protected $session;
    protected $logger;
    protected $stockHelper;

    public function __construct(
        Session $session,
        LoggerInterface $logger,
        StockHelper $stockHelper
    ) {
        $this->session = $session;
        $this->logger = $logger;
        $this->stockHelper = $stockHelper;
    }

    /**
     * Save delivery branch data to order before saving
     *
     * @param OrderRepositoryInterface $subject
     * @param OrderInterface $order
     * @return array
     */
    public function beforeSave(OrderRepositoryInterface $subject, OrderInterface $order)
    {
        try {
            $shippingAddress = $order->getShippingAddress();

            $shippingLat = $shippingAddress ? $shippingAddress->getData('latitude') : null;
            $shippingLng = $shippingAddress ? $shippingAddress->getData('longitude') : null;

            $sessionLat = $this->session->getData('customer_latitude');
            $sessionLng = $this->session->getData('customer_longitude');

            $shippingHasCoords = $this->hasCoordinates($shippingLat, $shippingLng);
            $sessionHasCoords = $this->hasCoordinates($sessionLat, $sessionLng);

            $finalLatValue = null;
            $finalLngValue = null;
            $finalLatFloat = null;
            $finalLngFloat = null;
            $shouldRecalculateBranch = false;

            if ($shippingHasCoords) {
                $finalLatValue = $shippingLat;
                $finalLngValue = $shippingLng;
                $finalLatFloat = (float)$shippingLat;
                $finalLngFloat = (float)$shippingLng;
                $shouldRecalculateBranch = !$sessionHasCoords
                    || !$this->coordinatesEqual($shippingLat, $sessionLat)
                    || !$this->coordinatesEqual($shippingLng, $sessionLng);
            } elseif ($sessionHasCoords) {
                $finalLatValue = $sessionLat;
                $finalLngValue = $sessionLng;
                $finalLatFloat = (float)$sessionLat;
                $finalLngFloat = (float)$sessionLng;
                $shouldRecalculateBranch = true;

                if ($shippingAddress) {
                    $shippingAddress->setData('latitude', $finalLatValue);
                    $shippingAddress->setData('longitude', $finalLngValue);
                }
            }

            if ($shippingAddress && $finalLatValue !== null && $finalLngValue !== null) {
                $shippingAddress->setData('latitude', $finalLatValue);
                $shippingAddress->setData('longitude', $finalLngValue);
            }

            $sourceCode = $this->session->getData('selected_source_code');
            $branchName = $this->session->getData('selected_branch_name');
            $branchPhone = $this->session->getData('selected_branch_phone');

            if ($finalLatFloat !== null && $finalLngFloat !== null && ($shouldRecalculateBranch || !$sourceCode)) {
                $nearestSource = $this->stockHelper->findNearestSourceCode($finalLatFloat, $finalLngFloat);

                if ($nearestSource) {
                    if ($sourceCode !== $nearestSource) {
                        $branchName = null;
                        $branchPhone = null;
                    }
                    $sourceCode = $nearestSource;
                } else {
                    $sourceCode = null;
                    $branchName = null;
                    $branchPhone = null;
                }
            }

            if ($finalLatValue !== null && $finalLngValue !== null) {
                $this->session->setData('customer_latitude', $finalLatValue);
                $this->session->setData('customer_longitude', $finalLngValue);
            }

            $this->session->setData('selected_source_code', $sourceCode);
            $this->session->setData('selected_branch_name', $branchName);
            $this->session->setData('selected_branch_phone', $branchPhone);

            $order->setData('delivery_source_code', $sourceCode);
            $order->setData('delivery_branch_name', $branchName);
            $order->setData('delivery_branch_phone', $branchPhone);

            if ($shippingAddress) {
                $shippingAddress->setData('delivery_source_code', $sourceCode);
                $shippingAddress->setData('delivery_branch_name', $branchName);
                $shippingAddress->setData('delivery_branch_phone', $branchPhone);
            }

        } catch (\Exception $e) {
            $this->logger->error('Error saving delivery data to order: ' . $e->getMessage());
        }

        return [$order];
    }

    protected function hasCoordinates($lat, $lng): bool
    {
        return $lat !== null && $lat !== '' && $lng !== null && $lng !== '';
    }

    protected function coordinatesEqual($first, $second): bool
    {
        if ($first === null || $first === '' || $second === null || $second === '') {
            return false;
        }

        return abs((float)$first - (float)$second) < 0.000001;
    }
}

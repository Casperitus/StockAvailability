<?php

namespace Madar\StockAvailability\Plugin\Sales;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Madar\StockAvailability\Model\LocationManager;
use Psr\Log\LoggerInterface;

class OrderRepositoryPlugin
{
    protected LocationManager $locationManager;
    protected LoggerInterface $logger;

    public function __construct(
        LocationManager $locationManager,
        LoggerInterface $logger
    ) {
        $this->locationManager = $locationManager;
        $this->logger = $logger;
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
            $this->locationManager->applyToOrder($order);
        } catch (\Exception $e) {
            $this->logger->error('Error saving delivery data to order: ' . $e->getMessage());
        }

        return [$order];
    }
}

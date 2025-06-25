<?php

namespace Madar\StockAvailability\Plugin\Sales;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Madar\StockAvailability\Model\Session;
use Psr\Log\LoggerInterface;

class OrderRepositoryPlugin
{
    protected $session;
    protected $logger;

    public function __construct(
        Session $session,
        LoggerInterface $logger
    ) {
        $this->session = $session;
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
            // Get delivery branch data from session
            $sourceCode = $this->session->getData('selected_source_code');
            $branchName = $this->session->getData('selected_branch_name');
            $branchPhone = $this->session->getData('selected_branch_phone');
            $customerLat = $this->session->getData('customer_latitude');
            $customerLng = $this->session->getData('customer_longitude');

            // Save to order
            if ($sourceCode) {
                $order->setData('delivery_source_code', $sourceCode);
            }
            if ($branchName) {
                $order->setData('delivery_branch_name', $branchName);
            }
            if ($branchPhone) {
                $order->setData('delivery_branch_phone', $branchPhone);
            }

            // Also save to shipping address if available
            $shippingAddress = $order->getShippingAddress();
            if ($shippingAddress) {
                if ($sourceCode) {
                    $shippingAddress->setData('delivery_source_code', $sourceCode);
                }
                if ($branchName) {
                    $shippingAddress->setData('delivery_branch_name', $branchName);
                }
                if ($branchPhone) {
                    $shippingAddress->setData('delivery_branch_phone', $branchPhone);
                }
                if ($customerLat) {
                    $shippingAddress->setData('latitude', $customerLat);
                }
                if ($customerLng) {
                    $shippingAddress->setData('longitude', $customerLng);
                }
            }

        } catch (\Exception $e) {
            $this->logger->error('Error saving delivery data to order: ' . $e->getMessage());
        }

        return [$order];
    }
}
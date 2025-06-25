<?php

namespace Madar\StockAvailability\Block\Adminhtml\Order\View;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order;

class DeliveryInfo extends Template
{
    /**
     * @var Registry
     */
    protected $registry;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        array $data = []
    ) {
        $this->registry = $registry;
        parent::__construct($context, $data);
    }

    /**
     * Retrieve current order model instance
     *
     * @return Order|null
     */
    public function getOrder(): ?Order
    {
        return $this->registry->registry('current_order');
    }

    /**
     * Generates a valid Google Maps URL to show a pin at the coordinates.
     *
     * @param float $lat
     * @param float $lng
     * @return string
     */
    public function getGoogleMapsUrl($lat, $lng): string
    {
        // This is the standard and most reliable way to link to a coordinate on Google Maps.
        return sprintf(
            'https://www.google.com/maps/search/?api=1&query=%s,%s',
            $lat,
            $lng
        );
    }

    /**
     * Get the source code assigned to this order
     *
     * @return string|null
     */
    public function getAssignedSourceCode(): ?string
    {
        $order = $this->getOrder();
        if (!$order) {
            return null;
        }

        // Try to get from order data first
        $sourceCode = $order->getData('delivery_source_code');

        // If not found in order data, try shipping address
        if (!$sourceCode) {
            $shippingAddress = $order->getShippingAddress();
            if ($shippingAddress) {
                $sourceCode = $shippingAddress->getData('delivery_source_code');
            }
        }

        return $sourceCode;
    }

    /**
     * Get source details by source code
     *
     * @param string $sourceCode
     * @return array|null
     */
    public function getSourceDetails(string $sourceCode): ?array
    {
        try {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $sourceRepository = $objectManager->get(\Magento\InventoryApi\Api\SourceRepositoryInterface::class);
            $source = $sourceRepository->get($sourceCode);

            return [
                'source_code' => $source->getSourceCode(),
                'name' => $source->getName(),
                'phone' => $source->getPhone(),
                'latitude' => $source->getLatitude(),
                'longitude' => $source->getLongitude(),
                'street' => is_array($source->getStreet()) ? implode(', ', $source->getStreet()) : ($source->getStreet() ?: ''),
                'city' => $source->getCity(),
                'region' => $source->getRegion(),
                'postcode' => $source->getPostcode(),
                'country_id' => $source->getCountryId(),
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get delivery branch name from order
     *
     * @return string|null
     */
    public function getDeliveryBranchName(): ?string
    {
        $order = $this->getOrder();
        if (!$order) {
            return null;
        }

        $branchName = $order->getData('delivery_branch_name');
        if (!$branchName) {
            $shippingAddress = $order->getShippingAddress();
            if ($shippingAddress) {
                $branchName = $shippingAddress->getData('delivery_branch_name');
            }
        }

        return $branchName;
    }

    /**
     * Get delivery branch phone from order
     *
     * @return string|null
     */
    public function getDeliveryBranchPhone(): ?string
    {
        $order = $this->getOrder();
        if (!$order) {
            return null;
        }

        $branchPhone = $order->getData('delivery_branch_phone');
        if (!$branchPhone) {
            $shippingAddress = $order->getShippingAddress();
            if ($shippingAddress) {
                $branchPhone = $shippingAddress->getData('delivery_branch_phone');
            }
        }

        return $branchPhone;
    }
}

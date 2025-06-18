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
}

<?php

namespace Madar\StockAvailability\Block\Adminhtml\Order\View;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order;
use Madar\StockAvailability\Helper\StockHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;

class DeliveryInfo extends Template
{
    protected $registry;
    protected $stockHelper;
    protected $scopeConfig;

    public function __construct(
        Context $context,
        Registry $registry,
        StockHelper $stockHelper,
        ScopeConfigInterface $scopeConfig,
        array $data = []
    ) {
        $this->registry = $registry;
        $this->stockHelper = $stockHelper;
        $this->scopeConfig = $scopeConfig;
        parent::__construct($context, $data);
    }

    public function getOrder(): ?Order
    {
        return $this->registry->registry('current_order');
    }

    public function getDeliveryBranch(): ?array
    {
        $order = $this->getOrder();
        if (!$order) {
            return null;
        }

        // Get shipping address
        $shippingAddress = $order->getShippingAddress();
        if (!$shippingAddress) {
            return null;
        }

        // Get coordinates
        $latitude = $shippingAddress->getData('latitude');
        $longitude = $shippingAddress->getData('longitude');

        if (!$latitude || !$longitude) {
            return null;
        }

        // Find nearest branch
        $sources = $this->stockHelper->getAllSourcesWithHubs();
        $nearestBranch = null;
        $shortestDistance = PHP_FLOAT_MAX;

        foreach ($sources as $source) {
            $distance = $this->calculateDistance(
                $latitude,
                $longitude,
                $source['latitude'],
                $source['longitude']
            );

            if ($distance <= $source['delivery_range_km'] && $distance < $shortestDistance) {
                $shortestDistance = $distance;
                $nearestBranch = [
                    'name' => $source['source_name'],
                    'code' => $source['source_code'],
                    'phone' => $source['phone'],
                    'distance' => round($distance, 2),
                    'latitude' => $source['latitude'],
                    'longitude' => $source['longitude']
                ];
            }
        }

        return $nearestBranch;
    }

    public function getGoogleMapsUrl($lat, $lng): string
    {
        return sprintf(
            'https://www.google.com/maps/search/?api=1&query=%s,%s',
            $lat,
            $lng
        );
    }

    protected function calculateDistance($lat1, $lng1, $lat2, $lng2): float
    {
        $earthRadius = 6371; // km
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    public function getApiKey(): string
    {
        return $this->scopeConfig->getValue(
            'madar_shiplus/general/referrer_api_key',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }
}

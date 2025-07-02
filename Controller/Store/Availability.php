<?php

namespace Madar\StockAvailability\Controller\Store;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Madar\StockAvailability\Helper\StockHelper;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

class Availability extends Action
{
    protected $stockHelper;
    protected $resultJsonFactory;
    protected $logger;

    public function __construct(
        Context $context,
        StockHelper $stockHelper,
        JsonFactory $resultJsonFactory,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->stockHelper = $stockHelper;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->logger = $logger;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        $sku = $this->getRequest()->getParam('sku');
        $latitude = (float) $this->getRequest()->getParam('latitude');
        $longitude = (float) $this->getRequest()->getParam('longitude');
        $maxDistance = (float) $this->getRequest()->getParam('max_distance', 50);

        if (!$sku || !$latitude || !$longitude) {
            return $result->setData([
                'success' => false,
                'message' => 'Missing required parameters: sku, latitude, longitude'
            ]);
        }

        try {
            // Get all sources
            $allSources = $this->stockHelper->getAllSourcesWithHubs();
            $availableStores = [];

            foreach ($allSources as $source) {
                // Calculate distance
                $distance = $this->calculateDistance(
                    $latitude,
                    $longitude,
                    (float) $source['latitude'],
                    (float) $source['longitude']
                );

                // Skip if too far
                if ($distance > $maxDistance) {
                    continue;
                }

                // Check if product is deliverable/available at this source
                $isAvailable = $this->stockHelper->isProductDeliverable($sku, $source['source_code']);

                $this->logger->info("Store: {$source['source_name']}, SKU: {$sku}, Available: " . ($isAvailable ? 'Yes' : 'No'));

                // Only include if product is available
                if ($isAvailable) {
                    $availableStores[] = [
                        'source_code' => $source['source_code'],
                        'source_name' => $source['source_name'],
                        'phone' => $source['phone'] ?? '',
                        'latitude' => $source['latitude'],
                        'longitude' => $source['longitude'],
                        'distance' => round($distance, 1),
                        'address' => $this->formatAddress($source),
                        'delivery_range_km' => $source['delivery_range_km']
                    ];
                }
            }

            // Sort by distance
            usort($availableStores, function($a, $b) {
                return $a['distance'] <=> $b['distance'];
            });

            // Limit to top 10
            $availableStores = array_slice($availableStores, 0, 10);

            $this->logger->info("Found " . count($availableStores) . " available stores for SKU: {$sku}");

            return $result->setData([
                'success' => true,
                'stores' => $availableStores,
                'total_found' => count($availableStores)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Store availability error: ' . $e->getMessage());
            return $result->setData([
                'success' => false,
                'message' => 'Error finding available stores: ' . $e->getMessage()
            ]);
        }
    }

    private function calculateDistance($lat1, $lng1, $lat2, $lng2)
    {
        $earthRadius = 6371; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng/2) * sin($dLng/2);

        $c = 2 * atan2(sqrt($a), sqrt(1-$a));

        return $earthRadius * $c;
    }

    private function formatAddress($source)
    {
        $parts = [];
        
        if (!empty($source['street'])) {
            $parts[] = $source['street'];
        }
        if (!empty($source['city'])) {
            $parts[] = $source['city'];
        }
        if (!empty($source['region'])) {
            $parts[] = $source['region'];
        }

        return !empty($parts) ? implode(', ', $parts) : 'Address not available';
    }
}
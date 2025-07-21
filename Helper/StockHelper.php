<?php

namespace Madar\StockAvailability\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;

class StockHelper extends AbstractHelper
{
    protected $getSourceItemsBySku;
    protected $sourceRepository;
    protected $logger;
    protected $productRepository;
    protected $searchCriteriaBuilder;
    protected $resourceConnection;
    protected $stockCache = [];
    protected $cacheTag = 'MADAR_PRODUCT_DELIVERABILITY';
    protected $deliverabilityCache = [];


    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        GetSourceItemsBySkuInterface $getSourceItemsBySku,
        SourceRepositoryInterface $sourceRepository,
        LoggerInterface $logger,
        ProductRepositoryInterface $productRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        ResourceConnection $resourceConnection
    ) {
        parent::__construct($context);
        $this->getSourceItemsBySku = $getSourceItemsBySku;
        $this->sourceRepository = $sourceRepository;
        $this->logger = $logger;
        $this->productRepository = $productRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Get Bulk Inventory Status for given SKUs from inventory_source_item
     */
    public function getBulkInventoryStatus(array $skus): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $connection->getTableName('inventory_source_item');

        $select = $connection->select()
            ->from($table, ['sku', 'source_code', 'status'])
            ->where('sku IN (?)', $skus);

        $results = $connection->fetchAll($select);
        $inventoryData = [];

        foreach ($results as $row) {
            $inventoryData[$row['sku']][$row['source_code']] = (bool)$row['status'];
        }

        return $inventoryData;
    }

    /**
     * Fetch bulk product data efficiently
     */
    public function getBulkProductData(array $skus): array
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('sku', $skus, 'in')
            ->addFilter('status', \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED, 'eq')
            ->create();

        $products = $this->productRepository->getList($searchCriteria)->getItems();

        $productsData = [];

        foreach ($products as $product) {
            $sku = $product->getSku();
            $productsData[$sku] = [
                'global_shipping' => (bool)$product->getCustomAttribute('is_global_shipping')?->getValue(),
                'type' => $product->getTypeId(),
                'children' => $this->getChildSkus($product),
            ];
        }

        return $productsData;
    }

    /**
     * Cleanup method
     */
    public function cleanupDeletedDeliverabilityData(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $connection->getTableName('madar_product_deliverability');
        $connection->delete($table, ['deleted = ?' => 1]);
    }

    /**
     * Get bulk inventory data
     */
    public function getBulkInventoryData(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $sourceItemTable = $this->resourceConnection->getTableName('inventory_source_item');

        $select = $connection->select()->from(
            $sourceItemTable,
            ['sku', 'source_code', 'status']
        );

        $results = $connection->fetchAll($select);

        $inventoryData = [];
        foreach ($results as $row) {
            $inventoryData[$row['sku']][$row['source_code']] = (bool)$row['status'];
        }

        return $inventoryData;
    }
    /**
     * Get all sources with enhanced address information
     *
     * @return array
     */
    public function getAllSourcesWithAddresses(): array
    {
        $sources = $this->getAllSourcesWithHubs();

        foreach ($sources as &$source) {
            // Get full source details from repository for better address info
            try {
                $sourceDetails = $this->sourceRepository->get($source['source_code']);
                $source['street'] = is_array($sourceDetails->getStreet())
                    ? implode(', ', $sourceDetails->getStreet())
                    : ($sourceDetails->getStreet() ?: '');
                $source['city'] = $sourceDetails->getCity() ?: '';
                $source['region'] = $sourceDetails->getRegion() ?: '';
                $source['postcode'] = $sourceDetails->getPostcode() ?: '';
                $source['country_id'] = $sourceDetails->getCountryId() ?: '';
            } catch (\Exception $e) {
                // Keep existing source data if detailed fetch fails
            }
        }

        return $sources;
    }
    /**
     * Fetch all products data at once (optimized).
     */
    public function getAllProductsData(): array
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('status', \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED, 'eq')
            ->create();

        $productList = $this->productRepository->getList($searchCriteria)->getItems();
        $productsData = [];

        foreach ($productList as $product) {
            $sku = $product->getSku();
            $productsData[$sku] = [
                'global_shipping' => (bool)$product->getCustomAttribute('is_global_shipping')?->getValue(),
                'type' => $product->getTypeId(),
                'children' => $this->getChildSkus($product)
            ];
        }

        return $productsData;
    }

    /**
     * Optimized deliverability calculation method
     */
    public function calculateDeliverableSkus(array $allSkus, array $source, array $productData, array $inventoryData): array
    {
        $sourceCode = $source['source_code'];
        $deliverableSkus = [];

        foreach ($allSkus as $sku) {
            $data = $productData[$sku] ?? null;
            if (!$data) continue;

            if ($data['global_shipping']) {
                $deliverableSkus[] = $sku;
                continue;
            }

            // Check main source stock status
            if (!empty($inventoryData[$sku][$sourceCode])) {
                $deliverableSkus[] = $sku;
                continue;
            }

            // Check child SKUs if grouped/configurable
            if (in_array($data['type'], [Configurable::TYPE_CODE, Grouped::TYPE_CODE])) {
                foreach ($data['children'] as $childSku) {
                    if (!empty($inventoryData[$childSku][$sourceCode])) {
                        $deliverableSkus[] = $sku;
                        continue 2; // next SKU
                    }

                    // Check associated hubs
                    foreach ($source['associated_hubs'] as $hub) {
                        if (!empty($inventoryData[$childSku][$hub['source_code']])) {
                            $deliverableSkus[] = $sku;
                            continue 2;
                        }
                    }
                }
                continue;
            }

            // Check associated hubs for simple product
            foreach ($source['associated_hubs'] as $hub) {
                if (!empty($inventoryData[$sku][$hub['source_code']])) {
                    $deliverableSkus[] = $sku;
                    continue 2;
                }
            }
        }

        return array_unique($deliverableSkus);
    }
    /**
     * Check if product is actually in stock at the specific source (not including hubs)
     *
     * @param string $sku
     * @param string $sourceCode
     * @return bool
     */
    public function isProductInStockAtSpecificSource(string $sku, string $sourceCode): bool
    {
        try {
            // Check cache first
            $cacheKey = 'stock_' . $sku . '_' . $sourceCode;
            if (isset($this->stockCache[$cacheKey])) {
                return $this->stockCache[$cacheKey];
            }

            // For NATIONWIDE_SHIPPING, we don't check stock
            if ($sourceCode === 'NATIONWIDE_SHIPPING') {
                return false;
            }

            // Check if product exists
            $product = $this->productRepository->get($sku);

            // Check if it's a grouped or configurable product
            if (in_array($product->getTypeId(), [Configurable::TYPE_CODE, Grouped::TYPE_CODE])) {
                $childSkus = $this->getChildSkus($product);
                foreach ($childSkus as $childSku) {
                    if ($this->isProductInStockAtSource($childSku, $sourceCode)) {
                        $this->stockCache[$cacheKey] = true;
                        return true;
                    }
                }
                $this->stockCache[$cacheKey] = false;
                return false;
            }

            // For simple products, check direct stock at source
            $isInStock = $this->isProductInStockAtSource($sku, $sourceCode);
            $this->stockCache[$cacheKey] = $isInStock;
            return $isInStock;
        } catch (\Exception $e) {
            $this->logger->error("[StockHelper] Error checking stock at specific source: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get detailed stock status for a product
     * Returns: 'in_stock', 'backorder', or 'out_of_stock'
     *
     * @param string $sku
     * @param string $sourceCode
     * @return string
     */
    public function getProductStockStatus(string $sku, string $sourceCode): string
    {
        try {
            // First check if it's in stock at the specific source
            if ($this->isProductInStockAtSpecificSource($sku, $sourceCode)) {
                return 'in_stock';
            }

            // If not in stock locally, check if it's deliverable (from hubs)
            if ($this->isProductDeliverable($sku, $sourceCode)) {
                return 'backorder';
            }

            // Neither in stock nor deliverable
            return 'out_of_stock';
        } catch (\Exception $e) {
            $this->logger->error("[StockHelper] Error getting stock status: " . $e->getMessage());
            return 'out_of_stock';
        }
    }

    /**
     * Retrieves all inventory sources with their associated hubs.
     *
     * @return array
     */
    public function getAllSourcesWithHubs(): array
    {
        try {
            $searchCriteria = $this->searchCriteriaBuilder
                ->create();
            $sourceList = $this->sourceRepository->getList($searchCriteria)->getItems();
        } catch (\Exception $e) {
            return [];
        }

        $sources = [];
        foreach ($sourceList as $source) {
            try {
                $sourceCode = $source->getSourceCode();
                $sourceName = $source->getName();
                $sourcePhone = $source->getPhone();
                $latitude = $source->getLatitude();
                $longitude = $source->getLongitude();
                $range = $source->getExtensionAttributes() && $source->getExtensionAttributes()->getDeliveryRangeKm()
                    ? (float)$source->getExtensionAttributes()->getDeliveryRangeKm()
                    : 50.0; // Default range
                $associatedHubsCodes = $source->getExtensionAttributes() && is_array($source->getExtensionAttributes()->getAssociatedHubs())
                    ? $source->getExtensionAttributes()->getAssociatedHubs()
                    : [];

                if ($latitude === null || $longitude === null) {
                    continue;
                }

                // Fetch associated hubs
                $associatedHubs = [];
                foreach ($associatedHubsCodes as $hubCode) {
                    try {
                        $hub = $this->sourceRepository->get($hubCode);
                        $hubLatitude = $hub->getLatitude();
                        $hubLongitude = $hub->getLongitude();
                        $hubRange = $hub->getExtensionAttributes() && $hub->getExtensionAttributes()->getDeliveryRangeKm()
                            ? (float)$hub->getExtensionAttributes()->getDeliveryRangeKm()
                            : 50.0; // Default range

                        if ($hubLatitude === null || $hubLongitude === null) {
                            continue;
                        }

                        $associatedHubs[] = [
                            'source_code' => $hub->getSourceCode(),
                            'source_name' => $hub->getName(),
                            'phone' => $hub->getPhone(),
                            'latitude' => $hubLatitude,
                            'longitude' => $hubLongitude,
                            'range' => $hubRange,
                            'source_object' => $hub,
                        ];
                    } catch (NoSuchEntityException $e) {
                        continue;
                    }
                }

                $sources[] = [
                    'source_code' => $sourceCode,
                    'source_name' => $sourceName,
                    'phone' => $sourcePhone,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'delivery_range_km' => $range,
                    'associated_hubs' => $associatedHubs,
                ];
            } catch (\Exception $e) {
                continue;
            }
        }
        return $sources;
    }

    /**
     * Retrieves deliverable SKUs for a given source based on stock status or global shipping.
     *
     * @param string $sourceCode
     * @param array $source
     * @return array
     */
    public function getDeliverableSkusForSource(string $sourceCode, array $source): array
    {
        // Fetch all enabled SKUs
        $skus = $this->getAllProductSkus();
        $deliverableSkus = [];

        foreach ($skus as $sku) {
            try {
                $product = $this->productRepository->get($sku);
                $productType = $product->getTypeId();

                // Check if product has global shipping
                $hasGlobalShipping = $this->hasGlobalShipping($product);

                if ($hasGlobalShipping) {
                    $deliverableSkus[] = $sku;
                    continue; // Skip further processing for global shipping products
                }

                // Handle Grouped and Configurable Products
                if (in_array($productType, [Grouped::TYPE_CODE, Configurable::TYPE_CODE])) {
                    $childSkus = $this->getChildSkus($product);

                    foreach ($childSkus as $childSku) {
                        try {
                            $childProduct = $this->productRepository->get($childSku);
                            $hasGlobalShippingChild = $this->hasGlobalShipping($childProduct);

                            if ($hasGlobalShippingChild) {
                                $deliverableSkus[] = $sku;
                                break; // Move to next parent SKU
                            }

                            $isInStockMain = $this->isProductInStockAtSource($childSku, $sourceCode);

                            if ($isInStockMain) {
                                $deliverableSkus[] = $sku;
                                break;
                            }

                            // Check stock status at associated hubs
                            foreach ($source['associated_hubs'] ?? [] as $hub) {
                                $hubCode = $hub['source_code'];
                                $isInStockHub = $this->isProductInStockAtSource($childSku, $hubCode);
                                if ($isInStockHub) {
                                    $deliverableSkus[] = $sku;
                                    break 2; // Exit both loops
                                }
                            }
                        } catch (NoSuchEntityException $e) {
                            continue;
                        } catch (\Exception $e) {
                            continue;
                        }
                    }

                    continue; // Move to the next SKU after processing grouped/configurable product
                }

                // For simple products
                // No need to check for global shipping again here as it's already handled above

                // Check stock status at main source
                $isInStockMain = $this->isProductInStockAtSource($sku, $sourceCode);

                if ($isInStockMain) {
                    $deliverableSkus[] = $sku;
                    continue;
                }

                // Check stock status at associated hubs
                foreach ($source['associated_hubs'] ?? [] as $hub) {
                    $hubCode = $hub['source_code'];
                    $isInStockHub = $this->isProductInStockAtSource($sku, $hubCode);
                    if ($isInStockHub) {
                        $deliverableSkus[] = $sku;
                        break;
                    }
                }
            } catch (NoSuchEntityException $e) {
                continue;
            } catch (\Exception $e) {
                continue;
            }
        }

        return $deliverableSkus;
    }


    /**
     * Saves the deliverability statuses of multiple products to the database in bulk.
     *
     * @param array $skus
     * @param string $sourceCode
     * @return void
     */
    public function saveDeliverabilityStatuses(array $skus, string $sourceCode): void
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName('madar_product_deliverability');

            $data = [];
            $currentTimestamp = date('Y-m-d H:i:s');

            foreach ($skus as $sku) {
                $data[] = [
                    'product_sku' => $sku,
                    'source_code' => $sourceCode,
                    'deliverable' => 1,
                    'last_updated' => $currentTimestamp,
                    'deleted' => 0, // Ensure the new/updated records are not marked as deleted
                ];
            }

            if (!empty($data)) {
                $connection->insertOnDuplicate(
                    $tableName,
                    $data,
                    ['deliverable', 'last_updated', 'deleted'] // Include 'deleted' in the update columns
                );
            }
        } catch (\Exception $e) {
        }
    }

    /**
     * Retrieves the ResourceConnection instance.
     *
     * @return ResourceConnection
     */
    public function getResourceConnection(): ResourceConnection
    {
        return $this->resourceConnection;
    }

    // Existing methods...

    /**
     * Determines if a product is in stock at a specific source.
     *
     * @param string $sku
     * @param string $sourceCode
     * @return bool
     */
    public function isProductInStockAtSource(string $sku, string $sourceCode): bool
    {
        try {
            $cacheKey = $sku . '_' . $sourceCode;
            if (isset($this->stockCache[$cacheKey])) {
                return $this->stockCache[$cacheKey];
            }

            $sourceItems = $this->getSourceItemsBySku->execute($sku);
            foreach ($sourceItems as $sourceItem) {
                if ($sourceItem->getSourceCode() === $sourceCode) {
                    $isInStock = $sourceItem->getStatus() == SourceItemInterface::STATUS_IN_STOCK;
                    $this->stockCache[$cacheKey] = $isInStock;
                    return $isInStock;
                }
            }
        } catch (\Exception $e) {
        }

        $this->stockCache[$cacheKey] = false;
        return false;
    }

    /**
     * Retrieves all product SKUs that are enabled.
     *
     * @return array
     */
    public function getAllProductSkus(): array
    {

        try {
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('status', \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED, 'eq')
                ->create();
            $productList = $this->productRepository->getList($searchCriteria)->getItems();
        } catch (\Exception $e) {
            return [];
        }

        $skus = [];
        foreach ($productList as $product) {
            $skus[] = $product->getSku();
        }

        return $skus;
    }

    /**
     * Determines if a product has global shipping enabled.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return bool
     */
    protected function hasGlobalShipping($product): bool
    {
        $globalShipping = false;
        if ($product->getCustomAttribute('is_global_shipping')) {
            $globalShipping = (bool)$product->getCustomAttribute('is_global_shipping')->getValue();
        }
        return $globalShipping;
    }

    /**
     * Retrieves child SKUs for a given parent product.
     *
     * @param \Magento\Catalog\Model\Product $parentProduct
     * @return array
     */
    protected function getChildSkus($parentProduct): array
    {
        $childSkus = [];
        $productType = $parentProduct->getTypeId();

        if ($productType === Configurable::TYPE_CODE) {
            $childProducts = $parentProduct->getTypeInstance()->getUsedProducts($parentProduct);
            foreach ($childProducts as $child) {
                $childSkus[] = $child->getSku();
            }
        } elseif ($productType === Grouped::TYPE_CODE) {
            $childProducts = $parentProduct->getTypeInstance()->getAssociatedProducts($parentProduct);
            foreach ($childProducts as $child) {
                $childSkus[] = $child->getSku();
            }
        }

        return $childSkus;
    }


    /**
     * Determines if a product is deliverable based on the selected source code.
     *
     * @param string $sku
     * @param string $sourceCode
     * @return bool
     */
    public function isProductDeliverable(string $sku, string $sourceCode): bool
    {
        try {
            if (isset($this->deliverabilityCache[$sku][$sourceCode])) {
                return $this->deliverabilityCache[$sku][$sourceCode];
            }

            // Step 1: Handle the special "NATIONWIDE_SHIPPING" source code
            if ($sourceCode === 'NATIONWIDE_SHIPPING') {
                $product = $this->productRepository->get($sku);
                $hasGlobalShipping = $this->hasGlobalShipping($product);
                $this->deliverabilityCache[$sku][$sourceCode] = $hasGlobalShipping;
                return $hasGlobalShipping;
            }

            // Step 2: For actual sources, check if product has global shipping enabled (products with global shipping are deliverable from any actual source)
            $product = $this->productRepository->get($sku); // Ensure product is loaded
            $hasGlobalShipping = $this->hasGlobalShipping($product);

            if ($hasGlobalShipping) {
                $this->deliverabilityCache[$sku][$sourceCode] = true;
                return true;
            }

            // Step 3: For actual sources and non-global shipping products, check precomputed deliverability status
            $isDeliverable = $this->getDeliverabilityStatus($sku, $sourceCode);
            $this->deliverabilityCache[$sku][$sourceCode] = $isDeliverable;

            return $isDeliverable; // No need for the if/else here

        } catch (NoSuchEntityException $e) {
            // Product not found, so not deliverable
            $this->logger->warning("[StockHelper] Product SKU not found: {$sku}");
            $this->deliverabilityCache[$sku][$sourceCode] = false;
            return false;
        } catch (\Exception $e) {
            $this->logger->error("[StockHelper] Error in isProductDeliverable for SKU {$sku}, Source {$sourceCode}: " . $e->getMessage());
            // Default to false on other errors to be safe
            $this->deliverabilityCache[$sku][$sourceCode] = false;
            return false;
        }
    }


    /**
     * Calculates the distance between two geographic coordinates using the Haversine formula.
     *
     * @param float $lat1 Latitude of the first location
     * @param float $lon1 Longitude of the first location
     * @param float $lat2 Latitude of the second location
     * @param float $lon2 Longitude of the second location
     * @return float Distance in kilometers
     */
    protected function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // Radius of the Earth in kilometers

        // Convert degrees to radians
        $lat1Rad = deg2rad($lat1);
        $lon1Rad = deg2rad($lon1);
        $lat2Rad = deg2rad($lat2);
        $lon2Rad = deg2rad($lon2);

        // Haversine formula
        $deltaLat = $lat2Rad - $lat1Rad;
        $deltaLon = $lon2Rad - $lon1Rad;

        $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
            cos($lat1Rad) * cos($lat2Rad) *
            sin($deltaLon / 2) * sin($deltaLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        $distance = $earthRadius * $c;

        return $distance;
    }

    /**
     * Finds the nearest source (branch) to the given latitude/longitude.
     * Returns the source code or null if none is within range.
     *
     * @param float $lat
     * @param float $lng
     * @return string|null
     */
    public function findNearestSourceCode(float $lat, float $lng): ?string
    {
        $sources = $this->getAllSourcesWithHubs();
        if (!$sources) {
            return null;
        }

        $nearestSourceCode = null;
        $shortestDistance = PHP_INT_MAX;

        foreach ($sources as $source) {
            $distance = $this->calculateDistance($lat, $lng, $source['latitude'], $source['longitude']);
            if ($distance <= $source['delivery_range_km'] && $distance < $shortestDistance) {
                $shortestDistance = $distance;
                $nearestSourceCode = $source['source_code'];
            }
        }

        return $nearestSourceCode;
    }


    /**
     * Retrieves the deliverability status of a product SKU for a given source code from the madar_product_deliverability table.
     *
     * @param string $sku
     * @param string $sourceCode
     * @return bool
     */
    protected function getDeliverabilityStatus(string $sku, string $sourceCode): bool
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $deliverabilityTable = $this->resourceConnection->getTableName('madar_product_deliverability');

            $select = $connection->select()
                ->from($deliverabilityTable, ['deliverable'])
                ->where('product_sku = ?', $sku)
                ->where('source_code = ?', $sourceCode)
                ->limit(1);

            $result = $connection->fetchOne($select);

            if ($result !== false) {
                return (bool)$result;
            }

            // If there's no entry in the table, default to not deliverable
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }
}

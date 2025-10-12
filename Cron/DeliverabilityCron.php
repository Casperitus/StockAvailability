<?php

namespace Madar\StockAvailability\Cron;

use Madar\StockAvailability\Helper\StockHelper;
use Psr\Log\LoggerInterface;

class DeliverabilityCron
{
    protected $stockHelper;
    protected $logger;

    public function __construct(
        StockHelper $stockHelper,
        LoggerInterface $logger
    ) {
        $this->stockHelper = $stockHelper;
        $this->logger = $logger;
    }

    public function execute()
    {

        try {
            $this->resetDeliverabilityData();

            $sources = $this->stockHelper->getAllSourcesWithHubs();
            $batchSize = 500;

            foreach ($this->stockHelper->getProductSkuBatches($batchSize) as $skuBatch) {
                if (empty($skuBatch)) {
                    continue;
                }

                $productData = $this->stockHelper->getBulkProductData($skuBatch);
                if (empty($productData)) {
                    continue;
                }

                $inventorySkus = $this->stockHelper->expandSkusForInventory($skuBatch, $productData);
                $inventoryData = $this->stockHelper->getBulkInventoryStatus($inventorySkus);

                foreach ($sources as $source) {
                    $mainSourceCode = $source['source_code'];

                    $deliverableSkus = $this->stockHelper->calculateDeliverableSkus(
                        $skuBatch,
                        $source,
                        $productData,
                        $inventoryData
                    );

                    if (empty($deliverableSkus)) {
                        continue;
                    }

                    foreach (array_chunk($deliverableSkus, $batchSize) as $chunk) {
                        $this->stockHelper->saveDeliverabilityStatuses($chunk, $mainSourceCode);
                    }
                }
            }

            $this->stockHelper->cleanupDeletedDeliverabilityData();
        } catch (\Exception $e) {
            $this->logger->error('Error: ' . $e->getMessage());
        }
    }


    protected function resetDeliverabilityData(): void
    {
        $connection = $this->stockHelper->getResourceConnection()->getConnection();
        $tableName = $connection->getTableName('madar_product_deliverability');
        $connection->update($tableName, ['deleted' => 1], 'deleted = 0');
    }
}

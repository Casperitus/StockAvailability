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
        $t0 = microtime(true);
        $this->logger->info('[DeliverabilityCron] ---- START ----');

        try {
            // 1) Reset
            $this->resetDeliverabilityData();

            // 2) Sources
            $sources = $this->stockHelper->getAllSourcesWithHubs();
            $sourcesCount = is_array($sources) ? count($sources) : 0;
            $this->logger->info(sprintf('[DeliverabilityCron] Sources loaded: %d', $sourcesCount));
            if ($sourcesCount === 0) {
                $this->logger->warning('[DeliverabilityCron] No sources with coordinates. Nothing will be re-inserted! (Check latitude/longitude on sources)');
            } else {
                foreach ($sources as $s) {
                    $this->logger->info(sprintf(
                        '[DeliverabilityCron] Source %s (hubs: %d, range: %s km, lat:%s lon:%s)',
                        $s['source_code'] ?? 'n/a',
                        isset($s['associated_hubs']) ? count($s['associated_hubs']) : 0,
                        $s['delivery_range_km'] ?? 'n/a',
                        $s['latitude'] ?? 'null',
                        $s['longitude'] ?? 'null'
                    ));
                }
            }

            $batchSize = 500;
            $totalProductsSeen = 0;
            $totalDeliverableWritten = 0;

            foreach ($this->stockHelper->getProductSkuBatches($batchSize) as $skuBatch) {
                $batchCount = count($skuBatch);
                $totalProductsSeen += $batchCount;
                $this->logger->info(sprintf('[DeliverabilityCron] Batch SKUs: %d (cumulative: %d)', $batchCount, $totalProductsSeen));

                if ($batchCount === 0) {
                    continue;
                }

                // 3) Product data for this batch
                $productData = $this->stockHelper->getBulkProductData($skuBatch);
                $this->logger->info(sprintf(
                    '[DeliverabilityCron] ProductData: requested %d, found %d',
                    $batchCount,
                    count($productData)
                ));
                if (empty($productData)) {
                    continue;
                }

                // 4) Inventory for batch (+children)
                $inventorySkus = $this->stockHelper->expandSkusForInventory($skuBatch, $productData);
                $this->logger->info(sprintf(
                    '[DeliverabilityCron] Inventory SKUs to fetch: %d',
                    count($inventorySkus)
                ));
                $inventoryData = $this->stockHelper->getBulkInventoryStatus($inventorySkus);
                $this->logger->info(sprintf(
                    '[DeliverabilityCron] Inventory rows loaded: %d',
                    array_sum(array_map('count', $inventoryData ?: []))
                ));

                // 5) For each source, compute deliverable and upsert
                foreach ($sources as $source) {
                    $mainSourceCode = $source['source_code'] ?? null;
                    if (!$mainSourceCode) {
                        $this->logger->warning('[DeliverabilityCron] Skipping a source with no source_code.');
                        continue;
                    }

                    $deliverableSkus = $this->stockHelper->calculateDeliverableSkus(
                        $skuBatch,
                        $source,
                        $productData,
                        $inventoryData
                    );

                    $deliverableCount = count($deliverableSkus);
                    $this->logger->info(sprintf(
                        '[DeliverabilityCron] %s -> deliverable count for this batch: %d',
                        $mainSourceCode,
                        $deliverableCount
                    ));

                    if ($deliverableCount === 0) {
                        continue;
                    }

                    foreach (array_chunk($deliverableSkus, $batchSize) as $chunk) {
                        $written = $this->stockHelper->saveDeliverabilityStatuses($chunk, $mainSourceCode);
                        $totalDeliverableWritten += (int)$written;
                        $this->logger->info(sprintf(
                            '[DeliverabilityCron] Upserted %d rows into madar_product_deliverability for %s',
                            (int)$written,
                            $mainSourceCode
                        ));
                    }
                }
            }

            // 6) Cleanup
            $this->stockHelper->cleanupDeletedDeliverabilityData();

            $elapsed = round(microtime(true) - $t0, 3);
            $this->logger->info(sprintf(
                '[DeliverabilityCron] ---- DONE ---- productsSeen=%d, written=%d, elapsed=%ss',
                $totalProductsSeen,
                $totalDeliverableWritten,
                $elapsed
            ));
        } catch (\Exception $e) {
            $this->logger->error('[DeliverabilityCron] ERROR: ' . $e->getMessage());
        }
    }

    protected function resetDeliverabilityData(): void
    {
        try {
            $connection = $this->stockHelper->getResourceConnection()->getConnection();
            $tableName = $connection->getTableName('madar_product_deliverability');
            $affected = $connection->update($tableName, ['deleted' => 1], 'deleted = 0');
            $this->logger->info(sprintf('[DeliverabilityCron] Reset rows to deleted=1: %d', (int)$affected));
        } catch (\Exception $e) {
            $this->logger->error('[DeliverabilityCron] resetDeliverabilityData failed: ' . $e->getMessage());
            throw $e;
        }
    }
}

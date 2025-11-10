<?php

declare(strict_types=1);

namespace Madar\StockAvailability\Plugin;

use Magento\Catalog\Model\Product;
use Madar\StockAvailability\Helper\StockHelper;
use Madar\StockAvailability\Model\DeliverabilityAttribute;
use Madar\StockAvailability\Model\Session as CustomerSession;
use Psr\Log\LoggerInterface;

class ProductPlugin
{
    private StockHelper $stockHelper;
    private LoggerInterface $logger;
    private CustomerSession $customerSession;
    private DeliverabilityAttribute $deliverabilityAttribute;

    /** @var array<string,bool> */
    private array $validatedSkus = [];

    private function resolveSourceCode(): ?string
    {
        $sourceCode = $this->customerSession->getData('selected_source_code');
        if (is_string($sourceCode) && $sourceCode !== '') {
            return $sourceCode;
        }

        $latitude = $this->customerSession->getData('customer_latitude');
        $longitude = $this->customerSession->getData('customer_longitude');

        if ($latitude !== null && $longitude !== null && is_numeric($latitude) && is_numeric($longitude)) {
            $resolved = $this->stockHelper->findNearestSourceCode((float) $latitude, (float) $longitude);

            if ($resolved) {
                $this->customerSession->setData('selected_source_code', $resolved);
                $this->logger->debug('Resolved source code from stored coordinates during PDP evaluation.', [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'resolved_source' => $resolved,
                ]);

                return $resolved;
            }

            $this->logger->debug('Unable to resolve source code from stored coordinates during PDP evaluation.', [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);
        }

        return null;
    }

    private function getCacheKey(string $sku, ?string $sourceCode): string
    {
        return sprintf('%s|%s', $sku, $sourceCode ?? '');
    }

    public function __construct(
        StockHelper $stockHelper,
        LoggerInterface $logger,
        CustomerSession $customerSession,
        DeliverabilityAttribute $deliverabilityAttribute
    ) {
        $this->stockHelper = $stockHelper;
        $this->logger = $logger;
        $this->customerSession = $customerSession;
        $this->deliverabilityAttribute = $deliverabilityAttribute;
    }

    public function afterIsSaleable(Product $subject, bool $result): bool
    {
        $sku = (string) $subject->getSku();

        $normalizedSource = $this->resolveSourceCode();

        $this->logger->debug('Evaluating deliverability during isSaleable.', [
            'sku' => $sku,
            'initial_result' => $result,
            'selected_source_code' => $normalizedSource,
        ]);

        if (!$result) {
            $this->deliverabilityAttribute->apply($subject, false);
            $this->logger->debug('Product already non-salable, forcing requestable deliverability.', ['sku' => $sku]);
            return false;
        }

        $cacheKey = $this->getCacheKey($sku, $normalizedSource);

        if (array_key_exists($cacheKey, $this->validatedSkus)) {
            $isDeliverable = $this->validatedSkus[$cacheKey];
            $this->deliverabilityAttribute->apply($subject, $isDeliverable);
            $this->logger->debug('Using cached deliverability decision.', [
                'sku' => $sku,
                'source_code' => $normalizedSource,
                'is_deliverable' => $isDeliverable,
            ]);
            return $result && $isDeliverable;
        }

        if (!$normalizedSource) {
            $this->logger->debug('No source selected; defaulting product to deliverable for PDP evaluation.', [
                'sku' => $sku,
            ]);
            $this->validatedSkus[$cacheKey] = true;
            $this->deliverabilityAttribute->apply($subject, true);
            return $result;
        }

        $isDeliverable = $this->stockHelper->isProductDeliverable($sku, $normalizedSource);
        $this->validatedSkus[$cacheKey] = $isDeliverable;
        $this->deliverabilityAttribute->apply($subject, $isDeliverable);

        if (!$isDeliverable) {
            $this->logger->info(sprintf('SKU %s marked as requestable for source %s.', $sku, $normalizedSource ?? 'N/A'));
        } else {
            $this->logger->debug('SKU marked as deliverable after helper evaluation.', [
                'sku' => $sku,
                'source_code' => $normalizedSource,
            ]);
        }

        return $result && $isDeliverable;
    }
}

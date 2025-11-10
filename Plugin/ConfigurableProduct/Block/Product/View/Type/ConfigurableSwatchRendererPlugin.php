<?php

namespace Madar\StockAvailability\Plugin\ConfigurableProduct\Block\Product\View\Type;

use Magento\ConfigurableProduct\Block\Product\View\Type\Configurable as MagentoConfigurable;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Psr\Log\LoggerInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Madar\StockAvailability\Helper\StockHelper;
use Madar\StockAvailability\Model\Session as CustomerSession;

class ConfigurableSwatchRendererPlugin
{
    protected $jsonSerializer;
    protected $stockHelper;
    protected $logger;
    protected $customerSession;


    public function __construct(
        JsonSerializer $jsonSerializer,
        StockHelper $stockHelper,
        LoggerInterface $logger,
        CustomerSession $customerSession

    ) {
        $this->jsonSerializer = $jsonSerializer;
        $this->stockHelper = $stockHelper;
        $this->logger = $logger;
        $this->customerSession = $customerSession;

    }

    public function afterGetJsonConfig(MagentoConfigurable $subject, $result)
    {
        // Deserialize the result to get the config array
        $config = $this->jsonSerializer->unserialize($result);
        $product = $subject->getProduct();

        // Ensure the product is a configurable product
        if ($product->getTypeId() === ConfigurableType::TYPE_CODE) {
            $associatedProducts = $product->getTypeInstance()->getUsedProducts($product);
        } else {
            return $this->jsonSerializer->serialize($config);
        }

        // Initialize the "is_deliverable" attribute with options
        if (!isset($config['attributes']['is_deliverable'])) {
            $config['attributes']['is_deliverable'] = [
                'id' => 'is_deliverable',
                'code' => 'is_deliverable',
                'label' => 'Deliverability',
                'options' => [],
                'position' => 99 // Ensure it has a position (99 or any other suitable value)
            ];
        }

        // Retrieve the source code from the session
        $customerData = $this->customerSession->getData();
        $sourceCode = $customerData['selected_source_code'] ?? null;

        $requestableProductIds = [];

        foreach ($associatedProducts as $associatedProduct) {
            $productId = $associatedProduct->getId();
            $sku = $associatedProduct->getSku();

            // Get stock status (you can customize this if you're using MSI)
            $isInStock = $associatedProduct->isSaleable();

            // Calculate deliverability
            $isDeliverable = $sourceCode
                ? $this->stockHelper->isProductDeliverable($sku, $sourceCode)
                : true;

            $config['attributes']['is_deliverable']['options'][] = [
                'id' => $productId,
                'label' => $isDeliverable ? 'Deliverable' : 'Requestable',
                'products' => [$productId]
            ];

            if (!$isDeliverable) {
                $associatedProduct->setIsSalable(false);
                $requestableProductIds[] = (int) $productId;
            }
        }

        if ($requestableProductIds) {
            $config = $this->disableRequestableConfigurations($config, $requestableProductIds);
        }

        // Log the final JSON configuration that gets sent to the frontend
        $finalJsonConfig = $this->jsonSerializer->serialize($config);
        return $finalJsonConfig;
    }

    /**
     * Remove requestable product IDs from the configurable index so their swatches are disabled.
     *
     * @param array $config
     * @param int[] $requestableProductIds
     * @return array
     */
    private function disableRequestableConfigurations(array $config, array $requestableProductIds): array
    {
        if (empty($config['attributes']) || empty($config['index']) || !is_array($config['attributes'])) {
            return $config;
        }

        $normalizedIds = array_unique(array_map('intval', $requestableProductIds));

        foreach ($normalizedIds as $productId) {
            if (!isset($config['index'][$productId]) || !is_array($config['index'][$productId])) {
                continue;
            }

            foreach ($config['index'][$productId] as $attributeId => $optionId) {
                if (!isset($config['attributes'][$attributeId]['options'])) {
                    continue;
                }

                foreach ($config['attributes'][$attributeId]['options'] as &$option) {
                    if (!isset($option['id']) || (int) $option['id'] !== (int) $optionId) {
                        continue;
                    }

                    $products = $option['products'] ?? [];
                    if (!is_array($products)) {
                        $products = [];
                    }

                    $option['products'] = array_values(array_filter(
                        $products,
                        static fn($candidateId) => (int) $candidateId !== $productId
                    ));

                    if (empty($option['products'])) {
                        $option['disabled'] = true;
                    }
                }
                unset($option);
            }

            $config['index'][$productId] = [];

            if (isset($config['salable']) && is_array($config['salable'])) {
                $config['salable'][$productId] = false;
            }
        }

        return $config;
    }
}

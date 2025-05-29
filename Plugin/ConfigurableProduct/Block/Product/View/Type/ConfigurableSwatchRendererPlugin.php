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

        foreach ($associatedProducts as $associatedProduct) {
            $productId = $associatedProduct->getId();
            $sku = $associatedProduct->getSku();

            // Get stock status (you can customize this if you're using MSI)
            $isInStock = $associatedProduct->isSaleable();

            // Calculate deliverability
            $isDeliverable = $sourceCode
                ? $this->stockHelper->isProductDeliverable($sku, $sourceCode)
                : true;  // Assume deliverable if no source code is provided

            // Both stock status and deliverability must be true to enable the swatch
            $isAvailable = $isInStock && $isDeliverable;

            // Add deliverability status to the options array
            $config['attributes']['is_deliverable']['options'][] = [
                'id' => $productId,
                'label' => $isAvailable ? 'Yes' : 'No',
                'products' => [$productId]
            ];
        }

        // Log the final JSON configuration that gets sent to the frontend
        $finalJsonConfig = $this->jsonSerializer->serialize($config);
        return $finalJsonConfig;
    }
}

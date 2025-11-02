<?php
declare(strict_types=1);

namespace Madar\StockAvailability\Test\Integration\View;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\LayoutInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

class DeliverabilityBlockTest extends TestCase
{
    /**
     * @magentoAppArea frontend
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testDeliverabilityBlockRendersWithoutDeliverabilityData(): void
    {
        $objectManager = Bootstrap::getObjectManager();

        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = $objectManager->get(ProductRepositoryInterface::class);
        $product = $productRepository->get('simple');

        /** @var Registry $registry */
        $registry = $objectManager->get(Registry::class);
        if ($registry->registry('current_product')) {
            $registry->unregister('current_product');
        }
        $registry->register('current_product', $product);

        try {
            /** @var LayoutInterface $layout */
            $layout = $objectManager->create(LayoutInterface::class);
            $layout->getUpdate()->load(['default', 'catalog_product_view']);
            $layout->generateXml();
            $layout->generateElements();

            $block = $layout->getBlock('deliverability.view.model');
            $this->assertNotNull($block);

            $html = $block->toHtml();
            $this->assertIsString($html);
            $this->assertSame('', trim($html));
        } finally {
            if ($registry->registry('current_product')) {
                $registry->unregister('current_product');
            }
        }
    }
}

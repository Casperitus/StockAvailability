<?php

declare(strict_types=1);

namespace Magento\Framework\Serialize\Serializer {
    if (!class_exists(Json::class)) {
        class Json
        {
            public function serialize($data): string
            {
                return json_encode($data);
            }

            public function unserialize($string)
            {
                return json_decode($string, true);
            }
        }
    }
}

namespace Magento\ConfigurableProduct\Model\Product\Type {
    if (!class_exists(Configurable::class)) {
        class Configurable
        {
            public const TYPE_CODE = 'configurable';
        }
    }
}

namespace Magento\ConfigurableProduct\Block\Product\View\Type {
    if (!class_exists(Configurable::class)) {
        class Configurable
        {
            private $product;

            public function setProduct($product): void
            {
                $this->product = $product;
            }

            public function getProduct()
            {
                return $this->product;
            }
        }
    }
}

namespace Madar\StockAvailability\Test\Unit\Plugin\ConfigurableProduct\Block\Product\View\Type {

use Madar\StockAvailability\Helper\StockHelper;
use Madar\StockAvailability\Model\Session;
use Madar\StockAvailability\Plugin\ConfigurableProduct\Block\Product\View\Type\ConfigurableSwatchRendererPlugin;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConfigurableSwatchRendererPluginTest extends TestCase
{
    public function testDisableRequestableConfigurationsRemovesProducts(): void
    {
        $serializer = new Json();
        $stockHelper = $this->createMock(StockHelper::class);
        $logger = $this->createMock(LoggerInterface::class);
        $session = $this->createMock(Session::class);

        $plugin = new ConfigurableSwatchRendererPlugin($serializer, $stockHelper, $logger, $session);

        $config = [
            'index' => [
                10 => [77 => 1],
                11 => [77 => 2],
            ],
            'attributes' => [
                77 => [
                    'options' => [
                        [
                            'id' => 1,
                            'label' => 'Red',
                            'products' => [10, 11],
                        ],
                        [
                            'id' => 2,
                            'label' => 'Blue',
                            'products' => [11],
                        ],
                    ],
                ],
            ],
            'salable' => [
                10 => true,
                11 => true,
            ],
        ];

        $method = new \ReflectionMethod($plugin, 'disableRequestableConfigurations');
        $method->setAccessible(true);

        $result = $method->invoke($plugin, $config, [11]);

        $this->assertSame([10], $result['attributes'][77]['options'][0]['products']);
        $this->assertSame([], $result['attributes'][77]['options'][1]['products']);
        $this->assertTrue($result['attributes'][77]['options'][1]['disabled']);
        $this->assertSame([], $result['index'][11]);
        $this->assertFalse($result['salable'][11]);
    }
}
}

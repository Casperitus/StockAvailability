<?php

declare(strict_types=1);

namespace Madar\StockAvailability\Model;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AttributeInterface;
use Psr\Log\LoggerInterface;

class DeliverabilityAttribute
{
    public const ATTRIBUTE_CODE = 'is_deliverable';
    public const LABEL_DELIVERABLE = 'Deliverable';
    public const LABEL_REQUESTABLE = 'Requestable';

    private EavConfig $eavConfig;
    private LoggerInterface $logger;

    /** @var array<string,int|null> */
    private array $optionCache = [];

    public function __construct(EavConfig $eavConfig, LoggerInterface $logger)
    {
        $this->eavConfig = $eavConfig;
        $this->logger = $logger;
    }

    public function apply(ProductInterface $product, bool $isDeliverable): void
    {
        $label = $isDeliverable ? self::LABEL_DELIVERABLE : self::LABEL_REQUESTABLE;
        $optionId = $this->getOptionIdByLabel($label);

        $product->setData(self::ATTRIBUTE_CODE . '_flag', $isDeliverable);
        $product->setData(self::ATTRIBUTE_CODE . '_label', $label);

        if ($optionId !== null) {
            $product->setData(self::ATTRIBUTE_CODE, $optionId);
            $product->setData(self::ATTRIBUTE_CODE . '_option_id', $optionId);

            if ($product instanceof Product) {
                $product->setCustomAttribute(self::ATTRIBUTE_CODE, $optionId);
            }
        } else {
            $product->setData(self::ATTRIBUTE_CODE, $label);
        }

        $sku = method_exists($product, 'getSku') ? (string) $product->getSku() : 'unknown';
        $this->logger->debug(
            sprintf('Applied %s deliverability to product %s (option: %s).', $label, $sku, $optionId ?? 'none'),
            [
                'sku' => $sku,
                'label' => $label,
                'option_id' => $optionId,
                'is_deliverable' => $isDeliverable,
            ]
        );
    }

    public function isDeliverable(ProductInterface $product): ?bool
    {
        $flag = $product->getData(self::ATTRIBUTE_CODE . '_flag');
        if ($flag === null) {
            $raw = $product->getData(self::ATTRIBUTE_CODE . '_option_id');
            if ($raw === null) {
                $raw = $product->getData(self::ATTRIBUTE_CODE);
            }

            if ($raw === null) {
                $rawLabel = $product->getData(self::ATTRIBUTE_CODE . '_label');
                if ($rawLabel === null) {
                    return null;
                }

                if (is_string($rawLabel)) {
                    return strcasecmp($rawLabel, self::LABEL_DELIVERABLE) === 0;
                }

                return null;
            }

            if (is_bool($raw)) {
                return $raw;
            }

            if (is_numeric($raw)) {
                $deliverableId = $this->getOptionIdByLabel(self::LABEL_DELIVERABLE);
                return $deliverableId !== null ? ((int) $raw === $deliverableId) : null;
            }

            if (is_string($raw)) {
                return strcasecmp($raw, self::LABEL_DELIVERABLE) === 0;
            }

            return null;
        }

        return (bool) $flag;
    }

    private function getOptionIdByLabel(string $label): ?int
    {
        $normalizedLabel = strtolower($label);
        if (array_key_exists($normalizedLabel, $this->optionCache)) {
            return $this->optionCache[$normalizedLabel];
        }

        try {
            $attribute = $this->getAttribute();
            if (!$attribute) {
                $this->optionCache[$normalizedLabel] = null;
                return null;
            }

            foreach ($attribute->getOptions() as $option) {
                $optionLabel = $option->getLabel();
                if (!is_string($optionLabel)) {
                    continue;
                }

                if (strcasecmp($optionLabel, $label) === 0) {
                    $value = $option->getValue();
                    $this->optionCache[$normalizedLabel] = is_numeric($value) ? (int) $value : null;
                    return $this->optionCache[$normalizedLabel];
                }
            }
        } catch (\Exception $exception) {
            $this->logger->error(
                sprintf('Unable to fetch option id for %s: %s', $label, $exception->getMessage())
            );
        }

        $this->optionCache[$normalizedLabel] = null;
        return null;
    }

    private function getAttribute(): ?AttributeInterface
    {
        try {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, self::ATTRIBUTE_CODE);
            return $attribute && $attribute->getId() ? $attribute : null;
        } catch (\Exception $exception) {
            $this->logger->error(
                sprintf('Unable to load %s attribute: %s', self::ATTRIBUTE_CODE, $exception->getMessage())
            );
            return null;
        }
    }
}

<?php

namespace Magento\Sales\Api;

use Magento\Sales\Api\Data\OrderInterface;

interface OrderRepositoryInterface
{
    public function save(OrderInterface $order);
}

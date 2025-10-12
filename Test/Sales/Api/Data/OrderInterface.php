<?php

namespace Magento\Sales\Api\Data;

interface OrderInterface
{
    public function setData($key, $value = null);
    public function getShippingAddress();
}

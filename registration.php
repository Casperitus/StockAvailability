<?php
/**
 * Copyright © Wael Omar All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

if (!class_exists(ComponentRegistrar::class)) {
    return;
}

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Madar_StockAvailability', __DIR__);


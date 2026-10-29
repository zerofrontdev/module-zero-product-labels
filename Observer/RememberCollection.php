<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\ProductLabels\Observer;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zero\ProductLabels\Model\Config;
use Zero\ProductLabels\Model\LowStock;

/**
 * catalog_product_collection_load_after: the products of every list are what
 * the "Only a few left" badge will be asked about, one by one; told now, it
 * fetches their quantities together. Nothing when the badge is off.
 */
class RememberCollection implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly LowStock $lowStock
    ) {
    }

    public function execute(Observer $observer): void
    {
        if ($this->config->lowStockQty() <= 0) {
            return;
        }
        $collection = $observer->getEvent()->getData('collection');
        if ($collection instanceof Collection) {
            $this->lowStock->expect($collection->getItems());
        }
    }
}
